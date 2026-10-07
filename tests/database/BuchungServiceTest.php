<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\BuchungAbgelehnt;
use App\Libraries\BuchungService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use App\Models\ArtikelModel;
use Tests\Support\DbTestCase;

final class BuchungServiceTest extends DbTestCase
{
    private const V1 = '7d6a4f0e-3c1b-4a55-9e0d-2b8f6c1a9d01';
    private const V2 = '7d6a4f0e-3c1b-4a55-9e0d-2b8f6c1a9d02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen('2026-10-05 12:00:00');
    }

    public function test_bucht_vorgang_mit_aktuellem_preis(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $spezi  = $this->artikelAnlegen(['name' => 'Spezi', 'preis_cent' => 200]);

        $r = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [
            ['artikel_id' => $helles, 'menge' => 3],
            ['artikel_id' => $spezi, 'menge' => 1],
        ]);

        $this->assertFalse($r['wiederholt']);
        $this->assertSame(650, $r['summe_cent']);
        $this->assertSame('3× Helles, 1× Spezi – 6,50 €', $r['zusammenfassung']);
        $this->assertSame('2026-10-05 12:00:00', $r['gebucht_at']);
        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'artikel_id' => $helles, 'menge' => 3, 'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_at' => '2026-10-05 12:00:00']);
        $this->seeNumRecords(2, 'buchungen', ['vorgang_id' => self::V1]);
    }

    public function test_gleiche_vorgang_id_bucht_nicht_doppelt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $pos    = [['artikel_id' => $helles, 'menge' => 2]];

        $erst  = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);
        $zweit = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);

        $this->assertFalse($erst['wiederholt']);
        $this->assertTrue($zweit['wiederholt']);
        $this->assertSame($erst['summe_cent'], $zweit['summe_cent']);
        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V1, 'artikel_id' => $helles]);
    }

    public function test_gleiche_vorgang_id_fremdes_konto_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $fremd  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $pos    = [['artikel_id' => $helles, 'menge' => 1]];

        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Ungültiger Vorgang.');
        service('buchungen')->bucheVorgang(self::V1, $fremd, $fremd, null, 'web', $pos);
    }

    public function test_gleiche_vorgang_id_anderer_buchender_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $andere = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $pos    = [['artikel_id' => $helles, 'menge' => 1]];

        service('buchungen')->bucheVorgang(self::V1, $konto, null, null, 'tablet', $pos);

        $this->expectException(BuchungAbgelehnt::class);
        service('buchungen')->bucheVorgang(self::V1, $konto, $andere, null, 'tablet', $pos);
    }

    public function test_preisaenderung_vor_dem_buchen_gilt_sofort(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        (new ArtikelModel())->update($helles, ['preis_cent' => 180]);

        $r = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 2]]);

        $this->assertSame(360, $r['summe_cent']);
        $this->assertSame('2× Helles – 3,60 €', $r['zusammenfassung']);
        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'einzelpreis_cent' => 180]);
    }

    public function test_archivierter_artikel_lehnt_ganzen_vorgang_ab(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $alt    = $this->artikelAnlegen(['name' => 'Altbier', 'archiviert_at' => '2026-10-01 10:00:00']);

        try {
            service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [
                ['artikel_id' => $helles, 'menge' => 1],
                ['artikel_id' => $alt, 'menge' => 1],
            ]);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('„Altbier“ ist nicht mehr buchbar. Nicht gebucht.', $e->getMessage());
        }

        $this->seeNumRecords(0, 'buchungen', []);
    }

    public function test_unbekannter_artikel_abgelehnt(): void
    {
        $konto = $this->personAnlegen();

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Ein Artikel ist nicht mehr buchbar. Nicht gebucht.');
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => 99999, 'menge' => 1]]);
    }

    public function test_kiosk_artikel_nicht_buchbar_solange_bereich_inaktiv(): void
    {
        $konto  = $this->personAnlegen();
        $kiosk  = (int) db_connect()->table('bereiche')->where('schluessel', 'kiosk')->get()->getRow()->id;
        db_connect()->table('kategorien')->insert(['bereich_id' => $kiosk, 'name' => 'Snacks']);
        $katId  = (int) db_connect()->insertID();
        $snack  = $this->artikelAnlegen(['name' => 'Brezel', 'kategorie_id' => $katId]);

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('„Brezel“ ist nicht mehr buchbar. Nicht gebucht.');
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $snack, 'menge' => 1]]);
    }

    public function test_menge_null_oder_100_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        foreach ([0, 100, -1] as $menge) {
            try {
                service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => $menge]]);
                $this->fail("Menge {$menge} müsste abgelehnt werden");
            } catch (BuchungAbgelehnt $e) {
                $this->assertSame('Ungültige Menge. Nicht gebucht.', $e->getMessage());
            }
        }

        $this->seeNumRecords(0, 'buchungen', []);
    }

    public function test_summierte_menge_ueber_99_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        $this->expectException(BuchungAbgelehnt::class);
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [
            ['artikel_id' => $helles, 'menge' => 60],
            ['artikel_id' => $helles, 'menge' => 40],
        ]);
    }

    public function test_leerer_warenkorb_zu_viele_positionen_und_falsche_typen(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $faelle = [
            'Der Warenkorb ist leer.' => [],
            'Zu viele Positionen.'    => array_fill(0, 31, ['artikel_id' => $helles, 'menge' => 1]),
            'Ungültige Position. Nicht gebucht.' => [['artikel_id' => $helles, 'menge' => '2']],
        ];

        foreach ($faelle as $meldung => $positionen) {
            try {
                service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $positionen);
                $this->fail($meldung);
            } catch (BuchungAbgelehnt $e) {
                $this->assertSame($meldung, $e->getMessage());
            }
        }
    }

    public function test_ungueltige_vorgang_id_und_inaktives_konto_abgelehnt(): void
    {
        $helles = $this->artikelAnlegen();
        $weg    = $this->personAnlegen(['archiviert_at' => '2026-09-01 10:00:00']);
        $pos    = [['artikel_id' => $helles, 'menge' => 1]];

        try {
            service('buchungen')->bucheVorgang('kein-uuid', $weg, null, null, 'web', $pos);
            $this->fail('UUID');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Ungültiger Vorgang.', $e->getMessage());
        }

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Dieses Konto ist nicht buchbar. Nicht gebucht.');
        service('buchungen')->bucheVorgang(self::V1, $weg, null, null, 'web', $pos);
    }

    public function test_unbekannte_quelle_ist_programmierfehler(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        $this->expectException(\InvalidArgumentException::class);
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'korrektur', [['artikel_id' => $helles, 'menge' => 1]]);
    }

    public function test_doppelte_artikel_werden_addiert(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        $r = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [
            ['artikel_id' => $helles, 'menge' => 2],
            ['artikel_id' => $helles, 'menge' => 3],
        ]);

        $this->assertCount(1, $r['positionen']);
        $this->assertSame(5, $r['positionen'][0]['menge']);
        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V1]);
        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'menge' => 5]);
    }

    public function test_vorgang_liefert_nur_nicht_stornierte(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $spezi  = $this->artikelAnlegen(['name' => 'Spezi', 'preis_cent' => 200]);

        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [
            ['artikel_id' => $helles, 'menge' => 1],
            ['artikel_id' => $spezi, 'menge' => 1],
        ]);

        $zeile = db_connect()->table('buchungen')->where('artikel_id', $spezi)->get()->getRowArray();
        service('buchungen')->storniereBuchung((int) $zeile['id'], $konto);

        $v = service('buchungen')->vorgang(self::V1);
        $this->assertFalse($v['wiederholt']);
        $this->assertCount(1, $v['positionen']);
        $this->assertSame(150, $v['summe_cent']);

        service('buchungen')->storniereVorgang(self::V1, $konto);
        $this->assertNull(service('buchungen')->vorgang(self::V1));
        $this->assertNull(service('buchungen')->vorgang(self::V2));
    }

    public function test_storno_innerhalb_frist(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 2]]);

        $this->uhrStellen('2026-10-05 12:09:00');
        service('buchungen')->storniereVorgang(self::V1, $konto);

        $this->seeInDatabase('buchungen', [
            'vorgang_id' => self::V1, 'storniert_at' => '2026-10-05 12:09:00', 'storniert_von_id' => $konto, 'storno_grund' => null,
        ]);
    }

    public function test_storno_nach_frist_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 2]]);

        $this->uhrStellen('2026-10-05 12:11:00');

        try {
            service('buchungen')->storniereVorgang(self::V1, $konto);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Die Storno-Frist ist abgelaufen.', $e->getMessage());
        }

        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'storniert_at' => null]);
    }

    public function test_doppeltes_storno_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 2]]);
        service('buchungen')->storniereVorgang(self::V1, $konto);

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Bereits storniert.');
        service('buchungen')->storniereVorgang(self::V1, $konto);
    }

    public function test_storno_unbekannt(): void
    {
        try {
            service('buchungen')->storniereVorgang(self::V2, null);
            $this->fail('Vorgang');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Unbekannter Vorgang.', $e->getMessage());
        }

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Unbekannte Buchung.');
        service('buchungen')->storniereBuchung(99999, null);
    }
    public function test_fehlgeschlagener_insert_laesst_keine_zeile_zurueck(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $spezi  = $this->artikelAnlegen(['name' => 'Spezi']);

        try {
            service('buchungen')->bucheVorgang(self::V1, $konto, 999999, null, 'web', [
                ['artikel_id' => $helles, 'menge' => 1],
                ['artikel_id' => $spezi, 'menge' => 1],
            ]);
            $this->fail('FK-Fehler erwartet');
        } catch (DatabaseException) {
            $this->assertTrue(true);
        }

        $this->seeNumRecords(0, 'buchungen', []);
    }

    public function test_wettlauf_rollt_teilzeilen_zurueck_und_antwortet_mit_gespeichertem_ergebnis(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $spezi  = $this->artikelAnlegen(['name' => 'Spezi', 'preis_cent' => 200]);
        $pos    = [['artikel_id' => $helles, 'menge' => 1], ['artikel_id' => $spezi, 'menge' => 1]];

        // Ein „paralleler“ Request legt zwischen Prüfung und Transaktion die Spezi-Zeile an.
        $service = $this->dienstMitKonkurrenz(self::V1, $konto, $konto, $spezi);
        $r       = $service->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);

        $this->assertTrue($r['wiederholt']);
        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V1]);
        $this->seeNumRecords(0, 'buchungen', ['vorgang_id' => self::V1, 'artikel_id' => $helles]);
    }

    public function test_wettlauf_mit_fremdem_konto_wird_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $fremd  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();

        $service = $this->dienstMitKonkurrenz(self::V1, $fremd, $fremd, $helles);

        try {
            $service->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Ungültiger Vorgang.', $e->getMessage());
        }

        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V1]);
        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'konto_id' => $fremd]);
    }

    public function test_wiederholung_eines_stornierten_vorgangs_meldet_storniert(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $pos    = [['artikel_id' => $helles, 'menge' => 2]];

        $erst = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);
        $this->assertFalse($erst['storniert']);

        service('buchungen')->storniereVorgang(self::V1, $konto);

        $zweit = service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', $pos);
        $this->assertTrue($zweit['wiederholt']);
        $this->assertTrue($zweit['storniert']);
        $this->assertSame(300, $zweit['summe_cent']);
        $this->assertSame('2× Helles – 3,00 €', $zweit['zusammenfassung']);
    }

    public function test_storno_im_eingefrorenen_zeitraum_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 2]]);
        $zeile = db_connect()->table('buchungen')->where('vorgang_id', self::V1)->get()->getRowArray();

        // Stichtag = Buchungszeitpunkt (Grenzsekunde gehört zum abgeschlossenen Zeitraum); Storno-Frist läuft noch.
        $this->auszaehlungAnlegen('2026-10-05 12:00:00');
        $this->uhrStellen('2026-10-05 12:05:00');

        foreach ([
            static fn () => service('buchungen')->storniereVorgang(self::V1, $konto),
            static fn () => service('buchungen')->storniereBuchung((int) $zeile['id'], $konto),
        ] as $storno) {
            try {
                $storno();
                $this->fail('Ablehnung erwartet');
            } catch (BuchungAbgelehnt $e) {
                $this->assertSame('Dieser Zeitraum ist abgeschlossen.', $e->getMessage());
            }
        }

        // Auch nach Fristablauf meldet der eingefrorene Zeitraum sich zuerst.
        $this->uhrStellen('2026-10-05 13:00:00');

        try {
            service('buchungen')->storniereVorgang(self::V1, $konto);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Dieser Zeitraum ist abgeschlossen.', $e->getMessage());
        }

        $this->seeInDatabase('buchungen', ['vorgang_id' => self::V1, 'storniert_at' => null]);
    }

    public function test_storno_direkt_nach_dem_stichtag_bleibt_erlaubt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
        $this->auszaehlungAnlegen('2026-10-05 11:59:59');
        $this->auszaehlungAnlegen('2026-10-05 12:00:00', 'entwurf');

        service('buchungen')->storniereVorgang(self::V1, $konto);

        $this->seeNumRecords(0, 'buchungen', ['vorgang_id' => self::V1, 'storniert_at' => null]);
    }

    public function test_rueckgaengig_nach_abschluss_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'tablet', [['artikel_id' => $helles, 'menge' => 1]]);

        // Abschluss in derselben Minute: die Tablet-Rückgängig-Taste käme noch innerhalb der Frist.
        $this->auszaehlungAnlegen('2026-10-05 12:00:00');
        $this->uhrStellen('2026-10-05 12:00:30');

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Dieser Zeitraum ist abgeschlossen.');
        service('buchungen')->storniereVorgang(self::V1, $konto);
    }

    public function test_buchung_im_eingefrorenen_zeitraum_abgelehnt(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        // Ein (paralleler) Abschluss mit Stichtag = jetzt ist bereits committet.
        $this->auszaehlungAnlegen('2026-10-05 12:00:00');

        try {
            service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Dieser Zeitraum ist abgeschlossen. Nicht gebucht.', $e->getMessage());
        }

        $this->seeNumRecords(0, 'buchungen', []);
    }

    /**
     * Der Zeiträume-Cache stammt aus der Zeit vor einem (in einem anderen Request) committeten Abschluss: Buchen und Storno
     * müssen ihn unter der Bereichssperre verwerfen (`vergiss()`), sonst rutschten sie in den eingefrorenen Zeitraum.
     */
    public function test_veralteter_zeitraeume_cache_wird_unter_der_sperre_verworfen(): void
    {
        $konto  = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);

        $this->assertNull(service('zeitraeume')->letzterStichtag($this->bereichId('getraenke')), 'Cache vorbelegt: kein Abschluss');
        $this->auszaehlungAnlegen('2026-10-05 12:00:00');

        foreach ([
            'Dieser Zeitraum ist abgeschlossen. Nicht gebucht.' => static fn () => service('buchungen')->bucheVorgang(self::V2, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]),
            'Dieser Zeitraum ist abgeschlossen.'                => static fn () => service('buchungen')->storniereVorgang(self::V1, $konto),
        ] as $meldung => $aktion) {
            try {
                $aktion();
                $this->fail('Ablehnung erwartet: ' . $meldung);
            } catch (BuchungAbgelehnt $e) {
                $this->assertSame($meldung, $e->getMessage());
            }
        }

        $this->seeNumRecords(1, 'buchungen', ['storniert_at' => null]);
    }

    public function test_buchung_sperrt_bereich(): void
    {
        $konto   = $this->personAnlegen();
        $helles  = $this->artikelAnlegen();
        $bereich = $this->bereichId('getraenke');

        $service = $this->dienstMitSperrprobe($bereich);
        $r       =$service->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);

        $this->assertFalse($r['wiederholt']);
        $this->assertInstanceOf(DatabaseException::class, $service->fehler);
        $this->assertSame(1205, $service->fehler->getCode(), $service->fehler->getMessage());
        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V1]);
    }

    public function test_storno_sperrt_bereich(): void
    {
        $konto   = $this->personAnlegen();
        $helles  = $this->artikelAnlegen();
        $bereich = $this->bereichId('getraenke');
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);

        $service = $this->dienstMitSperrprobe($bereich);
        $service->storniereVorgang(self::V1, $konto);

        $this->assertInstanceOf(DatabaseException::class, $service->fehler);
        $this->assertSame(1205, $service->fehler->getCode(), $service->fehler->getMessage());
        $this->seeNumRecords(0, 'buchungen', ['vorgang_id' => self::V1, 'storniert_at' => null]);
    }

    public function test_lock_timeout_wird_zu_deutscher_meldung_und_nichts_wird_geschrieben(): void
    {
        $konto   = $this->personAnlegen();
        $wart    = $this->personAnlegen();
        $helles  = $this->artikelAnlegen();
        $bereich = $this->bereichId('getraenke');
        $meldung = 'Gerade wird abgerechnet – bitte gleich erneut versuchen.';
        service('buchungen')->bucheVorgang(self::V2, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
        $id = (int) db_connect()->table('buchungen')->where('vorgang_id', self::V2)->get()->getRow()->id;

        $versuche = [
            'buchen'          => static fn () => service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]),
            'storno vorgang'  => static fn () => service('buchungen')->storniereVorgang(self::V2, $konto),
            'storno buchung'  => static fn () => service('buchungen')->storniereBuchung($id, $konto),
            'storno wart'     => static fn () => service('buchungen')->storniereAlsWart($id, $wart, 'Irrtum', $bereich),
            'korrektur'       => static fn () => service('buchungen')->bucheKorrektur($konto, $helles, -1, 'Fehler', $wart, $bereich),
        ];

        $this->beiGesperrtemBereich($bereich, function () use ($versuche, $meldung): void {
            foreach ($versuche as $name => $versuch) {
                try {
                    $versuch();
                    $this->fail("{$name}: Ablehnung erwartet");
                } catch (BuchungAbgelehnt $e) {
                    $this->assertSame($meldung, $e->getMessage(), $name);
                }
            }
        });

        $this->seeNumRecords(1, 'buchungen', []);
        $this->seeNumRecords(1, 'buchungen', ['vorgang_id' => self::V2, 'storniert_at' => null]);
        $this->seeNumRecords(0, 'protokoll', []);
    }

    public function test_wart_methoden_lehnen_anderen_bereich_ab(): void
    {
        $konto  = $this->personAnlegen();
        $wart   = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        $kiosk  = $this->bereichId('kiosk');
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
        $id = (int) db_connect()->table('buchungen')->where('vorgang_id', self::V1)->get()->getRow()->id;

        try {
            service('buchungen')->storniereAlsWart($id, $wart, 'x', $kiosk);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Unbekannte Buchung.', $e->getMessage());
        }

        try {
            service('buchungen')->bucheKorrektur($konto, $helles, 1, 'x', $wart, $kiosk);
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Bitte einen Artikel dieses Bereichs wählen.', $e->getMessage());
        }

        $this->seeNumRecords(1, 'buchungen', []);
        $this->seeNumRecords(1, 'buchungen', ['storniert_at' => null]);
    }

    public function test_wart_storno_ohne_grund_und_nach_frist(): void
    {
        $konto  = $this->personAnlegen();
        $wart   = $this->personAnlegen();
        $helles = $this->artikelAnlegen();
        service('buchungen')->bucheVorgang(self::V1, $konto, $konto, null, 'web', [['artikel_id' => $helles, 'menge' => 1]]);
        $id = (int) db_connect()->table('buchungen')->where('vorgang_id', self::V1)->get()->getRow()->id;
        $this->uhrStellen('2026-10-07 12:00:00');

        try {
            service('buchungen')->storniereAlsWart($id, $wart, '  ', $this->bereichId('getraenke'));
            $this->fail('Ablehnung erwartet');
        } catch (BuchungAbgelehnt $e) {
            $this->assertSame('Bitte einen Grund angeben.', $e->getMessage());
        }

        service('buchungen')->storniereAlsWart($id, $wart, ' Doppelt gebucht ', $this->bereichId('getraenke'));
        $this->seeInDatabase('buchungen', ['id' => $id, 'storniert_von_id' => $wart, 'storno_grund' => 'Doppelt gebucht', 'storniert_at' => '2026-10-07 12:00:00']);
        $this->seeInDatabase('protokoll', ['person_id' => $wart, 'aktion' => 'storniert', 'tabelle' => 'buchungen', 'datensatz_id' => $id]);
    }

    /**
     * Dienst, dessen Hook (in der Transaktion, nach der Bereichssperre) über eine zweite Verbindung
     * versucht, den Bereich selbst zu sperren; die Exception landet in `$fehler`.
     */
    private function dienstMitSperrprobe(int $bereichId): BuchungService
    {
        return new class ($bereichId) extends BuchungService {
            public ?\Throwable $fehler = null;

            public function __construct(private int $bereich)
            {
            }

            protected function vorDemSchreiben(string $vorgangId): void
            {
                $zweite = \Config\Database::connect('tests', false);

                try {
                    $zweite->query('SET SESSION innodb_lock_wait_timeout = 1');
                    $zweite->query('SELECT id FROM bereiche WHERE id = ? FOR UPDATE', [$this->bereich]);
                } catch (\Throwable $e) {
                    $this->fehler = $e;
                } finally {
                    $zweite->close();
                }
            }
        };
    }

    private function dienstMitKonkurrenz(string $vorgangId, int $kontoId, int $vonId, int $artikelId): BuchungService
    {
        return new class ($vorgangId, $kontoId, $vonId, $artikelId) extends BuchungService {
            public function __construct(private string $v, private int $k, private int $von, private int $a)
            {
            }

            /**
             * Der Hook läuft innerhalb der Buchungs-Transaktion; der „parallele“ Request schreibt
             * deshalb über eine eigene Verbindung (autocommit) wie ein echter zweiter Request.
             */
            protected function vorDemSchreiben(string $vorgangId): void
            {
                $zweite = \Config\Database::connect('tests', false);

                try {
                    $zweite->table('buchungen')->insert([
                        'vorgang_id' => $this->v, 'konto_id' => $this->k, 'artikel_id' => $this->a, 'menge' => 1,
                        'einzelpreis_cent' => 100, 'quelle' => 'web', 'gebucht_von_id' => $this->von,
                        'gebucht_at' => '2026-10-05 12:00:00',
                    ]);
                } finally {
                    $zweite->close();
                }
            }
        };
    }
}