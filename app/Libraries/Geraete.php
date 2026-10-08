<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\FreischaltcodeModel;
use App\Models\GeraetModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Tablet-Freischaltung und Geräte-Cookie `gl_geraet` (32 Byte hex; in der DB nur sha256).
 * Das Cookie wird bei jedem Tablet-Request neu gesetzt (gleitende 400 Tage), über den
 * Filter `after`, damit es auch Redirects begleitet.
 */
final class Geraete
{
    public const COOKIE = 'gl_geraet';

    private const TAGE = 400;

    /** @var ?string Token, das dieser Request als Cookie setzen soll */
    private ?string $cookieToken = null;

    /**
     * Code gültig und nicht eingelöst: Code einlösen, Gerät anlegen, Token liefern; sonst null.
     */
    public function freischalten(string $code, string $name): ?string
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{8}$/', $code) !== 1) {
            return null;
        }

        $jetzt  = service('uhr')->jetzt();
        $token  = bin2hex(random_bytes(32));
        $modell = new GeraetModel();
        $ok     = false;

        $modell->transaktion(function () use ($modell, $code, $name, $token, $jetzt, &$ok): void {
            if (! (new FreischaltcodeModel())->loeseEin($code, $jetzt)) {
                return;
            }

            $modell->insert([
                'name'               => $name,
                'token_hash'         => hash('sha256', $token),
                'zuletzt_gesehen_at' => $jetzt->format('Y-m-d H:i:s'),
            ]);
            $ok = true;
        });

        if (! $ok) {
            return null;
        }

        $this->cookieToken = $token;

        return $token;
    }

    /**
     * Nicht gesperrtes Gerät zum Token (aktualisiert `zuletzt_gesehen_at`).
     *
     * @return ?array<string, mixed>
     */
    public function ausCookie(?string $token): ?array
    {
        $geraet = $this->suche($token);

        if ($geraet === null || $geraet['gesperrt_at'] !== null) {
            return null;
        }

        $zeit = service('uhr')->jetzt()->format('Y-m-d H:i:s');
        (new GeraetModel())->update((int) $geraet['id'], ['zuletzt_gesehen_at' => $zeit]);
        $geraet['zuletzt_gesehen_at'] = $zeit;

        return $geraet;
    }

    public function istGesperrtesToken(?string $token): bool
    {
        $geraet = $this->suche($token);

        return $geraet !== null && $geraet['gesperrt_at'] !== null;
    }

    /**
     * Antwort für Nicht-Tablet-Routen: gültiges Gerät → Redirect zu `tablet`, gesperrtes Gerät →
     * 403-Seite, sonst (kein/unbekanntes Cookie) null.
     */
    public function nichtTabletAntwort(?string $token): ?ResponseInterface
    {
        if ($this->ausCookie($token) !== null) {
            return redirect()->to(site_url('tablet'));
        }

        return $this->istGesperrtesToken($token) ? $this->gesperrtAntwort() : null;
    }

    public function gesperrtAntwort(): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setBody(view('tablet/gesperrt'));
    }

    /**
     * Setzt den Request-Zustand zurück und merkt optional das Token zum Setzen des Cookies vor.
     */
    public function zuruecksetzen(?string $cookieToken = null): void
    {
        $this->cookieToken = $cookieToken;
    }

    public function cookieAnwenden(ResponseInterface $antwort): void
    {
        if ($this->cookieToken === null) {
            return;
        }

        $antwort->setCookie([
            'name'     => self::COOKIE,
            'value'    => $this->cookieToken,
            'expire'   => self::TAGE * 86400,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => config('Cookie')->secure,
        ]);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function suche(?string $token): ?array
    {
        if ($token === null || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return (new GeraetModel())->findeNachToken($token);
    }
}
