<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\StatistikRechner;
use App\Libraries\StatistikService;
use App\Models\ArtikelModel;
use App\Models\AuszaehlungModel;
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

    public function test_ansicht_optionen_nur_aktive_des_bereichs(): void
    {
        $hell = $this->artikelAnlegen(['name' => 'Helles', 'einheit' => '0,5 l']);
        $this->artikelAnlegen(['name' => 'Pils', 'archiviert_at' => '2026-10-01 00:00:00']);
        $bier = (int) (new ArtikelModel())->find($hell)['kategorie_id'];
        $leer = (int) (new KategorieModel())->insert(['bereich_id' => $this->bereich, 'name' => 'Wein'], true);
        (new KategorieModel())->insert(['bereich_id' => $this->bereich, 'name' => 'Alt', 'archiviert_at' => '2026-09-01 00:00:00']);
        $this->artikelAnlegen(['name' => 'Riegel', 'kategorie_id' => $this->kioskKategorie()]);

        $this->assertSame([
            ['id' => $bier, 'name' => 'Bier', 'artikel' => [['id' => $hell, 'name' => 'Helles (0,5 l)']]],
            ['id' => $leer, 'name' => 'Wein', 'artikel' => []],
        ], $this->service()->ansichtOptionen($this->bereich));
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

    private function position(int $auszaehlung, int $artikel, int $differenz, int $preis, int $verkauft, int $start = 0): void
    {
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $auszaehlung, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0,
            'schwund_erfasst' => 0, 'korrekturen' => 0, 'verkauft' => $verkauft, 'soll' => 0, 'ist' => $differenz,
            'differenz' => $differenz, 'start' => $start, 'preis_cent' => $preis,
        ]);
    }

    /**
     * Zwei abgeschlossene Zeiträume (Start + regulär) mit Schwund, Differenzen, Start-Position und Überschuss.
     *
     * @return array{a: int, b: int, c: int, d: int, kiosk: int, z1: int, z2: int}
     */
    private function schwundSzenario(): array
    {
        $a     = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $b     = $this->artikelAnlegen(['name' => 'Pils', 'preis_cent' => 200]);
        $c     = $this->artikelAnlegen(['name' => 'Radler', 'preis_cent' => 180]);
        $d     = $this->artikelAnlegen(['name' => 'Wasser', 'preis_cent' => 100]);
        $kiosk = $this->artikelAnlegen(['kategorie_id' => $this->kioskKategorie(), 'name' => 'Riegel', 'preis_cent' => 90]);

        $z1 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['art' => 'start', 'zeitraum_von' => '2026-09-01 00:00:00']);
        // Start-Auszählung: Positionen mit start = 0 und Fehlmenge/Überschuss zählen trotzdem nicht (S3-R5)
        $this->position($z1, $a, -3, 140, 10);
        $this->position($z1, $b, 2, 190, 5);

        $z2 = $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-10 12:00:00']);
        $this->position($z2, $a, -4, 150, 40);
        $this->position($z2, $b, 3, 200, 20);
        $this->position($z2, $c, -5, 180, 0, 1); // neuer Artikel: zählt nicht als unerklärt

        // Entwurf und Kiosk-Auszählung bleiben außen vor
        $entwurf = $this->auszaehlungAnlegen('2026-10-05 12:00:00', 'entwurf', 'getraenke', ['zeitraum_von' => '2026-10-01 18:00:00']);
        $this->position($entwurf, $a, -50, 150, 1);
        $kioskZ = $this->auszaehlungAnlegen('2026-09-20 12:00:00', 'abgeschlossen', 'kiosk', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($kioskZ, $kiosk, -9, 90, 3);

        // Zeitraum 1 (Start): Schwund zählt nicht; Stichtag inklusiv → gehört nicht zu Zeitraum 2
        $this->bewegung($a, -1, '2026-09-01 00:00:00', 'schwund');
        $this->bewegung($a, -1, '2026-09-10 12:00:00', 'schwund');
        $this->bewegung($b, -2, '2026-09-10 12:00:00', 'schwund');
        $this->bewegung($a, -7, '2026-08-31 23:59:59', 'schwund'); // vor dem ersten Zeitraum
        // Zeitraum 2: Beginn exklusiv
        $this->bewegung($a, -2, '2026-09-20 10:00:00', 'schwund');
        $this->bewegung($b, -1, '2026-10-01 18:00:00', 'schwund');
        $this->bewegung($d, -3, '2026-09-15 10:00:00', 'schwund'); // ohne Position → aktueller Preis
        $this->bewegung($a, -5, '2026-09-20 10:00:00', 'korrektur');
        $this->bewegung($a, 24, '2026-09-20 10:00:00', 'lieferung');
        $this->bewegung($kiosk, -7, '2026-09-20 10:00:00', 'schwund');
        // laufender Zeitraum
        $this->bewegung($a, -2, '2026-10-03 10:00:00', 'schwund');
        $this->bewegung($d, -1, '2026-10-08 10:00:00', 'schwund');
        $this->bewegung($a, -4, '2026-10-08 10:00:00', 'korrektur');
        $this->bewegung($kiosk, -6, '2026-10-08 10:00:00', 'schwund');

        return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'kiosk' => $kiosk, 'z1' => $z1, 'z2' => $z2];
    }

    public function test_schwund_zeitraeume_erfasst_unerklaert_ueberschuss_getrennt(): void
    {
        $s = $this->schwundSzenario();

        $zeitraeume = $this->service()->schwundZeitraeume($this->bereich);

        $this->assertSame([
            [
                'auszaehlung_id' => $s['z2'], 'von' => '2026-09-10 12:00:00', 'bis' => '2026-10-01 18:00:00', 'art' => 'regulaer',
                'erfasst_menge' => 6, 'erfasst_cent' => 2 * 150 + 1 * 200 + 3 * 100,
                'unerklaert_menge' => 4, 'unerklaert_cent' => 600,
                'ueberschuss_menge' => 3, 'ueberschuss_cent' => 600,
                'verkauft' => 60, 'quote' => 16.7, // (6 erfasst + 4 unerklärt) ÷ 60
            ],
            [
                'auszaehlung_id' => $s['z1'], 'von' => '2026-09-01 00:00:00', 'bis' => '2026-09-10 12:00:00', 'art' => 'start',
                'erfasst_menge' => 0, 'erfasst_cent' => 0,
                'unerklaert_menge' => 0, 'unerklaert_cent' => 0,
                'ueberschuss_menge' => 0, 'ueberschuss_cent' => 0,
                'verkauft' => 15, 'quote' => null, // Start-Auszählung: alles 0, keine Quote
            ],
        ], $zeitraeume);
    }

    public function test_schwund_ohne_zeitraeume_bei_anzahl_null(): void
    {
        $this->schwundSzenario();

        $this->assertSame([], $this->service()->schwundZeitraeume($this->bereich, 0));
        $this->assertSame([], $this->service()->schwundZeitraeume($this->bereich, -3));
        $this->assertSame([], (new AuszaehlungModel())->letzteMitZeitraum($this->bereich, 0));
        $this->assertSame([], $this->service()->schwundArtikel($this->bereich, 0));
    }

    public function test_schwund_start_auszaehlung_regulaer_gezaehlt(): void
    {
        // Gegenprobe: dieselben Positionen in einer regulären ersten Auszählung zählen (Beginn inklusiv)
        $a  = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $z1 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z1, $a, -3, 140, 10);
        $this->bewegung($a, -1, '2026-09-01 00:00:00', 'schwund');

        $zeitraum = $this->service()->schwundZeitraeume($this->bereich)[0];

        $this->assertSame([1, 140, 3, 420, 40.0], [
            $zeitraum['erfasst_menge'], $zeitraum['erfasst_cent'], $zeitraum['unerklaert_menge'], $zeitraum['unerklaert_cent'], $zeitraum['quote'],
        ]);
    }

    public function test_schwund_zeitraeume_begrenzt_ohne_inklusiven_beginn(): void
    {
        $s = $this->schwundSzenario();

        $nur = $this->service()->schwundZeitraeume($this->bereich, 1);

        $this->assertCount(1, $nur);
        $this->assertSame($s['z2'], $nur[0]['auszaehlung_id']);
        // Schwund genau am vorherigen Stichtag gehört zum vorherigen Zeitraum, auch wenn dieser nicht geladen wird
        $this->assertSame(6, $nur[0]['erfasst_menge']);
        $this->assertSame([], $this->service()->schwundZeitraeume($this->bereichId('kiosk') + 1000));
    }

    public function test_schwund_artikel_top_nach_gesamtbetrag(): void
    {
        $s = $this->schwundSzenario();

        // Start-Zeitraum trägt nichts bei (weder Schwund noch verkauft)
        $this->assertSame([
            ['artikel_id' => $s['a'], 'name' => 'Helles (0,5 l)', 'erfasst_menge' => 2, 'unerklaert_menge' => 4, 'gesamt_cent' => 300 + 600, 'quote' => 15.0],
            ['artikel_id' => $s['d'], 'name' => 'Wasser (0,5 l)', 'erfasst_menge' => 3, 'unerklaert_menge' => 0, 'gesamt_cent' => 300, 'quote' => null],
            ['artikel_id' => $s['b'], 'name' => 'Pils (0,5 l)', 'erfasst_menge' => 1, 'unerklaert_menge' => 0, 'gesamt_cent' => 200, 'quote' => 5.0],
        ], $this->service()->schwundArtikel($this->bereich));

        $this->assertSame([$s['a'], $s['d']], array_column($this->service()->schwundArtikel($this->bereich, 6, 2), 'artikel_id'));
        $this->assertSame([$s['a'], $s['d'], $s['b']], array_column($this->service()->schwundArtikel($this->bereich, 1), 'artikel_id'));
    }

    public function test_schwund_artikel_gleicher_betrag_nach_name(): void
    {
        $x  = $this->artikelAnlegen(['name' => 'Zwickl', 'preis_cent' => 100]);
        $y  = $this->artikelAnlegen(['name' => 'Apfelschorle', 'preis_cent' => 100]);
        $z1 = $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z1, $x, -2, 100, 10);
        $this->position($z1, $y, -2, 100, 10);

        $this->assertSame([$y, $x], array_column($this->service()->schwundArtikel($this->bereich), 'artikel_id'));
    }

    public function test_schwund_artikel_verlauf(): void
    {
        $s = $this->schwundSzenario();

        $this->assertSame([
            ['von' => '2026-09-10 12:00:00', 'bis' => '2026-10-01 18:00:00', 'erfasst_menge' => 2, 'unerklaert_menge' => 4, 'gesamt_cent' => 900],
            ['von' => '2026-09-01 00:00:00', 'bis' => '2026-09-10 12:00:00', 'erfasst_menge' => 0, 'unerklaert_menge' => 0, 'gesamt_cent' => 0],
        ], $this->service()->schwundArtikelVerlauf($this->bereich, $s['a']));

        $this->assertSame([], $this->service()->schwundArtikelVerlauf($this->bereich, $s['kiosk']));
        $this->assertSame([], $this->service()->schwundArtikelVerlauf($this->bereich, 999999));
    }

    public function test_schwund_laufend_nur_nach_letztem_stichtag(): void
    {
        $this->schwundSzenario();

        $this->assertSame(['menge' => 3, 'cent' => 2 * 150 + 1 * 100], $this->service()->schwundLaufend($this->bereich));
    }

    public function test_schwund_ohne_auszaehlung(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $this->bewegung($a, -2, '2026-09-01 00:00:00', 'schwund'); // Inbetriebnahme inklusiv
        $this->bewegung($a, -5, '2026-08-31 23:59:59', 'schwund');
        $this->bewegung($a, -1, '2026-10-09 10:00:00', 'schwund');

        $this->assertSame([], $this->service()->schwundZeitraeume($this->bereich));
        $this->assertSame([], $this->service()->schwundArtikel($this->bereich));
        $this->assertSame([], $this->service()->schwundArtikelVerlauf($this->bereich, $a));
        $this->assertSame(['menge' => 3, 'cent' => 450], $this->service()->schwundLaufend($this->bereich));
        $this->assertSame(['menge' => 0, 'cent' => 0], $this->service()->schwundLaufend($this->bereichId('kiosk')));
    }
}
