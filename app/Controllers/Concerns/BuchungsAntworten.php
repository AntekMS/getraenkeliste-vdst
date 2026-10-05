<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Libraries\BuchungAbgelehnt;
use App\Libraries\BuchungService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Gemeinsamer Teil der JSON-Endpunkte „buchen“ und „rückgängig“ (eigenes Gerät und Tablet):
 * strenge Positionsprüfung, Antwortformat (inkl. Storno-Wiederholung, nächster Vorgangs-ID, CSRF-Hash).
 * Nutzt `$this->request` und `$this->response` des Controllers.
 */
trait BuchungsAntworten
{
    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        try {
            $body = $this->request->getJSON(true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Bucht den Warenkorb aus dem JSON-Body auf das Konto und formt die Antwort.
     *
     * @param array<string, mixed>  $body
     * @param ?callable(string):void $nachErfolg bekommt die vorgang_id einer neu gebuchten (nicht stornierten) Buchung
     */
    protected function bucheAusBody(array $body, int $kontoId, ?int $gebuchtVonId, ?int $geraetId, string $quelle, ?callable $nachErfolg = null): ResponseInterface
    {
        $vorgangId  = $body['vorgang_id'] ?? null;
        $positionen = $this->positionen($body['positionen'] ?? null);

        if (! is_string($vorgangId)) {
            return $this->fehler('Ungültige Anfrage. Nicht gebucht.');
        }

        if ($positionen === null) {
            return $this->fehler('Ungültige Position. Nicht gebucht.');
        }

        try {
            $ergebnis = (new BuchungService())->bucheVorgang($vorgangId, $kontoId, $gebuchtVonId, $geraetId, $quelle, $positionen);
        } catch (BuchungAbgelehnt $e) {
            return $this->fehler($e->getMessage());
        }

        if ($ergebnis['storniert']) {
            return $this->antwort(['ok' => true, 'storniert' => true, 'meldung' => 'Dieser Vorgang wurde bereits rückgängig gemacht.']);
        }

        if ($nachErfolg !== null) {
            $nachErfolg($ergebnis['vorgang_id']);
        }

        return $this->antwort([
            'ok'              => true,
            'zusammenfassung' => $ergebnis['zusammenfassung'],
            'summe_cent'      => $ergebnis['summe_cent'],
            'vorgang_id'      => $ergebnis['vorgang_id'],
        ]);
    }

    protected function storniereAntwort(string $vorgangId, ?int $stornoVonId): ResponseInterface
    {
        try {
            (new BuchungService())->storniereVorgang($vorgangId, $stornoVonId);
        } catch (BuchungAbgelehnt $e) {
            return $this->fehler($e->getMessage());
        }

        return $this->antwort(['ok' => true, 'meldung' => 'Die Buchung wurde rückgängig gemacht.']);
    }

    /**
     * Streng: nur Ganzzahlen oder reine Ziffernstrings; alles andere macht den ganzen Warenkorb ungültig.
     *
     * @return ?list<array{artikel_id: int, menge: int}>
     */
    protected function positionen(mixed $roh): ?array
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

    protected function ganzzahl(mixed $wert): ?int
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
    protected function antwort(array $daten, int $status = 200): ResponseInterface
    {
        // Jede Antwort bringt die nächste Vorgangs-ID mit; der Client übernimmt sie nach Erfolg.
        if ($status === 200 && ($daten['ok'] ?? false) === true) {
            $daten['naechste_vorgang_id'] = BuchungService::neueVorgangId();
        }

        $daten['csrf_hash'] = csrf_hash();

        return $this->response->setStatusCode($status)->setJSON($daten);
    }

    protected function fehler(string $meldung, int $status = 422): ResponseInterface
    {
        return $this->antwort(['ok' => false, 'meldung' => $meldung], $status);
    }
}
