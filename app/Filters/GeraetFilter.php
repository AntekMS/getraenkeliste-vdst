<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Geraete;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `tablet`: nur mit gültigem Geräte-Cookie. Ohne Gerät Redirect zur Freischaltung, gesperrtes
 * Gerät bekommt eine 403-Seite. Argument `frei` (Freischaltungsseite selbst): kein Zwang, aber ein
 * bereits freigeschaltetes Tablet wird zu `tablet` geleitet. `after` setzt das Cookie neu (gleitend).
 */
class GeraetFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $geraete = service('geraete');
        $token   = $request->getCookie(Geraete::COOKIE);
        $frei    = in_array('frei', (array) $arguments, true);
        $geraete->zuruecksetzen();

        if ($geraete->ausCookie($token) !== null) {
            $geraete->zuruecksetzen($token);

            if (! $frei) {
                return null;
            }

            // Eine Antwort aus `before` überspringt `after`: Cookie hier direkt anwenden.
            $antwort = redirect()->to(site_url('tablet'));
            $geraete->cookieAnwenden($antwort);

            return $antwort;
        }

        if ($frei) {
            return null;
        }

        if ($geraete->istGesperrtesToken($token)) {
            return $geraete->gesperrtAntwort();
        }

        return redirect()->to(site_url('tablet/freischalten'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        service('geraete')->cookieAnwenden($response);
        // Freischalten beendet den persönlichen Login: gl_merken-Löschung muss die Antwort erreichen.
        service('anmeldung')->merkCookieAnwenden($response);

        return null;
    }
}
