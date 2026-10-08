<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\StatistikRechner;
use App\Libraries\StatistikService;
use App\Models\ArtikelModel;
use App\Models\KategorieModel;
use App\Models\PersonModel;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class StatistikServiceTest extends DbTestCase
{
    private int $bereich;
    private int $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbetriebnahme('2026-09-01 00:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->bereich = $this->bereichId('getraenke');
        $this->person  = $this->personAnlegen(['anzeigename' => 'Wart Willi']);
    }

    private function inbetriebnahme(string $zeit): void
    {
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => $zeit]);
        $this->resetServices(); // Einstellungs-Cache verwerfen
    }

    private function bewegung(int $artikel, int $menge, string $zeit, string $art = 'lieferung', ?int $preis = null, ?int $person = null): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => $art, 'menge' => $menge, 'einkaufspreis_cent' => $preis,
            'person_id' => $person ?? $this->person, 'erfolgt_at' => $zeit,
        ]);
    }

    /**
     * @param array<string, mixed> $werte
     */
    private function buchung(int $artikel, int $menge, string $zeit, array $werte = []): void
    {
        db_connect()->table('buchungen')->insert(array_merge([
            'vorgang_id' => bin2hex(random_bytes(18)), 'konto_id' => $this->person, 'artikel_id' => $artikel,
            'menge' => $menge, 'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_at' => $zeit,
            'storniert_at' => null, 'bestandswirksam' => 1,
        ], $werte));
    }

    private function service(): StatistikService
    {
        service('zeitraeume')->vergiss();

        return new StatistikService();
    }

    /**
     * @return array<string, mixed>
     */
    private function artikelZeile(array $einkauf, int $artikelId): array
    {
        foreach ($einkauf['kategorien'] as $kategorie) {
            foreach ($kategorie['artikel'] as $artikel) {
                if ($artikel['artikel_id'] === $artikelId) {
                    return $artikel;
                }
            }
        }

        $this->fail("Artikel {$artikelId} fehlt im Ergebnis.");
    }

    private function kioskKategorie(): int
    {
        return (int) (new KategorieModel())->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks'], true);
    }

    public function test_service_ist_registriert(): void
    {
        $this->assertInstanceOf(StatistikService::class, service('statistik'));
        $this->assertSame(service('statistik'), service('statistik'));
    }

    public function test_einkauf_verbrauch_28_tage_und_vorschlag(): void
    {
        $a = $this->artikelAnlegen(['mindestbestand' => 90, 'gebinde_groesse' => 24]);
        $this->bewegung($a, 100, '2026-09-02 10:00:00');
        $this->buchung($a, 10, '2026-09-13 12:00:00');                       // vor 27 Tagen: zählt
        $this->buchung($a, 5, '2026-09-11 12:00:00');                        // vor 29 Tagen: zählt nicht
        $this->buchung($a, 3, '2026-10-01 12:00:00', ['storniert_at' => '2026-10-01 12:05:00']);
        $this->buchung($a, -2, '2026-10-02 12:00:00', ['quelle' => 'korrektur']);
        $this->buchung($a, 4, '2026-10-03 12:00:00', ['quelle' => 'korrektur', 'bestandswirksam' => 0]);
        $this->buchung($a, 1, '2026-09-12 12:00:00');                        // genau jetzt − 28 Tage: exklusiv
        $this->buchung($a, 1, '2026-10-10 12:00:00');                        // genau jetzt: inklusiv

        $einkauf = $this->service()->einkauf($this->bereich);
        $zeile   = $this->artikelZeile($einkauf, $a);

        $this->assertSame(28, $einkauf['grundlage_tage']);
        $this->assertSame(30, $einkauf['reichweite_tage']);
        $verbrauch = (10 - 2 + 1) / 28;
        $this->assertEqualsWithDelta($verbrauch, $zeile['tagesverbrauch'], 1e-9);
        $this->assertSame(24, $zeile['gebinde_groesse']);
        $this->assertSame(service('bestand')->einzeln($a), $zeile['bestand']);
        $this->assertSame(StatistikRechner::reichweiteTage($zeile['bestand'], $verbrauch), $zeile['reichweite']);
        $this->assertSame(StatistikRechner::vorschlag($verbrauch, 30, 90, $zeile['bestand'], 24), $zeile['vorschlag']);
        $this->assertSame(['stueck' => 24, 'kisten' => 1], $zeile['vorschlag']);
    }

    public function test_einkauf_bestand_gleich_bestandservice(): void
    {
        $a = $this->artikelAnlegen(['name' => 'A']);
        $b = $this->artikelAnlegen(['name' => 'B', 'mindestbestand' => 3]);
        $c = $this->artikelAnlegen(['name' => 'C']);
        $id = $this->auszaehlungAnlegen('2026-10-01 12:00:00');
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $id, 'artikel_id' => $a, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => 17, 'differenz' => 0, 'start' => 0, 'preis_cent' => 150,
        ]);
        $this->bewegung($a, 12, '2026-10-02 10:00:00');
        $this->bewegung($b, 6, '2026-10-02 10:00:00');
        $this->bewegung($b, -2, '2026-10-03 10:00:00', 'schwund');
        $this->buchung($a, 3, '2026-10-04 10:00:00');
        $this->buchung($b, 9, '2026-10-04 10:00:00');
        $this->buchung($c, 1, '2026-09-20 10:00:00');

        $einkauf = $this->service()->einkauf($this->bereich);

        foreach ([$a, $b, $c] as $artikel) {
            $zeile = $this->artikelZeile($einkauf, $artikel);
            $this->assertSame(service('bestand')->einzeln($artikel), $zeile['bestand']);
        }

        $this->assertSame('negativ', $this->artikelZeile($einkauf, $b)['ampel']);
    }

    public function test_letzter_einkaufspreis_ist_juengste_lieferung_mit_preis(): void
    {
        $a = $this->artikelAnlegen(['name' => 'A']);
        $b = $this->artikelAnlegen(['name' => 'B']);
        $this->bewegung($a, 24, '2026-09-05 10:00:00', 'lieferung', 80);
        $this->bewegung($a, 24, '2026-09-20 10:00:00', 'lieferung', 85);
        $this->bewegung($a, 24, '2026-10-01 10:00:00', 'lieferung', null);
        $this->bewegung($a, 5, '2026-10-02 10:00:00', 'korrektur');

        $einkauf = $this->service()->einkauf($this->bereich);

        $this->assertSame(85, $this->artikelZeile($einkauf, $a)['letzter_ek_cent']);
        $this->assertNull($this->artikelZeile($einkauf, $b)['letzter_ek_cent']);
    }

    public function test_einkauf_leere_datenbank(): void
    {
        $this->inbetriebnahme('2026-10-07 09:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');

        $this->assertSame([], $this->service()->einkauf($this->bereich)['kategorien']);

        $a       = $this->artikelAnlegen();
        $einkauf = $this->service()->einkauf($this->bereich);
        $zeile   = $this->artikelZeile($einkauf, $a);

        $this->assertSame(3, $einkauf['grundlage_tage']);
        $this->assertSame(0.0, $zeile['tagesverbrauch']);
        $this->assertNull($zeile['vorschlag']);
        $this->assertNull($zeile['reichweite']);
        $this->assertNull($zeile['letzter_ek_cent']);
        $this->assertNull($zeile['gebinde_groesse']);
        $this->assertSame(0, $zeile['bestand']);
    }

    public function test_einkauf_ohne_kiosk_artikel(): void
    {
        $kiosk = $this->artikelAnlegen(['kategorie_id' => $this->kioskKategorie(), 'name' => 'Riegel']);
        $this->buchung($kiosk, 5, '2026-10-01 10:00:00');

        $this->assertSame([], $this->service()->einkauf($this->bereich)['kategorien']);
    }

    public function test_anteile_mitglieder_couleur_bund_mit_negativer_korrektur(): void
    {
        $personen = new PersonModel();
        $couleur  = $personen->sammelkontoId('Couleur');
        $bund     = $personen->sammelkontoId('Bund');
        $a        = $this->artikelAnlegen(['preis_cent' => 150]);
        $kiosk    = $this->artikelAnlegen(['kategorie_id' => $this->kioskKategorie(), 'name' => 'Riegel']);

        $this->buchung($a, 4, '2026-10-01 10:00:00');
        $this->buchung($a, -1, '2026-10-02 10:00:00', ['quelle' => 'korrektur', 'bestandswirksam' => 0]);
        $this->buchung($a, 3, '2026-10-01 10:00:00', ['konto_id' => $couleur, 'einzelpreis_cent' => 200]);
        $this->buchung($a, 2, '2026-10-01 10:00:00', ['konto_id' => $bund]);
        $this->buchung($a, -2, '2026-10-03 10:00:00', ['konto_id' => $bund, 'quelle' => 'korrektur']);
        $this->buchung($a, 7, '2026-10-01 10:00:00', ['konto_id' => $bund, 'storniert_at' => '2026-10-01 10:01:00']);
        $this->buchung($kiosk, 9, '2026-10-01 10:00:00');
        $this->buchung($a, 50, '2026-09-01 00:00:00');              // genau „von“
        $this->buchung($a, 60, '2026-10-05 00:00:01');              // nach „bis“

        $von = new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $bis = new DateTimeImmutable('2026-10-05 00:00:00', new DateTimeZone('Europe/Berlin'));

        $exklusiv = $this->service()->anteile($this->bereich, $von, false, $bis);
        $this->assertSame(['mitglieder' => 3, 'couleur' => 3, 'bund' => 0], $exklusiv['menge']);
        $this->assertSame(['mitglieder' => 450, 'couleur' => 600, 'bund' => 0], $exklusiv['cent']);

        $inklusiv = $this->service()->anteile($this->bereich, $von, true, $bis);
        $this->assertSame(['mitglieder' => 53, 'couleur' => 3, 'bund' => 0], $inklusiv['menge']);
        $this->assertSame(['mitglieder' => 7950, 'couleur' => 600, 'bund' => 0], $inklusiv['cent']);
    }

    public function test_anteile_negativer_gesamtbetrag_ohne_ueberlauf(): void
    {
        $a = $this->artikelAnlegen(['preis_cent' => 150]);
        $this->buchung($a, -3, '2026-10-02 10:00:00', ['quelle' => 'korrektur']);

        $von = new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('Europe/Berlin'));
        $bis = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('Europe/Berlin'));

        $anteile = $this->service()->anteile($this->bereich, $von, false, $bis);
        $this->assertSame(-3, $anteile['menge']['mitglieder']);
        $this->assertSame(-450, $anteile['cent']['mitglieder']);
    }

    public function test_wochenverbrauch_ueber_jahreswechsel(): void
    {
        $this->uhrStellen('2027-01-06 12:00:00');
        $a = $this->artikelAnlegen();
        $this->buchung($a, 2, '2026-12-31 20:00:00');                 // KW 53/2026
        $this->buchung($a, 3, '2027-01-03 23:59:59');                 // So, KW 53/2026
        $this->buchung($a, 4, '2027-01-04 00:00:00');                 // Mo, KW 1/2027
        $this->buchung($a, -1, '2027-01-05 00:00:00', ['quelle' => 'korrektur']);
        $this->buchung($a, 9, '2027-01-05 00:00:00', ['quelle' => 'korrektur', 'bestandswirksam' => 0]);
        $this->buchung($a, 6, '2027-01-05 00:00:00', ['storniert_at' => '2027-01-05 00:01:00']);
        $this->buchung($a, 8, '2027-01-06 12:00:01');                 // nach „jetzt“
        $this->buchung($a, 7, '2026-12-21 10:00:00');                 // KW 52/2026
        $this->buchung($a, 5, '2026-10-18 23:00:00');                 // vor dem Fenster (So, KW 42)

        $verlauf = $this->service()->wochenverbrauch($this->bereich, 'alle', 3);

        $this->assertSame(['2026-W52', '2026-W53', '2027-W01'], $verlauf['wochen']);
        $this->assertSame('alle', $verlauf['ansicht']);
        $this->assertSame([['name' => 'Bier', 'werte' => [7, 5, 3]]], $verlauf['reihen']);
    }

    public function test_sql_iso_woche_entspricht_rechner(): void
    {
        foreach (['2026-12-31 12:00:00', '2027-01-03 12:00:00', '2027-01-04 00:00:00', '2026-03-29 03:30:00', '2026-10-25 02:30:00'] as $zeit) {
            $sql = db_connect()->query("SELECT DATE_FORMAT(?, '%x-W%v') AS woche", [$zeit])->getRow()->woche;
            $this->assertSame(StatistikRechner::isoWoche(new DateTimeImmutable($zeit, new DateTimeZone('Europe/Berlin'))), $sql, $zeit);
        }
    }

    public function test_wochenverbrauch_ansichten(): void
    {
        $hell  = $this->artikelAnlegen(['name' => 'Helles']);
        $bier  = (int) (new ArtikelModel())->find($hell)['kategorie_id'];
        $pils  = $this->artikelAnlegen(['name' => 'Pils', 'archiviert_at' => '2026-10-01 00:00:00']);
        $wasserKat = (int) (new KategorieModel())->insert(['bereich_id' => $this->bereich, 'name' => 'Wasser', 'sortierung' => -1], true);
        $wasser    = $this->artikelAnlegen(['name' => 'Sprudel', 'kategorie_id' => $wasserKat, 'einheit' => '0,7 l', 'bestand_fuehren' => 0]);
        $leerKat   = (int) (new KategorieModel())->insert(['bereich_id' => $this->bereich, 'name' => 'Alt', 'archiviert_at' => '2026-09-01 00:00:00'], true);
        $kioskKat  = $this->kioskKategorie();
        $riegel    = $this->artikelAnlegen(['name' => 'Riegel', 'kategorie_id' => $kioskKat]);

        $this->buchung($hell, 2, '2026-10-06 10:00:00');
        $this->buchung($pils, 3, '2026-10-07 10:00:00');
        $this->buchung($wasser, 4, '2026-10-08 10:00:00');
        $this->buchung($riegel, 9, '2026-10-08 10:00:00');

        $alle = $this->service()->wochenverbrauch($this->bereich, 'alle', 2);
        $this->assertSame(['2026-W40', '2026-W41'], $alle['wochen']);
        $this->assertSame([
            ['name' => 'Wasser', 'werte' => [0, 4]],
            ['name' => 'Bier', 'werte' => [0, 5]],
        ], $alle['reihen']);

        $kategorie = $this->service()->wochenverbrauch($this->bereich, "kategorie:{$bier}", 2);
        $this->assertSame("kategorie:{$bier}", $kategorie['ansicht']);
        $this->assertSame([
            ['name' => 'Helles (0,5 l)', 'werte' => [0, 2]],
            ['name' => 'Pils (0,5 l)', 'werte' => [0, 3]],
        ], $kategorie['reihen']);

        $artikel = $this->service()->wochenverbrauch($this->bereich, "artikel:{$pils}", 2);
        $this->assertSame("artikel:{$pils}", $artikel['ansicht']);
        $this->assertSame([['name' => 'Pils (0,5 l)', 'werte' => [0, 3]]], $artikel['reihen']);

        $archiviert = $this->service()->wochenverbrauch($this->bereich, "kategorie:{$leerKat}", 2);
        $this->assertSame("kategorie:{$leerKat}", $archiviert['ansicht']);
        $this->assertSame([], $archiviert['reihen']);

        foreach (["artikel:{$riegel}", "kategorie:{$kioskKat}", 'kategorie:abc', 'artikel:', 'artikel:999999', 'foo', '', "artikel:{$hell}x", "kategorie: {$bier}"] as $ungueltig) {
            $ergebnis = $this->service()->wochenverbrauch($this->bereich, $ungueltig, 2);
            $this->assertSame('alle', $ergebnis['ansicht'], $ungueltig);
            $this->assertSame($alle['reihen'], $ergebnis['reihen'], $ungueltig);
        }
    }

    public function test_wochenverbrauch_leer(): void
    {
        $verlauf = $this->service()->wochenverbrauch($this->bereich, 'alle', 12);

        $this->assertCount(12, $verlauf['wochen']);
        $this->assertSame('2026-W41', $verlauf['wochen'][11]);
        $this->assertSame([], $verlauf['reihen']);
    }

    public function test_lieferhistorie_gruppiert(): void
    {
        $andere = $this->personAnlegen(['anzeigename' => 'Anna Andere']);
        $a      = $this->artikelAnlegen(['name' => 'Helles']);
        $b      = $this->artikelAnlegen(['name' => 'Pils']);
        $kiosk  = $this->artikelAnlegen(['kategorie_id' => $this->kioskKategorie(), 'name' => 'Riegel']);

        $this->bewegung($a, 24, '2026-10-02 10:00:00', 'lieferung', 80);
        $this->bewegung($b, 48, '2026-10-02 10:00:00', 'lieferung', null);
        $this->bewegung($a, 12, '2026-10-02 10:00:00', 'lieferung', 81, $andere);
        $this->bewegung($a, 6, '2026-10-05 10:00:00', 'lieferung', 82);
        $this->bewegung($a, -2, '2026-10-06 10:00:00', 'schwund');
        $this->bewegung($kiosk, 10, '2026-10-07 10:00:00', 'lieferung', 30);
        $this->bewegung($a, 3, '2026-09-01 10:00:00', 'lieferung', 79);

        $historie = $this->service()->lieferhistorie($this->bereich, 3);

        $this->assertCount(3, $historie);
        $this->assertSame('2026-10-05 10:00:00', $historie[0]['erfolgt_at']);
        $this->assertSame('Wart Willi', $historie[0]['erfasst_von']);
        $this->assertSame([['artikel' => 'Helles', 'menge' => 6, 'einkaufspreis_cent' => 82]], $historie[0]['zeilen']);

        $gruppen = array_slice($historie, 1);
        usort($gruppen, static fn (array $x, array $y): int => strcmp($x['erfasst_von'], $y['erfasst_von']));
        $this->assertSame('2026-10-02 10:00:00', $gruppen[0]['erfolgt_at']);
        $this->assertSame('Anna Andere', $gruppen[0]['erfasst_von']);
        $this->assertSame([['artikel' => 'Helles', 'menge' => 12, 'einkaufspreis_cent' => 81]], $gruppen[0]['zeilen']);
        $this->assertSame('Wart Willi', $gruppen[1]['erfasst_von']);
        $this->assertSame([
            ['artikel' => 'Helles', 'menge' => 24, 'einkaufspreis_cent' => 80],
            ['artikel' => 'Pils', 'menge' => 48, 'einkaufspreis_cent' => null],
        ], $gruppen[1]['zeilen']);

        $this->assertSame([], (new StatistikService())->lieferhistorie($this->bereichId('kiosk') + 1000, 20));
    }
}
