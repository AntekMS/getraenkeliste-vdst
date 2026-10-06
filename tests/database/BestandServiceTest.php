<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\BestandService;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class BestandServiceTest extends DbTestCase
{
    private int $bereich;
    private int $person;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        $this->resetServices(); // Einstellungs-Cache verwerfen
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->bereich = $this->bereichId('getraenke');
        $this->person  = $this->personAnlegen();
    }

    private function bewegung(int $artikel, int $menge, string $zeit): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => $menge > 0 ? 'lieferung' : 'schwund', 'menge' => $menge,
            'person_id' => $this->person, 'erfolgt_at' => $zeit,
        ]);
    }

    private function buchung(int $artikel, int $menge, string $zeit, bool $storniert = false, string $quelle = 'web'): void
    {
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => bin2hex(random_bytes(18)), 'konto_id' => $this->person, 'artikel_id' => $artikel,
            'menge' => $menge, 'einzelpreis_cent' => 150, 'quelle' => $quelle, 'gebucht_at' => $zeit,
            'storniert_at' => $storniert ? '2026-10-09 00:00:00' : null,
        ]);
    }

    private function bestand(int $artikel): int
    {
        service('zeitraeume')->vergiss();

        return (new BestandService())->einzeln($artikel);
    }

    public function test_ohne_auszaehlung_bewegungen_minus_verkaeufe(): void
    {
        $a = $this->artikelAnlegen();
        $this->bewegung($a, 24, '2026-10-02 10:00:00');
        $this->buchung($a, 5, '2026-10-03 10:00:00');

        $this->assertSame(19, $this->bestand($a));
    }

    public function test_nach_auszaehlung_ist_plus_spaetere(): void
    {
        $a  = $this->artikelAnlegen();
        $id = $this->auszaehlungAnlegen('2026-10-05 12:00:00');
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $id, 'artikel_id' => $a, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => 10, 'differenz' => 0, 'start' => 0, 'preis_cent' => 150,
        ]);
        $this->bewegung($a, 100, '2026-10-05 12:00:00'); // Stichtag selbst: eingefroren, zählt nicht
        $this->bewegung($a, 12, '2026-10-06 08:00:00');
        $this->buchung($a, 4, '2026-10-05 12:00:00');
        $this->buchung($a, 3, '2026-10-07 08:00:00');

        $this->assertSame(10 + 12 - 3, $this->bestand($a));
    }

    public function test_artikel_ohne_position_startet_bei_null_nach_auszaehlung(): void
    {
        $a = $this->artikelAnlegen();
        $this->auszaehlungAnlegen('2026-10-05 12:00:00');
        $this->bewegung($a, 7, '2026-10-06 08:00:00');

        $this->assertSame(7, $this->bestand($a));
    }

    public function test_stornierte_buchungen_zaehlen_nicht(): void
    {
        $a = $this->artikelAnlegen();
        $this->bewegung($a, 10, '2026-10-02 10:00:00');
        $this->buchung($a, 4, '2026-10-03 10:00:00', true);

        $this->assertSame(10, $this->bestand($a));
    }

    public function test_korrekturbuchung_minus_zwei_erhoeht_bestand(): void
    {
        $a = $this->artikelAnlegen();
        $this->bewegung($a, 10, '2026-10-02 10:00:00');
        $this->buchung($a, -2, '2026-10-03 10:00:00', false, 'korrektur');

        $this->assertSame(12, $this->bestand($a));
    }

    public function test_lieferung_bei_gesperrtem_bereich_wird_deutsche_meldung(): void
    {
        $a = $this->artikelAnlegen();

        $this->beiGesperrtemBereich($this->bereich, function () use ($a): void {
            try {
                (new BestandService())->liefere($this->bereich, $this->person, [['artikel_id' => $a, 'kisten' => 0, 'stueck' => 5, 'einkaufspreis' => null]], null);
                $this->fail('Ablehnung erwartet');
            } catch (\App\Libraries\BewegungAbgelehnt $e) {
                $this->assertSame('Gerade wird abgerechnet – bitte gleich erneut versuchen.', $e->getMessage());
            }
        });

        $this->seeNumRecords(0, 'bestandsbewegungen', []);
    }

    public function test_lieferung_zu_grosse_menge_ist_feldfehler(): void
    {
        $a = $this->artikelAnlegen(['gebinde_groesse' => 1000]);

        try {
            (new BestandService())->liefere($this->bereich, $this->person, [['artikel_id' => $a, 'kisten' => 1001, 'stueck' => 0, 'einkaufspreis' => null]], null);
            $this->fail('Ablehnung erwartet');
        } catch (\App\Libraries\BewegungAbgelehnt $e) {
            $this->assertSame('Menge zu groß.', $e->fehler['zeilen.0.kisten']);
        }

        $this->seeNumRecords(0, 'bestandsbewegungen', []);

        (new BestandService())->liefere($this->bereich, $this->person, [['artikel_id' => $a, 'kisten' => 1000, 'stueck' => 0, 'einkaufspreis' => null]], null);
        $this->seeInDatabase('bestandsbewegungen', ['artikel_id' => $a, 'menge' => 1000000]);
    }

    public function test_fuer_bereich_ampel_und_filter(): void
    {
        $leer    = $this->artikelAnlegen(['name' => 'Leer', 'mindestbestand' => 5]);
        $niedrig = $this->artikelAnlegen(['name' => 'Niedrig', 'mindestbestand' => 5]);
        $ok      = $this->artikelAnlegen(['name' => 'Ok', 'mindestbestand' => 5]);
        $neg     = $this->artikelAnlegen(['name' => 'Neg', 'mindestbestand' => 5]);
        $this->artikelAnlegen(['name' => 'Ohne', 'bestand_fuehren' => 0]);
        $this->artikelAnlegen(['name' => 'Archiv', 'archiviert_at' => '2026-10-02 00:00:00']);
        $this->bewegung($niedrig, 3, '2026-10-02 10:00:00');
        $this->bewegung($ok, 9, '2026-10-02 10:00:00');
        $this->buchung($neg, 1, '2026-10-03 10:00:00');

        service('zeitraeume')->vergiss();
        $gruppen = (new BestandService())->fuerBereich($this->bereich);

        $this->assertCount(1, $gruppen);
        $ampeln = array_column($gruppen[0]['artikel'], 'ampel', 'name');
        $this->assertSame(['Leer' => 'leer', 'Niedrig' => 'niedrig', 'Ok' => 'ok', 'Neg' => 'negativ'], $ampeln);
        $this->assertSame($leer, $gruppen[0]['artikel'][0]['artikel_id']);
    }
}
