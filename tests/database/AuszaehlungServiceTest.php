<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\AuszaehlungAbgelehnt;
use App\Libraries\AuszaehlungService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AuszaehlungServiceTest extends DbTestCase
{
    private int $bereich;
    private int $person;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        $this->resetServices();
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->bereich = $this->bereichId('getraenke');
        $this->person  = $this->personAnlegen();
    }

    private function zeit(string $z): DateTimeImmutable
    {
        return new DateTimeImmutable($z, new DateTimeZone('Europe/Berlin'));
    }

    private function bewegung(int $artikel, string $art, int $menge, string $zeit): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => $art, 'menge' => $menge, 'person_id' => $this->person, 'erfolgt_at' => $zeit,
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

    private function vorschlag(string $stichtag): array
    {
        service('zeitraeume')->vergiss();

        $zeilen = (new AuszaehlungService())->vorschlag($this->bereich, $this->zeit($stichtag));

        return array_column($zeilen, null, 'artikel_id');
    }

    public function test_vorschlag_berechnet_soll_aus_allen_bestandteilen_bis_zum_stichtag(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $alt = $this->auszaehlungAnlegen('2026-10-03 00:00:00');
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $alt, 'artikel_id' => $a, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 10, 'ist' => 10, 'differenz' => 0, 'start' => 1, 'preis_cent' => 150,
        ]);

        $this->bewegung($a, 'lieferung', 24, '2026-10-04 10:00:00');
        $this->bewegung($a, 'schwund', -2, '2026-10-05 10:00:00');
        $this->bewegung($a, 'korrektur', 3, '2026-10-06 10:00:00');
        $this->bewegung($a, 'lieferung', 100, '2026-10-08 10:00:01'); // nach dem Stichtag
        $this->bewegung($a, 'lieferung', 500, '2026-10-03 00:00:00'); // Beginn ist exklusiv
        $this->buchung($a, 5, '2026-10-04 12:00:00');
        $this->buchung($a, 1, '2026-10-07 12:00:00', false, 'korrektur');
        $this->buchung($a, 7, '2026-10-05 12:00:00', true); // storniert
        $this->buchung($a, 9, '2026-10-08 10:00:01');       // nach dem Stichtag

        $p = $this->vorschlag('2026-10-08 10:00:00')[$a];

        $this->assertSame(10, $p['anfangsbestand']);
        $this->assertSame(24, $p['lieferungen']);
        $this->assertSame(-2, $p['schwund_erfasst']);
        $this->assertSame(3, $p['korrekturen']);
        $this->assertSame(6, $p['verkauft']);
        $this->assertSame(10 + 24 - 2 + 3 - 6, $p['soll']);
        $this->assertNull($p['ist']);
        $this->assertFalse($p['start']);
        $this->assertSame(150, $p['preis_cent']);
    }

    public function test_ohne_abschluss_zaehlt_die_inbetriebnahme_inklusiv(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-01 00:00:00');

        $this->assertSame(10, $this->vorschlag('2026-10-08 10:00:00')[$a]['soll']);
    }

    public function test_neuer_artikel_ist_start_und_archivierter_ohne_aktivitaet_fehlt(): void
    {
        $neu      = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $ohne     = $this->artikelAnlegen(['name' => 'Ohne', 'bestand_fuehren' => 1, 'archiviert_at' => '2026-10-02 00:00:00']);
        $mit      = $this->artikelAnlegen(['name' => 'Mit', 'bestand_fuehren' => 1, 'archiviert_at' => '2026-10-02 00:00:00']);
        $keinBest = $this->artikelAnlegen(['name' => 'Kein Bestand', 'bestand_fuehren' => 0]);
        $this->bewegung($mit, 'lieferung', 4, '2026-10-02 00:00:00');

        $v = $this->vorschlag('2026-10-08 10:00:00');

        $this->assertTrue($v[$neu]['start']);
        $this->assertArrayNotHasKey($ohne, $v);
        $this->assertArrayNotHasKey($keinBest, $v);
        $this->assertSame(4, $v[$mit]['soll']);
    }

    public function test_zweiter_entwurf_aktualisiert_den_ersten(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $service = new AuszaehlungService();

        $erste  = $service->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:30'), [$a => 7, $b => null], 'erst');
        $zweite = $service->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 9], null);

        $this->assertSame($erste, $zweite);
        $this->assertSame(1, db_connect()->table('auszaehlungen')->countAllResults());
        $kopf = db_connect()->table('auszaehlungen')->get()->getRowArray();
        $this->assertSame('entwurf', $kopf['status']);
        $this->assertSame('start', $kopf['art']);
        $this->assertSame('2026-10-09 10:00:00', $kopf['stichtag']);
        $this->assertSame('2026-10-01 00:00:00', $kopf['zeitraum_von']);
        $this->assertNull($kopf['bemerkung']);

        $pos = array_column(db_connect()->table('auszaehlung_positionen')->get()->getResultArray(), null, 'artikel_id');
        $this->assertCount(2, $pos);
        $this->assertSame(10, (int) $pos[$a]['soll']);
        $this->assertSame(9, (int) $pos[$a]['ist']);
        $this->assertSame(-1, (int) $pos[$a]['differenz']);
        $this->assertNull($pos[$b]['ist']);
        $this->assertSame(0, (int) $pos[$b]['soll']);
        $this->assertSame(1, (int) $pos[$a]['start']);
    }

    public function test_entwurf_nach_abschluss_ist_regulaer_mit_zeitraum_ab_stichtag(): void
    {
        $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->auszaehlungAnlegen('2026-10-05 00:00:00');

        $id = (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:00'), [], null);

        $kopf = db_connect()->table('auszaehlungen')->where('id', $id)->get()->getRowArray();
        $this->assertSame('regulaer', $kopf['art']);
        $this->assertSame('2026-10-05 00:00:00', $kopf['zeitraum_von']);
    }

    public function test_stichtag_in_der_zukunft_wird_abgelehnt(): void
    {
        try {
            (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-10 12:01:00'), [], null);
            $this->fail('Erwartet: Ablehnung');
        } catch (AuszaehlungAbgelehnt $e) {
            $this->assertArrayHasKey('stichtag', $e->fehler);
            $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
        }
    }

    public function test_stichtag_vor_dem_letzten_abschluss_wird_abgelehnt(): void
    {
        $this->auszaehlungAnlegen('2026-10-05 00:00:00');

        $this->expectException(AuszaehlungAbgelehnt::class);
        $this->expectExceptionMessage('Der Stichtag muss nach dem letzten Abschluss liegen.');
        (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-05 00:00:00'), [], null);
    }

    public function test_negatives_ist_wird_abgelehnt_und_nichts_gespeichert(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        try {
            (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:00'), [$a => -1], null);
            $this->fail('Erwartet: Ablehnung');
        } catch (AuszaehlungAbgelehnt $e) {
            $this->assertSame(AuszaehlungService::MELDUNG_IST, $e->fehler["ist.{$a}"]);
            $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
        }
    }

    public function test_gesperrter_bereich_liefert_deutsche_meldung(): void
    {
        $this->beiGesperrtemBereich($this->bereich, function (): void {
            $this->expectException(AuszaehlungAbgelehnt::class);
            $this->expectExceptionMessage('Gerade wird abgerechnet – bitte gleich erneut versuchen.');
            (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:00'), [], null);
        });
    }

    public function test_soll_stimmt_mit_dem_bestand_der_bestandsseite_ueberein(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);
        $alt = $this->auszaehlungAnlegen('2026-10-03 00:00:00');

        foreach ([[$a, 10], [$b, 4]] as [$artikel, $ist]) {
            db_connect()->table('auszaehlung_positionen')->insert([
                'auszaehlung_id' => $alt, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
                'korrekturen' => 0, 'verkauft' => 0, 'soll' => $ist, 'ist' => $ist, 'differenz' => 0, 'start' => 0, 'preis_cent' => 150,
            ]);
        }

        $this->bewegung($a, 'lieferung', 24, '2026-10-04 10:00:00');
        $this->bewegung($a, 'schwund', -2, '2026-10-05 10:00:00');
        $this->bewegung($a, 'korrektur', -3, '2026-10-06 10:00:00');
        $this->bewegung($b, 'lieferung', 6, '2026-10-06 10:00:00');
        $this->buchung($a, 5, '2026-10-04 12:00:00');
        $this->buchung($a, 7, '2026-10-05 12:00:00', true);
        $this->buchung($a, -2, '2026-10-07 12:00:00', false, 'korrektur');
        $this->buchung($b, 1, '2026-10-08 12:00:00');

        $v = $this->vorschlag('2026-10-10 12:00:00');

        foreach ([$a, $b] as $artikel) {
            $this->assertSame(service('bestand')->einzeln($artikel), $v[$artikel]['soll'], "Artikel {$artikel}");
        }
    }
}
