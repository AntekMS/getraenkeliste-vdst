<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\AuszaehlungAbgelehnt;
use App\Libraries\AuszaehlungExport;
use App\Libraries\AuszaehlungService;
use App\Libraries\BuchungAbgelehnt;
use CodeIgniter\Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AuszaehlungServiceTest extends DbTestCase
{
    private int $bereich;
    private int $person;
    private string $basis;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        $this->resetServices();
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->bereich = $this->bereichId('getraenke');
        $this->person  = $this->personAnlegen();
        $this->basis   = sys_get_temp_dir() . '/gl-abschluss-' . bin2hex(random_bytes(6)) . '/';
        Services::injectMock('auszaehlungExport', new AuszaehlungExport($this->basis));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basis . 'exporte/{,.}*', GLOB_BRACE) ?: [] as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }

        @rmdir($this->basis . 'exporte');
        @rmdir($this->basis);
        parent::tearDown();
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

    // --- Abschluss (Task 9) ---

    /**
     * @return array<string, mixed>
     */
    private function kopfVon(int $id): array
    {
        return db_connect()->table('auszaehlungen')->where('id', $id)->get()->getRowArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionenVon(int $id): array
    {
        return array_column(db_connect()->table('auszaehlung_positionen')->where('auszaehlung_id', $id)->get()->getResultArray(), null, 'artikel_id');
    }

    public function test_abschluss_schliesst_ab_schreibt_positionen_datei_und_protokoll(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $this->buchung($a, 3, '2026-10-05 12:00:00');

        $id = (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:45'), [$a => 6], ' Erste ');

        $kopf = $this->kopfVon($id);
        $this->assertSame('abgeschlossen', $kopf['status']);
        $this->assertSame('start', $kopf['art']);
        $this->assertSame('2026-10-09 10:00:00', $kopf['stichtag']);
        $this->assertSame('2026-10-01 00:00:00', $kopf['zeitraum_von']);
        $this->assertSame('2026-10-10 12:00:00', $kopf['abgeschlossen_at']);
        $this->assertSame('Erste', $kopf['bemerkung']);
        $this->assertSame($this->person, (int) $kopf['erstellt_von_id']);
        $this->assertSame('exporte/Auszaehlung_getraenke_2026-10-01_bis_2026-10-09.xlsx', $kopf['datei_pfad']);
        $this->assertFileExists($this->basis . $kopf['datei_pfad']);

        $p = $this->positionenVon($id)[$a];
        $this->assertSame(7, (int) $p['soll']);
        $this->assertSame(6, (int) $p['ist']);
        $this->assertSame(-1, (int) $p['differenz']);

        $this->seeInDatabase('protokoll', ['person_id' => $this->person, 'aktion' => 'abgeschlossen', 'tabelle' => 'auszaehlungen', 'datensatz_id' => $id]);
        $this->assertEquals($this->zeit('2026-10-09 10:00:00'), service('zeitraeume')->letzterStichtag($this->bereich), 'Cache nach dem Abschluss geleert');
        $this->assertFalse((new AuszaehlungService())->dateiFehlt($id));
    }

    public function test_entwurf_wird_beim_abschluss_zur_abgeschlossenen_auszaehlung_und_soll_neu_berechnet(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $service = new AuszaehlungService();
        $entwurf = $service->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 6], null);
        $this->assertSame(10, (int) $this->positionenVon($entwurf)[$a]['soll']);

        // Zwischen Entwurf und Abschluss vor dem Stichtag gebucht (Review Focus 5); eine Buchung nach dem Stichtag zählt nicht.
        $this->buchung($a, 3, '2026-10-08 12:00:00');
        $this->buchung($a, 2, '2026-10-09 10:00:01');

        $wart = $this->personAnlegen();
        $id   = $service->schliesseAb($this->bereich, $wart, $this->zeit('2026-10-09 10:00:00'), [$a => 6], null);

        $this->assertSame($entwurf, $id);
        $this->assertSame(1, db_connect()->table('auszaehlungen')->countAllResults());
        $p = $this->positionenVon($id)[$a];
        $this->assertSame(3, (int) $p['verkauft']);
        $this->assertSame(7, (int) $p['soll']);
        $this->assertSame(6, (int) $p['ist']);
        $this->assertSame(-1, (int) $p['differenz']);
        $this->assertSame($wart, (int) $this->kopfVon($id)['erstellt_von_id']);
    }

    public function test_abschluss_verlangt_ist_fuer_jeden_artikel(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);

        try {
            (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 1, $b => null], null);
            $this->fail('Erwartet: Ablehnung');
        } catch (AuszaehlungAbgelehnt $e) {
            $this->assertSame('Bitte für jeden Artikel eintragen, wie viel du gezählt hast.', $e->getMessage());
            $this->assertSame(["ist.{$b}" => AuszaehlungService::MELDUNG_IST_LEER], $e->fehler);
        }

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_zweite_auszaehlung_nur_mit_spaeterem_stichtag_und_regulaer(): void
    {
        $a       = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $service = new AuszaehlungService();
        $erste   = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:00'), [$a => 4], null);

        try {
            $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-08 10:00:00'), [$a => 4], null);
            $this->fail('Erwartet: Ablehnung');
        } catch (AuszaehlungAbgelehnt $e) {
            $this->assertSame('Der Stichtag muss nach dem letzten Abschluss liegen.', $e->getMessage());
        }

        $this->assertSame(1, db_connect()->table('auszaehlungen')->where('status', 'abgeschlossen')->countAllResults());
        $this->assertNull((new \App\Models\AuszaehlungModel())->entwurf($this->bereich));

        $zweite = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 3], null);

        $this->assertSame('start', $this->kopfVon($erste)['art']);
        $kopf = $this->kopfVon($zweite);
        $this->assertSame('regulaer', $kopf['art']);
        $this->assertSame('2026-10-08 10:00:00', $kopf['zeitraum_von']);
        $p = $this->positionenVon($zweite)[$a];
        $this->assertSame(4, (int) $p['anfangsbestand']);
        $this->assertSame(0, (int) $p['start']);
    }

    public function test_abschluss_friert_ein_mitglieds_storno_vor_dem_stichtag_wird_abgelehnt(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $v = '7d6a4f0e-3c1b-4a55-9e0d-2b8f6c1a9d42';
        $this->uhrStellen('2026-10-10 11:59:00');
        service('buchungen')->bucheVorgang($v, $this->person, $this->person, null, 'web', [['artikel_id' => $a, 'menge' => 1]]);
        $this->uhrStellen('2026-10-10 12:00:00');

        (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-10 12:00:00'), [$a => 0], null);

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Dieser Zeitraum ist abgeschlossen.');
        service('buchungen')->storniereVorgang($v, $this->person);
    }

    public function test_buchung_im_selben_moment_nach_dem_abschluss_wird_abgelehnt(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-10 12:00:00'), [$a => 0], null);

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Dieser Zeitraum ist abgeschlossen. Nicht gebucht.');
        service('buchungen')->bucheVorgang('7d6a4f0e-3c1b-4a55-9e0d-2b8f6c1a9d43', $this->person, $this->person, null, 'web', [['artikel_id' => $a, 'menge' => 1]]);
    }

    public function test_excel_fehler_laesst_die_auszaehlung_abgeschlossen_und_neu_erzeugen_hilft(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        Services::injectMock('auszaehlungExport', new class ($this->basis) extends AuszaehlungExport {
            public function erzeuge(int $auszaehlungId): string
            {
                throw new RuntimeException('Platte voll');
            }
        });
        $service = new AuszaehlungService();

        $id = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 2], null);

        $kopf = $this->kopfVon($id);
        $this->assertSame('abgeschlossen', $kopf['status']);
        $this->assertNull($kopf['datei_pfad']);
        $this->assertTrue($service->dateiFehlt($id));
        $this->assertEquals($this->zeit('2026-10-09 10:00:00'), service('zeitraeume')->letzterStichtag($this->bereich));

        Services::injectMock('auszaehlungExport', new AuszaehlungExport($this->basis));
        $pfad = $service->dateiNeuErzeugen($id, $this->person);

        $this->assertSame($pfad, $this->kopfVon($id)['datei_pfad']);
        $this->assertFileExists($this->basis . $pfad);
        $this->assertFalse($service->dateiFehlt($id));
        $this->seeInDatabase('protokoll', ['person_id' => $this->person, 'aktion' => 'datei_erzeugt', 'tabelle' => 'auszaehlungen', 'datensatz_id' => $id]);
    }

    public function test_neu_erzeugen_eines_entwurfs_wird_abgelehnt(): void
    {
        $id = (new AuszaehlungService())->speichereEntwurf($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [], null);

        $this->expectException(RuntimeException::class);
        (new AuszaehlungService())->dateiNeuErzeugen($id, $this->person);
    }

    public function test_neu_erzeugen_mit_neuem_namen_loescht_die_alte_datei(): void
    {
        $a       = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $service = new AuszaehlungService();
        $id      = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 0], null);
        $aktuell = (string) $this->kopfVon($id)['datei_pfad'];
        rename($this->basis . $aktuell, $this->basis . 'exporte/alt.xlsx');
        db_connect()->table('auszaehlungen')->where('id', $id)->update(['datei_pfad' => 'exporte/alt.xlsx']);

        $pfad = $service->dateiNeuErzeugen($id, $this->person);

        $this->assertSame($aktuell, $pfad);
        $this->assertFileExists($this->basis . $pfad);
        $this->assertFileDoesNotExist($this->basis . 'exporte/alt.xlsx');
    }

    public function test_neu_erzeugen_mit_gleichem_namen_behaelt_die_datei(): void
    {
        $a       = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $service = new AuszaehlungService();
        $id      = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 0], null);
        $vorher  = (string) $this->kopfVon($id)['datei_pfad'];

        $this->assertSame($vorher, $service->dateiNeuErzeugen($id, $this->person));
        $this->assertFileExists($this->basis . $vorher);
    }

    public function test_start_wenn_artikel_in_der_letzten_auszaehlung_fehlt(): void
    {
        $a     = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b     = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);
        $erste = $this->auszaehlungAnlegen('2026-10-02 00:00:00');
        $zweite = $this->auszaehlungAnlegen('2026-10-03 00:00:00');
        $position = static fn (int $auszaehlung, int $artikel): array => [
            'auszaehlung_id' => $auszaehlung, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 5, 'ist' => 5, 'differenz' => 0, 'start' => 0, 'preis_cent' => 150,
        ];
        db_connect()->table('auszaehlung_positionen')->insertBatch([$position($erste, $a), $position($erste, $b), $position($zweite, $b)]);

        $v = $this->vorschlag('2026-10-08 10:00:00');

        $this->assertTrue($v[$a]['start'], 'nicht in der letzten Auszählung → Anfangsbestand unbekannt');
        $this->assertSame(0, $v[$a]['anfangsbestand']);
        $this->assertFalse($v[$b]['start']);
    }

    public function test_nicht_bestandswirksame_korrektur_zaehlt_nicht_fuers_soll(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $this->buchung($a, 3, '2026-10-04 12:00:00');
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => bin2hex(random_bytes(18)), 'konto_id' => $this->person, 'artikel_id' => $a, 'menge' => -2,
            'einzelpreis_cent' => 150, 'quelle' => 'korrektur', 'gebucht_at' => '2026-10-05 12:00:00', 'bestandswirksam' => 0,
        ]);

        $p = $this->vorschlag('2026-10-08 10:00:00')[$a];

        $this->assertSame(3, $p['verkauft']);
        $this->assertSame(7, $p['soll']);
        $this->assertSame(service('bestand')->einzeln($a), $p['soll']);
    }

    public function test_export_zaehlt_nicht_bestandswirksame_korrektur_im_betrag_aber_nicht_im_bestand(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $this->buchung($a, 3, '2026-10-04 12:00:00');
        $this->uhrStellen('2026-10-05 12:00:00');
        service('buchungen')->bucheKorrektur($this->person, $a, -2, 'Falsch gebucht', $this->person, $this->bereich, false);
        $this->uhrStellen('2026-10-10 12:00:00');

        $id    = (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 7], null);
        $mappe = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->basis . $this->kopfVon($id)['datei_pfad']);
        $daten = static function (string $blatt) use ($mappe): array {
            $zeilen = $mappe->getSheetByName($blatt)->toArray(null, false, false, false);
            $kopf   = array_shift($zeilen);

            return array_map(static fn (array $z): array => array_combine($kopf, $z), $zeilen);
        };

        $abrechnung = array_column($daten('Abrechnung'), null, 'konto_id');
        $this->assertSame(150, (int) round((float) $abrechnung[$this->person]['betrag_eur'] * 100), '3 × 1,50 − 2 × 1,50');
        $bestand = array_column($daten('Bestand'), null, 'artikel_id')[$a];
        $this->assertEquals(3, $bestand['verkauft']);
        $this->assertEquals(7, $bestand['soll']);
        $this->assertEquals(0, $bestand['differenz']);
    }

    public function test_abschluss_bei_gesperrtem_bereich_liefert_deutsche_meldung(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $this->beiGesperrtemBereich($this->bereich, function () use ($a): void {
            try {
                (new AuszaehlungService())->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 1], null);
                $this->fail('Erwartet: Ablehnung');
            } catch (AuszaehlungAbgelehnt $e) {
                $this->assertSame('Gerade wird abgerechnet – bitte gleich erneut versuchen.', $e->getMessage());
            }
        });

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    /**
     * Review Focus 1: Während der Abschluss den Bereich hält, muss jeder andere Schreiber (der zuerst den Bereich sperrt)
     * warten – hier eine zweite Verbindung mit Lock-Wait-Timeout 1 s. Was vor der Sperre committet war, zählt im Soll.
     */
    public function test_abschluss_haelt_die_bereichssperre_und_zaehlt_vorher_committete_buchungen(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->bewegung($a, 'lieferung', 10, '2026-10-02 00:00:00');
        $this->buchung($a, 4, '2026-10-09 09:59:59');

        $service = new class () extends AuszaehlungService {
            public ?\Throwable $fehler = null;

            protected function nachDerSperre(int $bereichId): void
            {
                $zweite = \Config\Database::connect('tests', false);

                try {
                    $zweite->query('SET SESSION innodb_lock_wait_timeout = 1');
                    $zweite->query('SELECT id FROM bereiche WHERE id = ? FOR UPDATE', [$bereichId]);
                } catch (\Throwable $e) {
                    $this->fehler = $e;
                } finally {
                    $zweite->close();
                }
            }
        };

        $id = $service->schliesseAb($this->bereich, $this->person, $this->zeit('2026-10-09 10:00:00'), [$a => 6], null);

        $this->assertNotNull($service->fehler, 'Die zweite Verbindung hätte warten müssen');
        $this->assertStringContainsString('Lock wait timeout', $service->fehler->getMessage());
        $this->assertSame(6, (int) $this->positionenVon($id)[$a]['soll']);
    }
}
