<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuszaehlungPositionModel;
use App\Models\PersonModel;
use CodeIgniter\Database\BaseBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Excel-Export einer abgeschlossenen Auszählung (Spec 8.1/8.2, `format_version` getraenkeliste-auszaehlung/1).
 *
 * Liest nur gespeicherte Daten; zweimaliges Erzeugen liefert dieselben Zellwerte. Zeitraum = (zeitraum_von, stichtag],
 * `zeitraum_von` inklusiv nur, wenn es keine frühere abgeschlossene Auszählung des Bereichs gibt (Inbetriebnahme, wie
 * `Zeitraeume`). Datenblätter: Kopfzeile + Excel-Tabelle mit Filter; ohne Datenzeilen nur ein AutoFilter auf der
 * Kopfzeile (eine Excel-Tabelle braucht mindestens eine Datenzeile). Alle Zellen werden mit explizitem Typ geschrieben,
 * damit Text wie „=…“ nie zur Formel wird. Geschrieben wird erst in eine Temp-Datei, dann per `rename` ersetzt.
 */
class AuszaehlungExport
{
    public const FORMAT_VERSION = 'getraenkeliste-auszaehlung/1';

    private const FORMAT_ZEIT  = 'yyyy-mm-dd hh:mm';
    private const FORMAT_EUR   = '0.00';
    private const FORMAT_GANZ  = '0';
    private const WOCHENTAGE   = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    /** Spalten der Datenblätter (Spec 8.2) mit Zellformat (`text`, `ganz`, `eur`, `zeit`) und Erklärung. */
    private const SPALTEN = [
        'Abrechnung' => [
            'konto_id'       => ['ganz', 'Stabile ID des Kontos (Person oder Sammelkonto).'],
            'konto_typ'      => ['text', 'mitglied für Personen, sammelkonto für Couleur und Bund.'],
            'vorname'        => ['text', 'Vorname der Person (bei Sammelkonten leer).'],
            'nachname'       => ['text', 'Nachname der Person (bei Sammelkonten leer).'],
            'anzeigename'    => ['text', 'Name, wie er in der App angezeigt wird.'],
            'gruppe'         => ['text', 'Gruppe der Person: aktiv, ah oder sonstige.'],
            'anzahl_artikel' => ['ganz', 'Summe der gebuchten Mengen ohne stornierte Buchungen, Korrekturbuchungen mit Vorzeichen.'],
            'betrag_eur'     => ['eur', 'Verbrauch des Kontos im Zeitraum in Euro (Summe Menge × Einzelpreis ohne stornierte Buchungen).'],
        ],
        'Positionen' => [
            'konto_id'        => ['ganz', 'Stabile ID des Kontos.'],
            'anzeigename'     => ['text', 'Name des Kontos.'],
            'artikel_id'      => ['ganz', 'Stabile ID des Artikels.'],
            'artikel'         => ['text', 'Name des Artikels.'],
            'kategorie'       => ['text', 'Kategorie des Artikels.'],
            'menge'           => ['ganz', 'Summe der Mengen dieses Kontos für Artikel und Preis ohne stornierte Buchungen.'],
            'einzelpreis_eur' => ['eur', 'Einzelpreis zum Buchungszeitpunkt in Euro.'],
            'summe_eur'       => ['eur', 'Menge × Einzelpreis in Euro.'],
        ],
        'Buchungen' => [
            'buchung_id'      => ['ganz', 'Stabile ID der Buchung.'],
            'vorgang_id'      => ['text', 'ID des Buchungsvorgangs (gemeinsamer Warenkorb).'],
            'gebucht_am'      => ['zeit', 'Zeitpunkt der Buchung.'],
            'konto_id'        => ['ganz', 'Stabile ID des belasteten Kontos.'],
            'anzeigename'     => ['text', 'Name des belasteten Kontos.'],
            'artikel_id'      => ['ganz', 'Stabile ID des Artikels.'],
            'artikel'         => ['text', 'Name des Artikels.'],
            'menge'           => ['ganz', 'Gebuchte Menge (bei Korrekturbuchungen auch negativ).'],
            'einzelpreis_eur' => ['eur', 'Einzelpreis zum Buchungszeitpunkt in Euro.'],
            'summe_eur'       => ['eur', 'Menge × Einzelpreis in Euro (auch bei stornierten Buchungen angegeben).'],
            'quelle'          => ['text', 'Woher die Buchung kommt: tablet, web oder korrektur.'],
            'gebucht_von'     => ['text', 'Wer gebucht hat (leer bei Buchungen am Tablet auf ein Sammelkonto).'],
            'storniert'       => ['ganz', '1 = storniert (zählt nicht zur Abrechnung), 0 = gültig.'],
            'storno_grund'    => ['text', 'Grund des Stornos, falls angegeben.'],
        ],
        'Bestand' => [
            'artikel_id'        => ['ganz', 'Stabile ID des Artikels.'],
            'artikel'           => ['text', 'Name des Artikels.'],
            'kategorie'         => ['text', 'Kategorie des Artikels.'],
            'anfangsbestand'    => ['ganz', 'Ist-Bestand der vorherigen Auszählung (0 beim ersten Mal).'],
            'lieferungen'       => ['ganz', 'Summe der Lieferungen im Zeitraum.'],
            'schwund_erfasst'   => ['ganz', 'Vom Wart erfasster Schwund im Zeitraum (negativ).'],
            'korrekturen'       => ['ganz', 'Summe der Bestandskorrekturen im Zeitraum (mit Vorzeichen).'],
            'verkauft'          => ['ganz', 'Summe der nicht stornierten Buchungsmengen im Zeitraum.'],
            'soll'              => ['ganz', 'Rechnerischer Bestand am Stichtag: Anfangsbestand + Lieferungen + Schwund + Korrekturen − verkauft.'],
            'ist'               => ['ganz', 'Gezählter Bestand am Stichtag.'],
            'differenz'         => ['ganz', 'Ist − Soll; negativ bedeutet Fehlmenge.'],
            'differenz_eur'     => ['eur', 'Differenz × Verkaufspreis in Euro.'],
            'verkaufspreis_eur' => ['eur', 'Verkaufspreis zum Zeitpunkt der Auszählung in Euro.'],
            'einkaufswert_eur'  => ['eur', 'Ist × zuletzt bekannter Einkaufspreis je Stück (letzte Lieferung mit Preis bis zum Stichtag); leer, wenn unbekannt.'],
        ],
        'Bewegungen' => [
            'bewegung_id'       => ['ganz', 'Stabile ID der Bestandsbewegung.'],
            'erfolgt_am'        => ['zeit', 'Zeitpunkt der Bewegung.'],
            'artikel_id'        => ['ganz', 'Stabile ID des Artikels.'],
            'artikel'           => ['text', 'Name des Artikels.'],
            'art'               => ['text', 'lieferung, schwund oder korrektur.'],
            'menge'             => ['ganz', 'Menge in Stück; Lieferung positiv, Schwund negativ, Korrektur mit Vorzeichen.'],
            'einkaufspreis_eur' => ['eur', 'Einkaufspreis je Stück in Euro (nur bei Lieferungen, falls angegeben).'],
            'bemerkung'         => ['text', 'Bemerkung des Warts.'],
            'erfasst_von'       => ['text', 'Wer die Bewegung erfasst hat.'],
        ],
        'Erklaerungen' => [
            'blatt'      => ['text', 'Name des Blatts.'],
            'spalte'     => ['text', 'Technischer Spaltenname; leer bei der Erklärung des ganzen Blatts.'],
            'erklaerung' => ['text', 'Erklärung in einem Satz.'],
        ],
        'Meta' => [
            'schluessel' => ['text', 'Name der Angabe.'],
            'wert'       => ['text', 'Wert der Angabe.'],
        ],
    ];

    private const STAMMDATEN_HINWEIS = 'Namen, Gruppe und Kategorie zeigen die Stammdaten beim Erzeugen der Datei; IDs, Mengen und Beträge sind mit dem Abschluss eingefroren.';

    private const BLATT_ERKLAERUNGEN = [
        'Uebersicht'   => 'Zusammenfassung für Menschen: Zeitraum, Umsatz, Summen, Schwund, Top-Artikel und Hinweise (nicht für den Import).',
        'Abrechnung'   => 'Eine Zeile je Konto mit nicht stornierten Buchungen im Zeitraum; Hauptquelle für den Import ins Kassensystem; betrag_eur kann durch Korrekturbuchungen 0 oder negativ sein. ' . self::STAMMDATEN_HINWEIS,
        'Positionen'   => 'Verbrauch je Konto, Artikel und Einzelpreis ohne stornierte Buchungen. ' . self::STAMMDATEN_HINWEIS,
        'Buchungen'    => 'Alle Buchungen des Zeitraums einschließlich stornierter. ' . self::STAMMDATEN_HINWEIS,
        'Bestand'      => 'Ergebnis der Auszählung je Artikel. ' . self::STAMMDATEN_HINWEIS,
        'Bewegungen'   => 'Lieferungen, Schwund und Bestandskorrekturen im Zeitraum.',
        'Statistik'    => 'Umsatz je Kategorie, Kalenderwoche und Wochentag sowie Vergleich zum Vorzeitraum, mit Diagramm (nicht für den Import).',
        'Erklaerungen' => 'Erklärt jedes Blatt und jede Spalte der Datenblätter.',
        'Meta'         => 'Angaben zur Datei: format_version, bereich, auszaehlung_id, zeitraum_von, zeitraum_bis und erstellt_am (= Abschluss) als Excel-Datum JJJJ-MM-TT hh:mm, erstellt_von, app_version, summe_abrechnung_eur (= Summe Abrechnung.betrag_eur), anzahl_konten (Zeilen in Abrechnung), anzahl_buchungen (Zeilen in Buchungen, inkl. stornierter).',
    ];

    private string $basis;

    /**
     * @param string|null $basis Verzeichnis, unter dem `exporte/` liegt (Standard: WRITEPATH)
     */
    public function __construct(?string $basis = null)
    {
        $this->basis = rtrim($basis ?? WRITEPATH, '/\\') . '/';
    }

    /**
     * Schreibt die Excel-Datei der Auszählung und gibt den Pfad relativ zur Basis zurück (`exporte/…`).
     *
     * @throws RuntimeException wenn die Auszählung fehlt, nicht abgeschlossen ist oder die Datei nicht geschrieben werden kann
     */
    public function erzeuge(int $auszaehlungId): string
    {
        $auszaehlung = db_connect()->table('auszaehlungen au')
            ->select('au.*, b.schluessel AS bereich_schluessel, b.name AS bereich_name, p.anzeigename AS erstellt_von_name')
            ->join('bereiche b', 'b.id = au.bereich_id')
            ->join('personen p', 'p.id = au.erstellt_von_id')
            ->where('au.id', $auszaehlungId)
            ->get()->getRowArray();

        if ($auszaehlung === null || $auszaehlung['status'] !== 'abgeschlossen' || $auszaehlung['abgeschlossen_at'] === null) {
            throw new RuntimeException("Auszählung {$auszaehlungId} ist nicht abgeschlossen oder existiert nicht.");
        }

        $zeitraum = $this->zeitraum($auszaehlung);
        $mappe    = $this->mappe($auszaehlung, $zeitraum);
        $relativ  = 'exporte/' . $this->dateiname($auszaehlung, $zeitraum);

        $this->speichere($mappe, $this->basis . $relativ);

        return $relativ;
    }

    /**
     * Absoluter Pfad einer gespeicherten Exportdatei (`datei_pfad` aus der DB) oder null, wenn sie fehlt oder nicht
     * innerhalb von `<basis>/exporte/` liegt (`realpath`-Prüfung gegen `..` und Symlinks).
     */
    public function datei(string $relativ): ?string
    {
        $verzeichnis = realpath($this->basis . 'exporte');
        $datei       = realpath($this->basis . $relativ);

        if ($verzeichnis === false || $datei === false || ! is_file($datei)) {
            return null;
        }

        return str_starts_with($datei, $verzeichnis . DIRECTORY_SEPARATOR) ? $datei : null;
    }

    /**
     * @param array<string, mixed> $auszaehlung
     *
     * @return array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool, vorherige: ?array<string, mixed>}
     */
    private function zeitraum(array $auszaehlung): array
    {
        $vorherige = db_connect()->table('auszaehlungen')
            ->where('bereich_id', $auszaehlung['bereich_id'])->where('status', 'abgeschlossen')
            ->where('id !=', $auszaehlung['id'])->where('stichtag <', $auszaehlung['stichtag'])
            ->orderBy('stichtag', 'DESC')->orderBy('id', 'DESC')->limit(1)
            ->get()->getRowArray();

        return [
            'von'          => self::zeit((string) $auszaehlung['zeitraum_von']),
            'bis'          => self::zeit((string) $auszaehlung['stichtag']),
            'von_inklusiv' => $vorherige === null,
            'vorherige'    => $vorherige,
        ];
    }

    /**
     * Entscheidung 11: `Auszaehlung_<bereich>_<von>_bis_<bis>.xlsx`, Suffix `_<id>`, wenn eine andere Auszählung den Namen hat.
     *
     * @param array<string, mixed>                                      $auszaehlung
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable} $zeitraum
     */
    private function dateiname(array $auszaehlung, array $zeitraum): string
    {
        $stamm = sprintf('Auszaehlung_%s_%s_bis_%s', $auszaehlung['bereich_schluessel'], $zeitraum['von']->format('Y-m-d'), $zeitraum['bis']->format('Y-m-d'));
        $belegt = db_connect()->table('auszaehlungen')
            ->where('datei_pfad', 'exporte/' . $stamm . '.xlsx')->where('id !=', $auszaehlung['id'])
            ->countAllResults() > 0;

        return $stamm . ($belegt ? '_' . $auszaehlung['id'] : '') . '.xlsx';
    }

    private function speichere(Spreadsheet $mappe, string $ziel): void
    {
        $verzeichnis = dirname($ziel);

        if (! is_dir($verzeichnis) && ! @mkdir($verzeichnis, 0775, true) && ! is_dir($verzeichnis)) {
            throw new RuntimeException("Exportverzeichnis {$verzeichnis} konnte nicht angelegt werden.");
        }

        $temp = $verzeichnis . '/.' . basename($ziel) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            $writer = new Xlsx($mappe);
            $writer->setIncludeCharts(true);
            $writer->setPreCalculateFormulas(false);
            $writer->save($temp);

            if (! rename($temp, $ziel)) {
                throw new RuntimeException("Exportdatei {$ziel} konnte nicht geschrieben werden.");
            }
        } catch (Throwable $e) {
            if (is_file($temp)) {
                unlink($temp);
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Excel-Export fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        } finally {
            $mappe->disconnectWorksheets();
        }
    }

    /**
     * @param array<string, mixed>                                                                                $auszaehlung
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool, vorherige: ?array<string, mixed>} $zeitraum
     */
    private function mappe(array $auszaehlung, array $zeitraum): Spreadsheet
    {
        $bereichId  = (int) $auszaehlung['bereich_id'];
        $abrechnung = $this->abrechnung($bereichId, $zeitraum);
        $buchungen  = $this->buchungen($bereichId, $zeitraum);
        $positionen = (new AuszaehlungPositionModel())->fuer((int) $auszaehlung['id']);
        $summeCent  = array_sum(array_map(static fn (array $z): int => (int) $z['cent'], $abrechnung));

        $mappe = new Spreadsheet();
        $mappe->removeSheetByIndex(0);
        $abgeschlossen = self::zeit((string) $auszaehlung['abgeschlossen_at']);
        $mappe->getProperties()
            ->setCreator('Getränkeliste VDSt')->setLastModifiedBy('Getränkeliste VDSt')
            ->setTitle('Auszählung ' . $auszaehlung['bereich_name'])
            ->setCreated($abgeschlossen->getTimestamp())->setModified($abgeschlossen->getTimestamp());

        $this->uebersicht($mappe->createSheet(), $auszaehlung, $zeitraum, $abrechnung, $buchungen, $positionen, $summeCent);

        $this->datenblatt($mappe, 'Abrechnung', array_map(static fn (array $z): array => [
            (int) $z['id'], $z['typ'], $z['vorname'], $z['nachname'], $z['anzeigename'], $z['gruppe'], (int) $z['anzahl'], self::euro((int) $z['cent']),
        ], $abrechnung));

        $this->datenblatt($mappe, 'Positionen', array_map(static fn (array $z): array => [
            (int) $z['konto_id'], $z['anzeigename'], (int) $z['artikel_id'], $z['artikel'], $z['kategorie'], (int) $z['menge'],
            self::euro((int) $z['einzelpreis_cent']), self::euro((int) $z['cent']),
        ], $this->positionen($bereichId, $zeitraum)));

        $this->datenblatt($mappe, 'Buchungen', array_map(static fn (array $b): array => [
            (int) $b['id'], $b['vorgang_id'], self::zeit($b['gebucht_at']), (int) $b['konto_id'], $b['konto_name'], (int) $b['artikel_id'],
            $b['artikel_name'], (int) $b['menge'], self::euro((int) $b['einzelpreis_cent']), self::euro((int) $b['menge'] * (int) $b['einzelpreis_cent']),
            $b['quelle'], $b['gebucht_von_name'], $b['storniert_at'] === null ? 0 : 1, $b['storno_grund'],
        ], $buchungen));

        $einkauf = $this->einkaufspreise(array_map(static fn (array $p): int => (int) $p['artikel_id'], $positionen), $zeitraum['bis']);
        $this->datenblatt($mappe, 'Bestand', array_map(static function (array $p) use ($einkauf): array {
            $ist = $p['ist'] === null ? null : (int) $p['ist'];
            $ek  = $einkauf[(int) $p['artikel_id']] ?? null;

            return [
                (int) $p['artikel_id'], $p['artikel_name'], $p['kategorie_name'], (int) $p['anfangsbestand'], (int) $p['lieferungen'],
                (int) $p['schwund_erfasst'], (int) $p['korrekturen'], (int) $p['verkauft'], (int) $p['soll'], $ist,
                $ist === null ? null : (int) $p['differenz'], $ist === null ? null : self::euro((int) $p['differenz'] * (int) $p['preis_cent']),
                self::euro((int) $p['preis_cent']), $ist === null || $ek === null ? null : self::euro($ist * $ek),
            ];
        }, $positionen));

        $this->datenblatt($mappe, 'Bewegungen', array_map(static fn (array $m): array => [
            (int) $m['id'], self::zeit($m['erfolgt_at']), (int) $m['artikel_id'], $m['artikel_name'], $m['art'], (int) $m['menge'],
            $m['einkaufspreis_cent'] === null ? null : self::euro((int) $m['einkaufspreis_cent']), $m['bemerkung'], $m['person_name'],
        ], $this->bewegungen($bereichId, $zeitraum)));

        $this->statistik($mappe->createSheet(), $buchungen, $zeitraum, $summeCent, $bereichId);

        $erklaerungen = [];
        foreach (array_keys(self::BLATT_ERKLAERUNGEN) as $blatt) {
            $erklaerungen[] = [$blatt, null, self::BLATT_ERKLAERUNGEN[$blatt]];

            foreach (self::SPALTEN[$blatt] ?? [] as $spalte => [, $text]) {
                $erklaerungen[] = [$blatt, $spalte, $text];
            }
        }
        $this->datenblatt($mappe, 'Erklaerungen', $erklaerungen);

        $this->datenblatt($mappe, 'Meta', [
            ['format_version', self::FORMAT_VERSION],
            ['bereich', $auszaehlung['bereich_schluessel']],
            ['auszaehlung_id', (int) $auszaehlung['id']],
            ['zeitraum_von', $zeitraum['von']],
            ['zeitraum_bis', $zeitraum['bis']],
            ['erstellt_am', $abgeschlossen],
            ['erstellt_von', $auszaehlung['erstellt_von_name']],
            ['app_version', GETRAENKELISTE_VERSION],
            ['summe_abrechnung_eur', self::euro($summeCent)],
            ['anzahl_konten', count($abrechnung)],
            ['anzahl_buchungen', count($buchungen)],
        ], [
            'zeitraum_von' => self::FORMAT_ZEIT, 'zeitraum_bis' => self::FORMAT_ZEIT, 'erstellt_am' => self::FORMAT_ZEIT,
            'summe_abrechnung_eur' => self::FORMAT_EUR,
        ]);

        $mappe->setActiveSheetIndex(0);

        return $mappe;
    }

    /**
     * Datenblatt nach 8.1: Kopfzeile, Werte mit explizitem Typ, Zahlenformate je Spalte, Tabelle mit Filter.
     *
     * @param list<list<mixed>>     $zeilen
     * @param array<string, string> $metaFormate Meta: Zahlenformat je Schlüssel (Spalte `wert`)
     */
    private function datenblatt(Spreadsheet $mappe, string $name, array $zeilen, array $metaFormate = []): void
    {
        $blatt   = $mappe->createSheet();
        $blatt->setTitle($name);
        $spalten = self::SPALTEN[$name];
        $letzte  = Coordinate::stringFromColumnIndex(count($spalten));

        $this->zeile($blatt, 1, array_keys($spalten));
        $blatt->getStyle("A1:{$letzte}1")->getFont()->setBold(true);

        foreach ($zeilen as $i => $werte) {
            $this->zeile($blatt, $i + 2, $werte);

            if (isset($metaFormate[$werte[0]])) {
                $blatt->getStyle('B' . ($i + 2))->getNumberFormat()->setFormatCode($metaFormate[$werte[0]]);
            }
        }

        $ende = count($zeilen) + 1;
        $nr   = 0;
        foreach ($spalten as [$format]) {
            $buchstabe = Coordinate::stringFromColumnIndex(++$nr);
            $code      = ['zeit' => self::FORMAT_ZEIT, 'eur' => self::FORMAT_EUR, 'ganz' => self::FORMAT_GANZ][$format] ?? null;

            if ($code !== null && $ende > 1) {
                $blatt->getStyle("{$buchstabe}2:{$buchstabe}{$ende}")->getNumberFormat()->setFormatCode($code);
            }

            $blatt->getColumnDimension($buchstabe)->setAutoSize(true);
        }

        if ($zeilen === []) {
            $blatt->setAutoFilter("A1:{$letzte}1");
        } else {
            $tabelle = new Table("A1:{$letzte}{$ende}", 'Tabelle_' . $name);
            $tabelle->getStyle()->setTheme(TableStyle::TABLE_STYLE_MEDIUM2)->setShowRowStripes(true);
            $blatt->addTable($tabelle);
        }

        $blatt->freezePane('A2');
    }

    /**
     * Schreibt Werte mit explizitem Typ (nie Formeln): int/float numerisch, DateTimeImmutable als Excel-Datum, null leer.
     *
     * @param list<mixed> $werte
     */
    private function zeile(Worksheet $blatt, int $zeile, array $werte): void
    {
        foreach (array_values($werte) as $i => $wert) {
            if ($wert === null) {
                continue;
            }

            $adresse = Coordinate::stringFromColumnIndex($i + 1) . $zeile;

            if ($wert instanceof DateTimeImmutable) {
                $blatt->setCellValueExplicit($adresse, Date::PHPToExcel($wert), DataType::TYPE_NUMERIC);
            } elseif (is_int($wert) || is_float($wert)) {
                $blatt->setCellValueExplicit($adresse, $wert, DataType::TYPE_NUMERIC);
            } else {
                $blatt->setCellValueExplicit($adresse, (string) $wert, DataType::TYPE_STRING);
            }
        }
    }

    /**
     * Übersicht für Menschen (frei gestaltet, aber ohne Formeln und verbundene Zellen).
     *
     * @param array<string, mixed>       $auszaehlung
     * @param array<string, mixed>       $zeitraum
     * @param list<array<string, mixed>> $abrechnung
     * @param list<array<string, mixed>> $buchungen
     * @param list<array<string, mixed>> $positionen
     */
    private function uebersicht(Worksheet $blatt, array $auszaehlung, array $zeitraum, array $abrechnung, array $buchungen, array $positionen, int $summeCent): void
    {
        $blatt->setTitle('Uebersicht');

        $personen   = new PersonModel();
        $kontoNamen = [$personen->sammelkontoId('Couleur') => 'Couleur', $personen->sammelkontoId('Bund') => 'Bund'];
        $mitglieder = 0;
        $sammel     = ['Couleur' => 0, 'Bund' => 0];
        foreach ($abrechnung as $z) {
            if ($z['typ'] === 'sammelkonto') {
                // Sammelkonten über die ID zuordnen (Anzeigename ist änderbar); unbekannte unter ihrem Namen.
                $name          = $kontoNamen[(int) $z['id']] ?? (string) $z['anzeigename'];
                $sammel[$name] = ($sammel[$name] ?? 0) + (int) $z['cent'];
            } else {
                $mitglieder += (int) $z['cent'];
            }
        }

        $schwund = AuszaehlungRechner::schwundCent(array_map(static fn (array $p): array => [
            'differenz_cent' => $p['ist'] === null ? null : (int) $p['differenz'] * (int) $p['preis_cent'],
            'start'          => (bool) $p['start'],
        ], $positionen), (string) $auszaehlung['art']);

        $zeilen   = [];
        $euro     = [];
        $fett     = [1];
        $zeilen[] = ['Auszählung ' . $auszaehlung['bereich_name']];
        $zeilen[] = [];
        $zeilen[] = ['Zeitraum', self::anzeige($zeitraum['von']) . ' – ' . self::anzeige($zeitraum['bis'])
            . ($zeitraum['von_inklusiv'] ? ' (ab Inbetriebnahme)' : '')];
        $zeilen[] = ['Art', $auszaehlung['art'] === 'start' ? 'Start-Auszählung' : 'Regulär'];
        $zeilen[] = ['Abgeschlossen am', self::anzeige(self::zeit((string) $auszaehlung['abgeschlossen_at']))];
        $zeilen[] = ['Abgeschlossen von', $auszaehlung['erstellt_von_name']];

        if ($auszaehlung['bemerkung'] !== null && $auszaehlung['bemerkung'] !== '') {
            $zeilen[] = ['Bemerkung', $auszaehlung['bemerkung']];
        }

        $zeilen[] = [];
        $summen = [['Umsatz gesamt', $summeCent], ['davon Mitglieder', $mitglieder]];
        foreach ($sammel as $name => $cent) {
            $summen[] = ['davon ' . $name, $cent];
        }
        $summen[] = ['Schwund (Fehlmenge × Verkaufspreis)', $schwund];

        foreach ($summen as [$text, $cent]) {
            $zeilen[] = [$text, self::euro($cent)];
            $euro[]   = count($zeilen);
        }

        $zeilen[] = [];
        $zeilen[] = ['Top-5-Artikel', 'Menge', 'Umsatz (€)'];
        $fett[]   = count($zeilen);
        foreach ($this->topArtikel($buchungen) as $a) {
            $zeilen[] = [$a['name'], $a['menge'], self::euro($a['cent'])];
            $euro[]   = count($zeilen);
        }

        $zeilen[] = [];
        $zeilen[] = ['Hinweise'];
        $fett[]   = count($zeilen);
        $hinweise = [];
        foreach ($positionen as $p) {
            if ($p['ist'] === null) {
                $hinweise[] = $p['artikel_name'] . ': kein Ist-Wert erfasst.';
            } elseif ((int) $p['ist'] < 0) {
                $hinweise[] = $p['artikel_name'] . ': Ist ist negativ (' . $p['ist'] . ').';
            }

            if ((int) $p['soll'] < 0) {
                $hinweise[] = $p['artikel_name'] . ': Soll ist negativ (' . $p['soll'] . ') – Buchungen und Bewegungen prüfen.';
            }
        }
        foreach ($hinweise === [] ? ['Keine Auffälligkeiten.'] : $hinweise as $h) {
            $zeilen[] = [$h];
        }

        foreach ($zeilen as $i => $werte) {
            $this->zeile($blatt, $i + 1, $werte);
        }
        foreach ($fett as $nr) {
            $blatt->getStyle("A{$nr}:C{$nr}")->getFont()->setBold(true);
        }
        foreach ($euro as $nr) {
            $blatt->getStyle("B{$nr}:C{$nr}")->getNumberFormat()->setFormatCode('#,##0.00 "€"');
        }
        $blatt->getStyle('A1')->getFont()->setSize(14);
        foreach (['A', 'B', 'C'] as $s) {
            $blatt->getColumnDimension($s)->setAutoSize(true);
        }
    }

    /**
     * Statistik (Entscheidung 9): Wertetabellen und ein Säulendiagramm „Umsatz je Kategorie“.
     *
     * @param list<array<string, mixed>> $buchungen
     * @param array<string, mixed>       $zeitraum
     */
    private function statistik(Worksheet $blatt, array $buchungen, array $zeitraum, int $summeCent, int $bereichId): void
    {
        $blatt->setTitle('Statistik');

        $kategorien = [];
        $wochen     = [];
        $tage       = array_fill_keys(array_keys(self::WOCHENTAGE), ['menge' => 0, 'cent' => 0]);
        $menge      = 0;

        foreach ($buchungen as $b) {
            if ($b['storniert_at'] !== null) {
                continue;
            }

            $m    = (int) $b['menge'];
            $cent = $m * (int) $b['einzelpreis_cent'];
            $zeit = self::zeit($b['gebucht_at']);
            $menge += $m;

            $k = (int) $b['kategorie_id'];
            $kategorien[$k] ??= ['name' => $b['kategorie_name'], 'sortierung' => (int) $b['kategorie_sortierung'], 'menge' => 0, 'cent' => 0];
            $kategorien[$k]['menge'] += $m;
            $kategorien[$k]['cent'] += $cent;

            $woche = $zeit->format('o-\WW');
            $wochen[$woche] ??= ['menge' => 0, 'cent' => 0];
            $wochen[$woche]['menge'] += $m;
            $wochen[$woche]['cent'] += $cent;

            $tag = (int) $zeit->format('N');
            $tage[$tag]['menge'] += $m;
            $tage[$tag]['cent'] += $cent;
        }

        uksort($kategorien, static fn (int $a, int $b): int => [$kategorien[$a]['sortierung'], $a] <=> [$kategorien[$b]['sortierung'], $b]);
        ksort($wochen);

        $zeilen = [];
        $fett   = [];
        $euro   = [];
        $abschnitt = function (string $titel, array $kopf, array $daten) use (&$zeilen, &$fett, &$euro): array {
            if ($zeilen !== []) {
                $zeilen[] = [];
            }
            $zeilen[] = [$titel];
            $fett[]   = count($zeilen);
            $zeilen[] = $kopf;
            $fett[]   = count($zeilen);
            $start    = count($zeilen) + 1;
            foreach ($daten as $d) {
                $zeilen[] = $d;
                $euro[]   = count($zeilen);
            }

            return [$start, count($zeilen)];
        };

        [$katStart, $katEnde] = $abschnitt('Umsatz je Kategorie', ['kategorie', 'menge', 'umsatz_eur'], array_map(
            static fn (array $k): array => [$k['name'], $k['menge'], self::euro($k['cent'])],
            array_values($kategorien),
        ));
        $katKopf = $katStart - 1;

        $abschnitt('Umsatz je Kalenderwoche', ['kalenderwoche', 'menge', 'umsatz_eur'], array_map(
            static fn (string $w, array $d): array => [$w, $d['menge'], self::euro($d['cent'])],
            array_keys($wochen), array_values($wochen),
        ));

        $abschnitt('Umsatz je Wochentag', ['wochentag', 'menge', 'umsatz_eur'], array_map(
            static fn (int $t, array $d): array => [self::WOCHENTAGE[$t], $d['menge'], self::euro($d['cent'])],
            array_keys($tage), array_values($tage),
        ));

        $vorherige = $zeitraum['vorherige'];
        $vor       = null;
        $vorText   = 'kein Vorzeitraum';
        if ($vorherige !== null) {
            $vorZeitraum = $this->zeitraum($vorherige);
            $vor         = $this->summe($bereichId, $vorZeitraum);
            $vorText     = self::anzeige($vorZeitraum['von']) . ' – ' . self::anzeige($vorZeitraum['bis']);
        }

        [$vglStart] = $abschnitt('Vergleich zum Vorzeitraum', ['kennzahl', 'dieser_zeitraum', 'vorzeitraum', 'differenz'], [
            ['Zeitraum', self::anzeige($zeitraum['von']) . ' – ' . self::anzeige($zeitraum['bis']), $vorText],
            ['Umsatz (€)', self::euro($summeCent), $vor === null ? null : self::euro($vor['cent']), $vor === null ? null : self::euro($summeCent - $vor['cent'])],
            ['Verkaufte Einheiten', $menge, $vor === null ? null : $vor['menge'], $vor === null ? null : $menge - $vor['menge']],
        ]);
        $umsatzZeile = $vglStart + 1;
        $mengeZeile  = $vglStart + 2;

        foreach ($zeilen as $i => $werte) {
            $this->zeile($blatt, $i + 1, $werte);
        }
        foreach ($fett as $nr) {
            $blatt->getStyle("A{$nr}:D{$nr}")->getFont()->setBold(true);
        }
        foreach ($euro as $nr) {
            $blatt->getStyle("C{$nr}")->getNumberFormat()->setFormatCode(self::FORMAT_EUR);
        }
        $blatt->getStyle("B{$umsatzZeile}:D{$umsatzZeile}")->getNumberFormat()->setFormatCode(self::FORMAT_EUR);
        $blatt->getStyle("B{$mengeZeile}:D{$mengeZeile}")->getNumberFormat()->setFormatCode(self::FORMAT_GANZ);
        foreach (['A', 'B', 'C', 'D'] as $s) {
            $blatt->getColumnDimension($s)->setAutoSize(true);
        }

        if ($kategorien !== []) {
            $anzahl = $katEnde - $katStart + 1;
            $reihe  = new DataSeries(
                DataSeries::TYPE_BARCHART,
                DataSeries::GROUPING_CLUSTERED,
                [0],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Statistik'!\$C\${$katKopf}", null, 1)],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'Statistik'!\$A\${$katStart}:\$A\${$katEnde}", null, $anzahl)],
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'Statistik'!\$C\${$katStart}:\$C\${$katEnde}", null, $anzahl)],
            );
            $reihe->setPlotDirection(DataSeries::DIRECTION_COL);

            $diagramm = new Chart('umsatz_je_kategorie', new Title('Umsatz je Kategorie'), null, new PlotArea(null, [$reihe]), true, DataSeries::EMPTY_AS_GAP, null, new Title('Euro'));
            $diagramm->setTopLeftPosition('F2');
            $diagramm->setBottomRightPosition('N20');
            $blatt->addChart($diagramm);
        }
    }

    /**
     * Top-5 nach Menge (dann Umsatz, Name) aus nicht stornierten Buchungen; nur Artikel mit Menge > 0.
     *
     * @param list<array<string, mixed>> $buchungen
     *
     * @return list<array{name: string, menge: int, cent: int}>
     */
    private function topArtikel(array $buchungen): array
    {
        $artikel = [];
        foreach ($buchungen as $b) {
            if ($b['storniert_at'] !== null) {
                continue;
            }
            $id = (int) $b['artikel_id'];
            $artikel[$id] ??= ['name' => (string) $b['artikel_name'], 'menge' => 0, 'cent' => 0];
            $artikel[$id]['menge'] += (int) $b['menge'];
            $artikel[$id]['cent'] += (int) $b['menge'] * (int) $b['einzelpreis_cent'];
        }

        $artikel = array_values(array_filter($artikel, static fn (array $a): bool => $a['menge'] > 0));
        usort($artikel, static fn (array $a, array $b): int => [$b['menge'], $b['cent'], $a['name']] <=> [$a['menge'], $a['cent'], $b['name']]);

        return array_slice($artikel, 0, 5);
    }

    /**
     * Buchungen des Bereichs im Zeitraum (Beginn exklusiv, beim ersten Zeitraum inklusiv; Stichtag inklusiv).
     *
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     */
    private function buchungsBasis(int $bereichId, array $zeitraum): BaseBuilder
    {
        return db_connect()->table('buchungen b')
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('b.gebucht_at ' . ($zeitraum['von_inklusiv'] ? '>=' : '>'), $zeitraum['von']->format('Y-m-d H:i:s'))
            ->where('b.gebucht_at <=', $zeitraum['bis']->format('Y-m-d H:i:s'));
    }

    /**
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     *
     * @return list<array<string, mixed>>
     */
    private function buchungen(int $bereichId, array $zeitraum): array
    {
        return $this->buchungsBasis($bereichId, $zeitraum)
            ->select('b.*, a.name AS artikel_name, k.id AS kategorie_id, k.name AS kategorie_name, k.sortierung AS kategorie_sortierung, '
                . 'konto.anzeigename AS konto_name, von.anzeigename AS gebucht_von_name')
            ->join('personen konto', 'konto.id = b.konto_id')
            ->join('personen von', 'von.id = b.gebucht_von_id', 'left')
            ->orderBy('b.gebucht_at')->orderBy('b.id')
            ->get()->getResultArray();
    }

    /**
     * Eine Zeile je Konto mit nicht stornierten Buchungen; Cent-Summe mit CAST (S2-R4, Korrekturmengen negativ).
     *
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     *
     * @return list<array<string, mixed>>
     */
    private function abrechnung(int $bereichId, array $zeitraum): array
    {
        return $this->buchungsBasis($bereichId, $zeitraum)
            ->select('p.id, p.typ, p.vorname, p.nachname, p.anzeigename, p.gruppe, SUM(b.menge) AS anzahl, '
                . 'SUM(b.menge * CAST(b.einzelpreis_cent AS SIGNED)) AS cent')
            ->join('personen p', 'p.id = b.konto_id')
            ->where('b.storniert_at', null)
            ->groupBy('p.id')
            ->orderBy('p.typ')->orderBy('p.nachname')->orderBy('p.vorname')->orderBy('p.anzeigename')->orderBy('p.id')
            ->get()->getResultArray();
    }

    /**
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     *
     * @return list<array<string, mixed>>
     */
    private function positionen(int $bereichId, array $zeitraum): array
    {
        return $this->buchungsBasis($bereichId, $zeitraum)
            ->select('b.konto_id, p.anzeigename, b.artikel_id, a.name AS artikel, k.name AS kategorie, b.einzelpreis_cent, '
                . 'SUM(b.menge) AS menge, SUM(b.menge * CAST(b.einzelpreis_cent AS SIGNED)) AS cent')
            ->join('personen p', 'p.id = b.konto_id')
            ->where('b.storniert_at', null)
            ->groupBy('b.konto_id, b.artikel_id, b.einzelpreis_cent, p.id, a.id, k.id')
            ->orderBy('p.typ')->orderBy('p.nachname')->orderBy('p.vorname')->orderBy('p.anzeigename')->orderBy('b.konto_id')
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')->orderBy('b.einzelpreis_cent')
            ->get()->getResultArray();
    }

    /**
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     *
     * @return array{menge: int, cent: int}
     */
    private function summe(int $bereichId, array $zeitraum): array
    {
        $zeile = $this->buchungsBasis($bereichId, $zeitraum)
            ->select('COALESCE(SUM(b.menge), 0) AS menge, COALESCE(SUM(b.menge * CAST(b.einzelpreis_cent AS SIGNED)), 0) AS cent')
            ->where('b.storniert_at', null)
            ->get()->getRowArray();

        return ['menge' => (int) $zeile['menge'], 'cent' => (int) $zeile['cent']];
    }

    /**
     * @param array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool} $zeitraum
     *
     * @return list<array<string, mixed>>
     */
    private function bewegungen(int $bereichId, array $zeitraum): array
    {
        return db_connect()->table('bestandsbewegungen m')
            ->select('m.*, a.name AS artikel_name, p.anzeigename AS person_name')
            ->join('artikel a', 'a.id = m.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('personen p', 'p.id = m.person_id')
            ->where('k.bereich_id', $bereichId)
            ->where('m.erfolgt_at ' . ($zeitraum['von_inklusiv'] ? '>=' : '>'), $zeitraum['von']->format('Y-m-d H:i:s'))
            ->where('m.erfolgt_at <=', $zeitraum['bis']->format('Y-m-d H:i:s'))
            ->orderBy('m.erfolgt_at')->orderBy('m.id')
            ->get()->getResultArray();
    }

    /**
     * Entscheidung 8: letzter bekannter Einkaufspreis je Artikel (letzte Lieferung mit Preis bis zum Stichtag).
     *
     * @param list<int> $artikelIds
     *
     * @return array<int, int> artikel_id => Cent je Stück
     */
    private function einkaufspreise(array $artikelIds, DateTimeImmutable $bis): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $preise = [];
        foreach (db_connect()->table('bestandsbewegungen')->select('artikel_id, einkaufspreis_cent')
            ->where('art', 'lieferung')->where('einkaufspreis_cent IS NOT NULL')->whereIn('artikel_id', $artikelIds)
            ->where('erfolgt_at <=', $bis->format('Y-m-d H:i:s'))
            ->orderBy('erfolgt_at')->orderBy('id')->get()->getResultArray() as $z) {
            $preise[(int) $z['artikel_id']] = (int) $z['einkaufspreis_cent'];
        }

        return $preise;
    }

    /** Euro aus einer Cent-Summe (erst summieren, dann teilen). */
    private static function euro(int $cent): float
    {
        return $cent / 100;
    }

    private static function zeit(string $wert): DateTimeImmutable
    {
        return new DateTimeImmutable($wert, new DateTimeZone('Europe/Berlin'));
    }

    private static function anzeige(DateTimeImmutable $zeit): string
    {
        return $zeit->format('d.m.Y H:i');
    }
}
