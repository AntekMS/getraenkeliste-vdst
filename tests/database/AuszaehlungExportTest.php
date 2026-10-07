<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\AuszaehlungExport;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\DbTestCase;

/**
 * Excel-Export einer abgeschlossenen Auszählung (Spec 8.1/8.2). Die Datei wird mit IOFactory wieder eingelesen.
 *
 * @internal
 */
final class AuszaehlungExportTest extends DbTestCase
{
    /** Kopfzeilen wörtlich aus Spec 8.2 (nicht aus der Klasse gelesen). */
    private const KOPFZEILEN = [
        'Abrechnung' => ['konto_id', 'konto_typ', 'vorname', 'nachname', 'anzeigename', 'gruppe', 'anzahl_artikel', 'betrag_eur'],
        'Positionen' => ['konto_id', 'anzeigename', 'artikel_id', 'artikel', 'kategorie', 'menge', 'einzelpreis_eur', 'summe_eur'],
        'Buchungen'  => ['buchung_id', 'vorgang_id', 'gebucht_am', 'konto_id', 'anzeigename', 'artikel_id', 'artikel', 'menge', 'einzelpreis_eur', 'summe_eur', 'quelle', 'gebucht_von', 'storniert', 'storno_grund'],
        'Bestand'    => ['artikel_id', 'artikel', 'kategorie', 'anfangsbestand', 'lieferungen', 'schwund_erfasst', 'korrekturen', 'verkauft', 'soll', 'ist', 'differenz', 'differenz_eur', 'verkaufspreis_eur', 'einkaufswert_eur'],
        'Bewegungen' => ['bewegung_id', 'erfolgt_am', 'artikel_id', 'artikel', 'art', 'menge', 'einkaufspreis_eur', 'bemerkung', 'erfasst_von'],
        'Erklaerungen' => ['blatt', 'spalte', 'erklaerung'],
        'Meta'       => ['schluessel', 'wert'],
    ];

    private const BLAETTER = ['Uebersicht', 'Abrechnung', 'Positionen', 'Buchungen', 'Bestand', 'Bewegungen', 'Statistik', 'Erklaerungen', 'Meta'];

    private const META_SCHLUESSEL = [
        'format_version', 'bereich', 'auszaehlung_id', 'zeitraum_von', 'zeitraum_bis', 'erstellt_am', 'erstellt_von',
        'app_version', 'summe_abrechnung_eur', 'anzahl_konten', 'anzahl_buchungen',
    ];

    private string $basis;
    private int $wart;
    private int $max;
    private int $erika;
    private int $couleur;
    private int $helles;
    private int $cola;
    private int $erste;
    private int $zweite;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        $this->resetServices();
        $this->uhrStellen('2026-10-10 12:00:00');

        $this->basis = sys_get_temp_dir() . '/gl-export-' . bin2hex(random_bytes(6)) . '/';

        $this->wart    = $this->personAnlegen(['vorname' => 'Gustav', 'nachname' => 'Wart', 'anzeigename' => 'Gustav Wart']);
        $this->max     = $this->personAnlegen(['vorname' => 'Max', 'nachname' => 'Muster', 'anzeigename' => 'Max Muster', 'gruppe' => 'aktiv']);
        $this->erika   = $this->personAnlegen(['vorname' => 'Erika', 'nachname' => 'Beispiel', 'anzeigename' => 'Erika Beispiel', 'gruppe' => 'ah']);
        $this->couleur = (int) db_connect()->table('personen')->where('anzeigename', 'Couleur')->get()->getRow()->id;

        $this->helles = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150, 'bestand_fuehren' => 1]);
        db_connect()->table('kategorien')->insert(['bereich_id' => $this->bereichId('getraenke'), 'name' => 'Alkoholfrei', 'sortierung' => 2]);
        $alkoholfrei = (int) db_connect()->insertID();
        $this->cola = $this->artikelAnlegen(['name' => 'Cola', 'preis_cent' => 200, 'kategorie_id' => $alkoholfrei, 'bestand_fuehren' => 1]);

        // Erste (Start-)Auszählung: Zeitraum ab Inbetriebnahme inklusiv.
        $this->erste = $this->auszaehlungAnlegen('2026-10-03 00:00:00', 'abgeschlossen', 'getraenke', [
            'art' => 'start', 'zeitraum_von' => '2026-10-01 00:00:00', 'erstellt_von_id' => $this->wart, 'abgeschlossen_at' => '2026-10-03 00:10:00',
        ]);
        $this->position($this->erste, $this->helles, ['ist' => 10, 'soll' => 0, 'differenz' => 10, 'start' => 1]);

        $this->zweite = $this->auszaehlungAnlegen('2026-10-08 10:00:00', 'abgeschlossen', 'getraenke', [
            'zeitraum_von' => '2026-10-03 00:00:00', 'erstellt_von_id' => $this->wart, 'abgeschlossen_at' => '2026-10-08 10:05:00',
        ]);
        $this->position($this->zweite, $this->helles, [
            'anfangsbestand' => 10, 'lieferungen' => 24, 'schwund_erfasst' => -2, 'verkauft' => 4, 'soll' => 28, 'ist' => 25, 'differenz' => -3, 'preis_cent' => 150,
        ]);
        $this->position($this->zweite, $this->cola, ['verkauft' => 1, 'soll' => -1, 'ist' => 0, 'differenz' => 1, 'start' => 1, 'preis_cent' => 200]);

        // Vorzeitraum: genau zur Inbetriebnahme (inklusiv).
        $this->buchung($this->max, $this->helles, 4, 150, '2026-10-01 00:00:00');

        // Zeitraum der zweiten Auszählung.
        $this->buchung($this->max, $this->helles, 1, 150, '2026-10-03 00:00:00');                 // Stichtag der ersten (dort inklusiv) → nicht dabei
        $this->buchung($this->max, $this->helles, 1, 150, '2026-10-04 18:00:00');
        $this->buchung($this->max, $this->helles, 1, 150, '2026-10-04 19:00:00');
        $this->buchung($this->max, $this->helles, 1, 150, '2026-10-04 20:00:00');
        $this->buchung($this->max, $this->helles, -1, 150, '2026-10-05 12:00:00', ['quelle' => 'korrektur', 'bemerkung' => 'Doppelt gebucht']);
        $this->buchung($this->erika, $this->cola, 2, 200, '2026-10-06 12:00:00', ['storniert_at' => '2026-10-06 12:01:00', 'storno_grund' => 'Versehen']);
        $this->buchung($this->couleur, $this->helles, 2, 150, '2026-10-07 21:00:00', ['gebucht_von_id' => $this->max, 'quelle' => 'tablet']);
        $this->buchung($this->erika, $this->cola, 1, 200, '2026-10-08 10:00:00');                 // Stichtag inklusiv
        $this->buchung($this->erika, $this->cola, 5, 200, '2026-10-08 10:00:01');                 // danach → nicht dabei

        $this->bewegung($this->helles, 'lieferung', 24, '2026-10-04 10:00:00', 80, null);
        $this->bewegung($this->helles, 'schwund', -2, '2026-10-05 10:00:00', null, '=SUMME(A1:A2)');
        $this->bewegung($this->helles, 'lieferung', 12, '2026-10-02 10:00:00', 70, null);        // Vorzeitraum
        $this->bewegung($this->helles, 'lieferung', 12, '2026-10-09 10:00:00', 99, null);        // nach dem Stichtag
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basis)) {
            foreach (glob($this->basis . 'exporte/{,.}*', GLOB_BRACE) ?: [] as $datei) {
                if (is_file($datei)) {
                    unlink($datei);
                }
            }

            @rmdir($this->basis . 'exporte');
            @rmdir($this->basis);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $werte
     */
    private function position(int $auszaehlung, int $artikel, array $werte): void
    {
        db_connect()->table('auszaehlung_positionen')->insert(array_merge([
            'auszaehlung_id' => $auszaehlung, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => 0, 'differenz' => 0, 'start' => 0, 'preis_cent' => 150,
        ], $werte));
    }

    /**
     * @param array<string, mixed> $werte
     */
    private function buchung(int $konto, int $artikel, int $menge, int $preis, string $zeit, array $werte = []): int
    {
        db_connect()->table('buchungen')->insert(array_merge([
            'vorgang_id' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFF), random_int(0, 0xFFFFFFFFFF)),
            'konto_id' => $konto, 'artikel_id' => $artikel, 'menge' => $menge, 'einzelpreis_cent' => $preis,
            'quelle' => 'web', 'gebucht_von_id' => $konto === $this->couleur ? null : $konto, 'gebucht_at' => $zeit,
        ], $werte));

        return (int) db_connect()->insertID();
    }

    private function bewegung(int $artikel, string $art, int $menge, string $zeit, ?int $ek, ?string $bemerkung): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => $art, 'menge' => $menge, 'einkaufspreis_cent' => $ek,
            'bemerkung' => $bemerkung, 'person_id' => $this->wart, 'erfolgt_at' => $zeit,
        ]);
    }

    private function export(): AuszaehlungExport
    {
        return new AuszaehlungExport($this->basis);
    }

    private function laden(string $pfad): Spreadsheet
    {
        $voll = $this->basis . $pfad;
        $this->assertFileExists($voll);

        return IOFactory::load($voll);
    }

    /**
     * @return list<array<string, mixed>> Zeilen ab Zeile 2 als Spalte => Wert
     */
    private function datensaetze(Worksheet $blatt): array
    {
        $zeilen = $blatt->toArray(null, false, false, false);
        $kopf   = array_shift($zeilen);

        return array_map(static fn (array $z): array => array_combine($kopf, $z), $zeilen);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Spreadsheet $mappe): array
    {
        return array_column($this->datensaetze($mappe->getSheetByName('Meta')), 'wert', 'schluessel');
    }

    private static function cent(mixed $euro): int
    {
        return (int) round((float) $euro * 100);
    }

    public function test_dateiname_nach_bereich_und_zeitraum_und_datei_liegt_in_exporte(): void
    {
        $pfad = $this->export()->erzeuge($this->zweite);

        $this->assertSame('exporte/Auszaehlung_getraenke_2026-10-03_bis_2026-10-08.xlsx', $pfad);
        $this->assertFileExists($this->basis . $pfad);
        $this->assertSame([], glob($this->basis . 'exporte/*.tmp') ?: [], 'Keine Temp-Datei übrig');
    }

    public function test_dateiname_bekommt_suffix_wenn_andere_auszaehlung_ihn_schon_hat(): void
    {
        db_connect()->table('auszaehlungen')->where('id', $this->erste)
            ->update(['datei_pfad' => 'exporte/Auszaehlung_getraenke_2026-10-03_bis_2026-10-08.xlsx']);

        $pfad = $this->export()->erzeuge($this->zweite);

        $this->assertSame("exporte/Auszaehlung_getraenke_2026-10-03_bis_2026-10-08_{$this->zweite}.xlsx", $pfad);
    }

    public function test_eigener_dateiname_bleibt_beim_neu_erzeugen_gleich(): void
    {
        $pfad = $this->export()->erzeuge($this->zweite);
        db_connect()->table('auszaehlungen')->where('id', $this->zweite)->update(['datei_pfad' => $pfad]);

        $this->assertSame($pfad, $this->export()->erzeuge($this->zweite));
    }

    public function test_blattnamen_und_reihenfolge(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));

        $this->assertSame(self::BLAETTER, $mappe->getSheetNames());
    }

    public function test_kopfzeilen_der_datenblaetter_exakt_nach_spec(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));

        foreach (self::KOPFZEILEN as $blatt => $kopf) {
            $ist = $mappe->getSheetByName($blatt)->rangeToArray('A1:' . $mappe->getSheetByName($blatt)->getHighestColumn() . '1', null, false, false)[0];
            $this->assertSame($kopf, $ist, "Kopfzeile {$blatt}");
        }
    }

    public function test_keine_formeln_und_keine_verbundenen_zellen(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));

        foreach ($mappe->getAllSheets() as $blatt) {
            $this->assertSame([], $blatt->getMergeCells(), 'Verbundene Zellen in ' . $blatt->getTitle());

            foreach ($blatt->getRowIterator() as $zeile) {
                foreach ($zeile->getCellIterator() as $zelle) {
                    $this->assertNotSame(DataType::TYPE_FORMULA, $zelle->getDataType(), $blatt->getTitle() . '!' . $zelle->getCoordinate());
                }
            }
        }

        // Eine Bemerkung, die wie eine Formel aussieht, bleibt Text.
        $bemerkungen = array_column($this->datensaetze($mappe->getSheetByName('Bewegungen')), 'bemerkung');
        $this->assertContains('=SUMME(A1:A2)', $bemerkungen);
    }

    public function test_datenblaetter_haben_tabelle_mit_filter(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));

        foreach (array_keys(self::KOPFZEILEN) as $blatt) {
            $tabellen = $mappe->getSheetByName($blatt)->getTableCollection();
            $this->assertCount(1, $tabellen, "Tabelle in {$blatt}");
            $this->assertStringStartsWith('A1:', $tabellen[0]->getRange());
        }
    }

    public function test_meta_werte(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));
        $meta  = $this->meta($mappe);

        $this->assertSame(self::META_SCHLUESSEL, array_keys($meta));
        $this->assertSame('getraenkeliste-auszaehlung/1', $meta['format_version']);
        $this->assertSame('getraenke', $meta['bereich']);
        $this->assertEquals($this->zweite, $meta['auszaehlung_id']);
        $zeilen = array_flip(array_keys($meta));
        foreach (['zeitraum_von' => '2026-10-03 00:00:00', 'zeitraum_bis' => '2026-10-08 10:00:00', 'erstellt_am' => '2026-10-08 10:05:00'] as $schluessel => $zeit) {
            $zelle = $mappe->getSheetByName('Meta')->getCell('B' . ($zeilen[$schluessel] + 2));
            $this->assertSame(DataType::TYPE_NUMERIC, $zelle->getDataType(), $schluessel);
            $this->assertSame('yyyy-mm-dd hh:mm', $zelle->getStyle()->getNumberFormat()->getFormatCode(), $schluessel);
            $this->assertEqualsWithDelta(Date::PHPToExcel(new DateTimeImmutable($zeit, new DateTimeZone('Europe/Berlin'))), $zelle->getValue(), 0.00001, $schluessel);
        }
        $this->assertSame('Gustav Wart', $meta['erstellt_von']);
        $this->assertSame(GETRAENKELISTE_VERSION, $meta['app_version']);
        $this->assertSame(800, self::cent($meta['summe_abrechnung_eur']));
        $this->assertEquals(3, $meta['anzahl_konten']);
        $this->assertEquals(7, $meta['anzahl_buchungen']);
    }

    public function test_kontrollsumme_entspricht_der_abrechnung_mit_korrektur_und_storno(): void
    {
        $mappe      = $this->laden($this->export()->erzeuge($this->zweite));
        $abrechnung = array_column($this->datensaetze($mappe->getSheetByName('Abrechnung')), null, 'konto_id');

        $summe = 0;
        foreach ($abrechnung as $zeile) {
            $summe += self::cent($zeile['betrag_eur']);
        }
        $this->assertSame(self::cent($this->meta($mappe)['summe_abrechnung_eur']), $summe);

        // 3 × 1,50 − 1,50 (Korrektur) = 3,00
        $this->assertSame(300, self::cent($abrechnung[$this->max]['betrag_eur']));
        $this->assertEquals(2, $abrechnung[$this->max]['anzahl_artikel']);
        $this->assertSame('mitglied', $abrechnung[$this->max]['konto_typ']);
        $this->assertSame('Muster', $abrechnung[$this->max]['nachname']);
        // Stornierte Cola zählt nicht, die am Stichtag schon.
        $this->assertSame(200, self::cent($abrechnung[$this->erika]['betrag_eur']));
        $this->assertSame('ah', $abrechnung[$this->erika]['gruppe']);
        $this->assertSame('sammelkonto', $abrechnung[$this->couleur]['konto_typ']);
        $this->assertSame(300, self::cent($abrechnung[$this->couleur]['betrag_eur']));
    }

    public function test_konto_nur_mit_stornierten_buchungen_fehlt_in_der_abrechnung(): void
    {
        $nurStorno = $this->personAnlegen(['anzeigename' => 'Nur Storno']);
        $this->buchung($nurStorno, $this->helles, 1, 150, '2026-10-06 12:00:00', ['storniert_at' => '2026-10-06 12:01:00']);

        $mappe = $this->laden($this->export()->erzeuge($this->zweite));

        $this->assertNotContains($nurStorno, array_map('intval', array_column($this->datensaetze($mappe->getSheetByName('Abrechnung')), 'konto_id')));
        $this->assertContains($nurStorno, array_map('intval', array_column($this->datensaetze($mappe->getSheetByName('Buchungen')), 'konto_id')));
    }

    public function test_buchungen_enthalten_stornierte_mit_eins_und_nur_den_zeitraum(): void
    {
        $mappe     = $this->laden($this->export()->erzeuge($this->zweite));
        $buchungen = $this->datensaetze($mappe->getSheetByName('Buchungen'));

        $this->assertCount(7, $buchungen);

        $storniert = array_values(array_filter($buchungen, static fn (array $b): bool => (int) $b['storniert'] === 1));
        $this->assertCount(1, $storniert);
        $this->assertSame('Versehen', $storniert[0]['storno_grund']);
        $this->assertEquals(2, $storniert[0]['menge']);

        $korrektur = array_values(array_filter($buchungen, static fn (array $b): bool => $b['quelle'] === 'korrektur'));
        $this->assertEquals(-1, $korrektur[0]['menge']);
        $this->assertSame(-150, self::cent($korrektur[0]['summe_eur']));

        $couleur = array_values(array_filter($buchungen, fn (array $b): bool => (int) $b['konto_id'] === $this->couleur));
        $this->assertSame('Max Muster', $couleur[0]['gebucht_von']);
        $this->assertSame('Couleur', $couleur[0]['anzeigename']);
    }

    public function test_datumszelle_ist_excel_datum_mit_format(): void
    {
        $mappe = $this->laden($this->export()->erzeuge($this->zweite));
        $blatt = $mappe->getSheetByName('Buchungen');
        $zelle = $blatt->getCell('C2');

        $this->assertSame(DataType::TYPE_NUMERIC, $zelle->getDataType());
        $this->assertSame('yyyy-mm-dd hh:mm', $zelle->getStyle()->getNumberFormat()->getFormatCode());
        $erwartet = Date::PHPToExcel(new DateTimeImmutable('2026-10-04 18:00:00', new DateTimeZone('Europe/Berlin')));
        $this->assertEqualsWithDelta($erwartet, $zelle->getValue(), 0.00001);

        $betrag = $blatt->getCell('J2');
        $this->assertSame(DataType::TYPE_NUMERIC, $betrag->getDataType());
        $this->assertSame('0.00', $betrag->getStyle()->getNumberFormat()->getFormatCode());

        $bewegung = $mappe->getSheetByName('Bewegungen')->getCell('B2');
        $this->assertSame(DataType::TYPE_NUMERIC, $bewegung->getDataType());
        $this->assertSame('yyyy-mm-dd hh:mm', $bewegung->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_positionen_je_konto_artikel_preis(): void
    {
        $mappe      = $this->laden($this->export()->erzeuge($this->zweite));
        $positionen = $this->datensaetze($mappe->getSheetByName('Positionen'));

        $max = array_values(array_filter($positionen, fn (array $p): bool => (int) $p['konto_id'] === $this->max));
        $this->assertCount(1, $max);
        $this->assertEquals(2, $max[0]['menge']);
        $this->assertSame(150, self::cent($max[0]['einzelpreis_eur']));
        $this->assertSame(300, self::cent($max[0]['summe_eur']));
        $this->assertSame('Bier', $max[0]['kategorie']);

        $summe = array_sum(array_map(static fn (array $p): int => self::cent($p['summe_eur']), $positionen));
        $this->assertSame(800, $summe);
    }

    public function test_bestand_aus_positionen_mit_einkaufswert(): void
    {
        $mappe   = $this->laden($this->export()->erzeuge($this->zweite));
        $bestand = array_column($this->datensaetze($mappe->getSheetByName('Bestand')), null, 'artikel_id');

        $helles = $bestand[$this->helles];
        $this->assertEquals(28, $helles['soll']);
        $this->assertEquals(25, $helles['ist']);
        $this->assertEquals(-3, $helles['differenz']);
        $this->assertSame(-450, self::cent($helles['differenz_eur']));
        $this->assertSame(150, self::cent($helles['verkaufspreis_eur']));
        $this->assertSame(2000, self::cent($helles['einkaufswert_eur'])); // 25 × 0,80 (letzte Lieferung bis Stichtag)
        $this->assertSame('Bier', $helles['kategorie']);

        $this->assertNull($bestand[$this->cola]['einkaufswert_eur']); // kein Einkaufspreis bekannt
    }

    public function test_bewegungen_nur_im_zeitraum(): void
    {
        $mappe      = $this->laden($this->export()->erzeuge($this->zweite));
        $bewegungen = $this->datensaetze($mappe->getSheetByName('Bewegungen'));

        $this->assertCount(2, $bewegungen);
        $this->assertSame('lieferung', $bewegungen[0]['art']);
        $this->assertEquals(24, $bewegungen[0]['menge']);
        $this->assertSame(80, self::cent($bewegungen[0]['einkaufspreis_eur']));
        $this->assertSame('Gustav Wart', $bewegungen[0]['erfasst_von']);
        $this->assertEquals(-2, $bewegungen[1]['menge']);
        $this->assertNull($bewegungen[1]['einkaufspreis_eur']);
    }

    public function test_erster_zeitraum_beginnt_inklusiv_bei_der_inbetriebnahme(): void
    {
        $mappe     = $this->laden($this->export()->erzeuge($this->erste));
        $buchungen = $this->datensaetze($mappe->getSheetByName('Buchungen'));

        // 4 × 1,50 genau zur Inbetriebnahme (inklusiv) + 1,50 genau am Stichtag (inklusiv)
        $this->assertCount(2, $buchungen);
        $this->assertSame(750, self::cent($this->meta($mappe)['summe_abrechnung_eur']));
        $this->assertCount(1, $this->datensaetze($mappe->getSheetByName('Bewegungen')));
    }

    public function test_jede_spalte_jedes_datenblatts_hat_eine_erklaerung(): void
    {
        $mappe        = $this->laden($this->export()->erzeuge($this->zweite));
        $erklaerungen = $this->datensaetze($mappe->getSheetByName('Erklaerungen'));

        foreach (self::KOPFZEILEN as $blatt => $kopf) {
            foreach ($kopf as $spalte) {
                $treffer = array_filter($erklaerungen, static fn (array $e): bool => $e['blatt'] === $blatt && $e['spalte'] === $spalte);
                $this->assertCount(1, $treffer, "Erklärung für {$blatt}.{$spalte}");
                $this->assertNotSame('', trim((string) array_values($treffer)[0]['erklaerung']));
            }
        }

        foreach (self::BLAETTER as $blatt) {
            $this->assertNotEmpty(array_filter($erklaerungen, static fn (array $e): bool => $e['blatt'] === $blatt && $e['spalte'] === null), "Blatt-Erklärung {$blatt}");
        }

        $blattText = array_column(array_filter($erklaerungen, static fn (array $e): bool => $e['spalte'] === null), 'erklaerung', 'blatt');
        $this->assertStringContainsString('0 oder negativ', $blattText['Abrechnung']);
        foreach (['Abrechnung', 'Positionen', 'Buchungen', 'Bestand'] as $blatt) {
            $this->assertStringContainsString('Stammdaten beim Erzeugen', $blattText[$blatt], $blatt);
        }
    }

    public function test_zweites_erzeugen_liefert_identische_zellwerte(): void
    {
        $export = $this->export();
        $pfad   = $export->erzeuge($this->zweite);
        $erste  = $this->alleWerte($this->laden($pfad));

        $this->uhrStellen('2026-11-01 08:00:00');
        $this->assertSame($pfad, $export->erzeuge($this->zweite));
        $zweite = $this->alleWerte($this->laden($pfad));

        $this->assertSame($erste, $zweite);
    }

    public function test_uebersicht_und_statistik_mit_diagramm(): void
    {
        $pfad   = $this->export()->erzeuge($this->zweite);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $mappe = $reader->load($this->basis . $pfad);

        $uebersicht = $this->textInhalt($mappe->getSheetByName('Uebersicht'));
        $this->assertStringContainsString('03.10.2026 00:00 – 08.10.2026 10:00', $uebersicht);
        $this->assertStringContainsString('Schwund', $uebersicht);
        $this->assertStringContainsString('Couleur', $uebersicht);
        $this->assertStringContainsString('Bund', $uebersicht);
        $this->assertStringContainsString('Cola: Soll ist negativ', $uebersicht);

        $statistik = $mappe->getSheetByName('Statistik');
        $text      = $this->textInhalt($statistik);
        foreach (['Umsatz je Kategorie', 'Umsatz je Kalenderwoche', 'Umsatz je Wochentag', 'Vergleich zum Vorzeitraum', '2026-W41', 'Mo', 'So'] as $erwartet) {
            $this->assertStringContainsString($erwartet, $text);
        }
        $this->assertCount(1, $statistik->getChartCollection());

        // Vergleich: dieser Zeitraum 8,00 €, Vorzeitraum 7,50 €, Differenz 0,50 €
        $umsatz = null;
        foreach ($statistik->toArray(null, false, false, false) as $zeile) {
            if ($zeile[0] === 'Umsatz (€)') {
                $umsatz = $zeile;
            }
        }
        $this->assertNotNull($umsatz);
        $this->assertSame([800, 750, 50], [self::cent($umsatz[1]), self::cent($umsatz[2]), self::cent($umsatz[3])]);
    }

    public function test_leere_auszaehlung_schreibt_kopfzeilen_mit_filter(): void
    {
        $leer = $this->auszaehlungAnlegen('2026-10-09 00:00:00', 'abgeschlossen', 'getraenke', [
            'zeitraum_von' => '2026-10-08 23:00:00', 'erstellt_von_id' => $this->wart,
        ]);

        $mappe = $this->laden($this->export()->erzeuge($leer));
        $blatt = $mappe->getSheetByName('Abrechnung');

        $this->assertSame(self::KOPFZEILEN['Abrechnung'], $blatt->rangeToArray('A1:H1', null, false, false)[0]);

        foreach (['Abrechnung', 'Positionen', 'Buchungen', 'Bestand', 'Bewegungen'] as $name) {
            $blatt  = $mappe->getSheetByName($name);
            $letzte = $blatt->getHighestColumn();
            $this->assertSame(1, $blatt->getHighestDataRow(), $name);
            $this->assertSame([], $blatt->getTableCollection()->getArrayCopy(), $name);
            $this->assertSame("A1:{$letzte}1", $blatt->getAutoFilter()->getRange(), $name);
            $this->assertSame(count(self::KOPFZEILEN[$name]), \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($letzte), $name);
        }
        $this->assertSame(0, self::cent($this->meta($mappe)['summe_abrechnung_eur']));
    }

    public function test_entwurf_wird_nicht_exportiert(): void
    {
        $entwurf = $this->auszaehlungAnlegen('2026-10-09 00:00:00', 'entwurf', 'getraenke', ['erstellt_von_id' => $this->wart]);

        $this->expectException(\RuntimeException::class);
        $this->export()->erzeuge($entwurf);
    }

    public function test_abgeschlossen_ohne_abschlusszeitpunkt_wird_abgelehnt(): void
    {
        db_connect()->table('auszaehlungen')->where('id', $this->zweite)->update(['abgeschlossen_at' => null]);

        $this->expectException(\RuntimeException::class);
        $this->export()->erzeuge($this->zweite);
    }

    public function test_nicht_schreibbares_ziel_wirft_ohne_teildatei(): void
    {
        mkdir($this->basis, 0777, true);
        file_put_contents($this->basis . 'exporte', 'kein Verzeichnis');

        try {
            $this->export()->erzeuge($this->zweite);
            $this->fail('Ausnahme erwartet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Exportverzeichnis', $e->getMessage());
        } finally {
            unlink($this->basis . 'exporte');
        }
    }

    public function test_service_ist_registriert(): void
    {
        $this->assertInstanceOf(AuszaehlungExport::class, service('auszaehlungExport'));
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function alleWerte(Spreadsheet $mappe): array
    {
        $werte = [];
        foreach ($mappe->getAllSheets() as $blatt) {
            $werte[$blatt->getTitle()] = $blatt->toArray(null, false, false, false);
        }

        return $werte;
    }

    private function textInhalt(Worksheet $blatt): string
    {
        $text = '';
        foreach ($blatt->toArray(null, false, false, false) as $zeile) {
            $text .= implode(' | ', array_map(static fn ($w): string => (string) $w, $zeile)) . "\n";
        }

        return $text;
    }
}
