<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Controllers\Concerns\WartEingaben;
use App\Libraries\BewegungAbgelehnt;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Lieferung, Schwund und Korrektur des Warts. Der Bereich kommt aus der Route (Recht über den Filter);
 * unbekannte oder inaktive Bereiche sind 404. Mengen werden hier nur syntaktisch gelesen, die Fachregeln
 * (Bereich, Gebinde, Einfrieren) prüft BestandService in einer Transaktion.
 */
class BewegungenController extends BaseController
{
    use WartEingaben;

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

            $artikelId  = $this->text($z['artikel_id'] ?? '');
            $zeilen[$i] = [
                'artikel_id'    => ctype_digit($artikelId) ? (int) $artikelId : 0,
                'kisten'        => $kisten ?? 0,
                'stueck'        => $stueck ?? 0,
                'einkaufspreis' => isset($z['einkaufspreis']) ? $this->text($z['einkaufspreis']) : null,
            ];
        }

        if ($fehler !== []) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')->with('fehler', $fehler);
        }

        try {
            $anzahl = service('bestand')->liefere((int) $bereich['id'], $this->personId(), $zeilen, $this->text($this->request->getPost('bemerkung')));
        } catch (BewegungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage())->with('fehler', $e->fehler);
        }

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/einkauf'))
            ->with('success', $anzahl === 1 ? 'Lieferung erfasst (1 Position).' : "Lieferung erfasst ({$anzahl} Positionen).");
    }

    public function bewegungForm(string $bereichSchluessel): string
    {
        $bereich = $this->bereich($bereichSchluessel);
        $art     = $this->text($this->request->getGet('art'));

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
        $art     = $this->text($this->request->getPost('art'));
        $menge   = trim($this->text($this->request->getPost('menge')));

        if (! isset(self::BEWEGUNGEN[$art])) {
            return $zurueck->with('error', 'Bitte Schwund oder Korrektur wählen.');
        }

        if (preg_match('/^-?\d{1,6}$/', $menge) !== 1) {
            return $zurueck->with('error', 'Bitte die markierten Felder prüfen. Nichts gespeichert.')
                ->with('fehler', ['menge' => self::MELDUNG_ZAHL]);
        }

        $artikelId = $this->text($this->request->getPost('artikel_id'));

        try {
            service('bestand')->bucheBewegung(
                (int) $bereich['id'],
                $this->personId(),
                ctype_digit($artikelId) ? (int) $artikelId : 0,
                $art,
                (int) $menge,
                $this->text($this->request->getPost('bemerkung')),
            );
        } catch (BewegungAbgelehnt $e) {
            return $zurueck->with('error', $e->getMessage())->with('fehler', $e->fehler);
        }

        return redirect()->to(site_url('wart/' . $bereich['schluessel'] . '/einkauf'))->with('success', self::BEWEGUNGEN[$art] . ' erfasst.');
    }

    /**
     * Leer = 0; sonst nur Ziffern (höchstens 6 Stellen), sonst null (auch für Array-Werte).
     */
    private function zahl(mixed $wert): ?int
    {
        if (is_array($wert)) {
            return null;
        }

        $wert = trim($this->text($wert));

        if ($wert === '') {
            return 0;
        }

        return preg_match('/^\d{1,6}$/', $wert) === 1 ? (int) $wert : null;
    }

    private function personId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }
}
