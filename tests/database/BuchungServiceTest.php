<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\BuchungAbgelehnt;
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
}
