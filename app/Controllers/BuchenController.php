<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\BuchungAbgelehnt;
use App\Libraries\BuchungService;
use App\Models\ArtikelModel;
use App\Models\BuchungModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Buchen am eigenen Gerät: Seite plus zwei JSON-Endpunkte (buchen, rückgängig).
 * Der Zugriff (angemeldet, Recht `buchen`) wird über Routenfilter gesichert.
 */
class BuchenController extends BaseController
{
    private const SAMMELKONTEN = ['couleur' => 'Couleur', 'bund' => 'Bund'];

    public function index(): string
    {
        return view('buchen/index', [
            'bereiche'  => (new ArtikelModel())->buchbar(),
            'vorgangId' => BuchungService::neueVorgangId(),
        ]);
    }

    public function buchen(): ResponseInterface
    {
        $body = $this->jsonBody();
        $ich  = (int) service('anmeldung')->person()['id'];

        $vorgangId = $body['vorgang_id'] ?? null;
        $konto     = $body['konto'] ?? null;

        if (! is_string($vorgangId) || ! is_string($konto)) {
            return $this->fehler('Ungültige Anfrage. Nicht gebucht.');
        }

        if ($konto === 'ich') {
            $kontoId = $ich;
        } elseif (isset(self::SAMMELKONTEN[$konto])) {
            $kontoId = (new PersonModel())->sammelkontoId(self::SAMMELKONTEN[$konto]);
        } else {
            return $this->fehler('Ungültiges Konto. Nicht gebucht.');
        }

        $positionen = $this->positionen($body['positionen'] ?? null);

        if ($positionen === null) {
            return $this->fehler('Ungültige Position. Nicht gebucht.');
        }

        try {
            $ergebnis = (new BuchungService())->bucheVorgang($vorgangId, $kontoId, $ich, null, 'web', $positionen);
        } catch (BuchungAbgelehnt $e) {
            return $this->fehler($e->getMessage());
        }

        if ($ergebnis['storniert']) {
            return $this->antwort(['ok' => true, 'storniert' => true, 'meldung' => 'Dieser Vorgang wurde bereits rückgängig gemacht.']);
        }

        return $this->antwort([
            'ok'              => true,
            'zusammenfassung' => $ergebnis['zusammenfassung'],
            'summe_cent'      => $ergebnis['summe_cent'],
            'vorgang_id'      => $ergebnis['vorgang_id'],
        ]);
    }

    public function rueckgaengig(): ResponseInterface
    {
        $vorgangId = $this->jsonBody()['vorgang_id'] ?? null;
        $ich       = (int) service('anmeldung')->person()['id'];

        if (! is_string($vorgangId)) {
            return $this->fehler('Ungültiger Vorgang.');
        }

        $zeile = (new BuchungModel())->where('vorgang_id', $vorgangId)->first();

        // Unbekannte und fremde Vorgänge sehen gleich aus: nichts über fremde IDs verraten.
        if ($zeile === null || ((int) $zeile['konto_id'] !== $ich && (int) ($zeile['gebucht_von_id'] ?? 0) !== $ich)) {
            return $this->fehler('Dieser Vorgang gehört nicht zu dir.', 403);
        }

        try {
            (new BuchungService())->storniereVorgang($vorgangId, $ich);
        } catch (BuchungAbgelehnt $e) {
            return $this->fehler($e->getMessage());
        }

        return $this->antwort(['ok' => true, 'meldung' => 'Die Buchung wurde rückgängig gemacht.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        try {
            $body = $this->request->getJSON(true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Streng: nur Ganzzahlen oder reine Ziffernstrings; alles andere macht den ganzen Warenkorb ungültig.
     *
     * @return ?list<array{artikel_id: int, menge: int}>
     */
    private function positionen(mixed $roh): ?array
    {
        if (! is_array($roh) || ! array_is_list($roh)) {
            return null;
        }

        $positionen = [];

        foreach ($roh as $p) {
            $artikelId = is_array($p) ? $this->ganzzahl($p['artikel_id'] ?? null) : null;
            $menge     = is_array($p) ? $this->ganzzahl($p['menge'] ?? null) : null;

            if ($artikelId === null || $menge === null) {
                return null;
            }

            $positionen[] = ['artikel_id' => $artikelId, 'menge' => $menge];
        }

        return $positionen;
    }

    private function ganzzahl(mixed $wert): ?int
    {
        if (is_int($wert)) {
            return $wert;
        }

        if (is_string($wert) && preg_match('/^\d{1,9}$/', $wert) === 1) {
            return (int) $wert;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function antwort(array $daten, int $status = 200): ResponseInterface
    {
        // Jede Antwort bringt die nächste Vorgangs-ID mit; der Client übernimmt sie nach Erfolg.
        if ($status === 200 && ($daten['ok'] ?? false) === true) {
            $daten['naechste_vorgang_id'] = BuchungService::neueVorgangId();
        }

        $daten['csrf_hash'] = csrf_hash();

        return $this->response->setStatusCode($status)->setJSON($daten);
    }

    private function fehler(string $meldung, int $status = 422): ResponseInterface
    {
        return $this->antwort(['ok' => false, 'meldung' => $meldung], $status);
    }
}
