<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Libraries\BewegungAbgelehnt;
use App\Models\BereichModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Lieferung, Schwund und Korrektur des Warts. Der Bereich kommt aus der Route (Recht über den Filter);
 * unbekannte oder inaktive Bereiche sind 404. Mengen werden hier nur syntaktisch gelesen, die Fachregeln
 * (Bereich, Gebinde, Einfrieren) prüft BestandService in einer Transaktion.
 */
class BewegungenController extends BaseController
{
    private const MAX_ZEILEN  = 50;
    private const BEWEGUNGEN  = ['schwund' => 'Schwund', 'korrektur' => 'Korrektur'];
    private const MELDUNG_ZAHL = 'Bitte eine ganze Zahl angeben.';

    public function lieferungForm(string $bereichSchluessel): string
    {
        $bereich = $this->bereich($bereichSchluessel);

        return view('wart/lieferung', ['bereich' => $bereich, 'gruppen' => service('bestand')->fuerBereich((int) $bereich['id'])]);
    }

    public function lieferung(string $bereichSchluessel): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);
        $zurueck = redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/lieferung'))->withInput();
        $roh     = $this->request->getPost('zeilen');
        $roh     = is_array($roh) ? array_slice($roh, 0, self::MAX_ZEILEN, true) : [];
        $zeilen  = [];
        $fehler  = [];

        foreach ($roh as $i => $z) {
            $z = is_array($z) ? $z : [];
            $kisten = $this->zahl($z['kisten'] ?? '');
            $stueck = $this->zahl($z['stueck'] ?? '');

            if ($kisten === null) {
                $fehler["zeilen.{$i}.kisten"] = self::MELDUNG_ZAHL;
            }

            if ($stueck === null) {
                $fehler["zeilen.{$i}.stueck"] = self::MELDUNG_ZAHL;
            }

            $zeilen[$i] = [
                'artikel_id'    => ctype_digit((string) ($z['artikel_id'] ?? '')) ? (int) $z['artikel_id'] : 0,
                'kisten'        => $kisten ?? 0,
                'stueck'        => $stueck ?? 0,
                'einkaufspreis' => isset($z['einkaufspreis']) ? (string) $z['einkaufspreis'] : null,
            ];
        }

        if ($fehler !== []) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')->with('fehler', $fehler);
        }

        try {
            $anzahl = service('bestand')->liefere((int) $bereich['id'], $this->personId(), $zeilen, (string) $this->request->getPost('bemerkung'));
        } catch (BewegungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage())->with('fehler', $e->fehler);
        }

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/bestand'))
            ->with('success', $anzahl === 1 ? 'Lieferung erfasst (1 Position).' : "Lieferung erfasst ({$anzahl} Positionen).");
    }

    public function bewegungForm(string $bereichSchluessel): string
    {
        $bereich = $this->bereich($bereichSchluessel);
        $art     = (string) $this->request->getGet('art');

        return view('wart/bewegung', [
            'bereich'  => $bereich,
            'gruppen'  => service('bestand')->fuerBereich((int) $bereich['id']),
            'arten'    => self::BEWEGUNGEN,
            'vorgabe'  => isset(self::BEWEGUNGEN[$art]) ? $art : 'schwund',
        ]);
    }

    public function bewegung(string $bereichSchluessel): RedirectResponse
    {
        $bereich = $this->bereich($bereichSchluessel);
        $zurueck = redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/bewegung'))->withInput();
        $art     = (string) $this->request->getPost('art');
        $menge   = trim((string) $this->request->getPost('menge'));

        if (! isset(self::BEWEGUNGEN[$art])) {
            return $zurueck->with('error', 'Bitte Schwund oder Korrektur wählen.');
        }

        if (preg_match('/^-?\d{1,6}$/', $menge) !== 1) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')
                ->with('fehler', ['menge' => self::MELDUNG_ZAHL]);
        }

        $artikelId = (string) $this->request->getPost('artikel_id');

        try {
            service('bestand')->bucheBewegung(
                (int) $bereich['id'],
                $this->personId(),
                ctype_digit($artikelId) ? (int) $artikelId : 0,
                $art,
                (int) $menge,
                (string) $this->request->getPost('bemerkung'),
            );
        } catch (BewegungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage())->with('fehler', $e->fehler);
        }

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/bestand'))->with('success', self::BEWEGUNGEN[$art] . ' erfasst.');
    }

    /**
     * Leer = 0; sonst nur Ziffern (höchstens 6 Stellen), sonst null.
     */
    private function zahl(mixed $wert): ?int
    {
        $wert = trim((string) $wert);

        if ($wert === '') {
            return 0;
        }

        return preg_match('/^\d{1,6}$/', $wert) === 1 ? (int) $wert : null;
    }

    private function personId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function bereich(string $schluessel): array
    {
        $bereich = (new BereichModel())->where('schluessel', $schluessel)->where('aktiv', 1)->first();

        if ($bereich === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $bereich;
    }
}
