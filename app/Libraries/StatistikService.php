<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuszaehlungModel;
use App\Models\PersonModel;
use DateTimeImmutable;

/**
 * Rohwerte der Statistikseiten per Aggregatabfragen (Statistik-Spec 2 und 4); gerechnet wird in `StatistikRechner`.
 * Bestand/Ampel kommen ausschließlich aus `bestand()` (keine zweite Bestandsrechnung).
 *
 * - Verbrauch (Tagesverbrauch, Wochen) = Σ `menge` nicht stornierter, bestandswirksamer Buchungen (wie der Bestand).
 * - Anteile (Menge/Cent) = alle nicht stornierten Buchungen inkl. nicht bestandswirksamer Korrekturen (Geldsicht).
 * - Wochen-Ansicht `alle` = je nicht archivierter Kategorie eine Reihe über **alle** ihre Artikel (auch ohne Bestandsführung
 *   oder archiviert); `kategorie:<id>` = je Artikel der Kategorie eine Reihe (archivierte nur mit Verbrauch); `artikel:<id>` = eine Reihe.
 */
class StatistikService
{
    private const FENSTER_TAGE = 28;
    private const MAX_WOCHEN   = 104;

    /** @var array<string, array<string, mixed>> Schwund-Rohwerte je (Bereich, Anzahl, Artikel) für die Dauer der Instanz (ein Request) */
    private array $schwundCache = [];

    /** @var array<string, int> */
    private array $sammelkonten = [];

    /** Schwund-Werte eines Artikels (oder Zeitraums) vor der Quote. */
    private const SCHWUND_LEER = [
        'erfasst_menge' => 0, 'erfasst_cent' => 0, 'unerklaert_menge' => 0, 'unerklaert_cent' => 0,
        'ueberschuss_menge' => 0, 'ueberschuss_cent' => 0, 'verkauft' => 0,
    ];

    /**
     * @return array{grundlage_tage: int, reichweite_tage: int, kategorien: list<array{kategorie_id: int, kategorie_name: string, artikel: list<array<string, mixed>>}>}
     */
    public function einkauf(int $bereichId): array
    {
        $jetzt      = service('uhr')->jetzt();
        $grundlage  = StatistikRechner::grundlageTage(service('einstellungen')->inbetriebnahme(), $jetzt);
        $reichweite = service('einstellungen')->int('reichweite_tage');
        $kategorien = service('bestand')->fuerBereich($bereichId);

        $ids = [];

        foreach ($kategorien as $kategorie) {
            foreach ($kategorie['artikel'] as $artikel) {
                $ids[] = $artikel['artikel_id'];
            }
        }

        $verkauft = $this->verbrauchJeArtikel($ids, $jetzt->modify('-' . self::FENSTER_TAGE . ' days'), $jetzt);
        $gebinde  = $this->gebinde($ids);
        $preise   = $this->letzteEinkaufspreise($ids);

        foreach ($kategorien as &$kategorie) {
            foreach ($kategorie['artikel'] as &$artikel) {
                $id        = $artikel['artikel_id'];
                $verbrauch = StatistikRechner::tagesverbrauch($verkauft[$id] ?? 0, $grundlage);

                $artikel['gebinde_groesse'] = $gebinde[$id] ?? null;
                $artikel['tagesverbrauch']  = $verbrauch;
                $artikel['reichweite']      = StatistikRechner::reichweiteTage($artikel['bestand'], $verbrauch);
                $artikel['vorschlag']       = StatistikRechner::vorschlag($verbrauch, $reichweite, $artikel['mindestbestand'], $artikel['bestand'], $artikel['gebinde_groesse']);
                $artikel['letzter_ek_cent'] = $preise[$id] ?? null;
            }
            unset($artikel);
        }
        unset($kategorie);

        return ['grundlage_tage' => $grundlage, 'reichweite_tage' => $reichweite, 'kategorien' => $kategorien];
    }

    /**
     * Menge und Umsatz (Cent) nicht stornierter Buchungen im Fenster (`von` exklusiv, außer `$vonInklusiv`; `bis` inklusiv).
     *
     * @return array{menge: array{mitglieder: int, couleur: int, bund: int}, cent: array{mitglieder: int, couleur: int, bund: int}}
     */
    public function anteile(int $bereichId, DateTimeImmutable $von, bool $vonInklusiv, DateTimeImmutable $bis): array
    {
        $couleur   = $this->sammelkontoId('Couleur');
        $bund      = $this->sammelkontoId('Bund');
        $vergleich = $vonInklusiv ? '>=' : '>';

        $zeilen = db_connect()->table('buchungen b')
            ->select("CASE WHEN b.konto_id = {$couleur} THEN 'couleur' WHEN b.konto_id = {$bund} THEN 'bund' ELSE 'mitglieder' END AS art", false)
            ->select('SUM(b.menge) AS menge, SUM(b.menge * CAST(b.einzelpreis_cent AS SIGNED)) AS cent', false)
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('personen p', 'p.id = b.konto_id')
            ->where('k.bereich_id', $bereichId)
            ->where('b.storniert_at', null)
            ->groupStart()->where('p.typ', 'mitglied')->orWhereIn('b.konto_id', [$couleur, $bund])->groupEnd()
            ->where("b.gebucht_at {$vergleich}", $von->format('Y-m-d H:i:s'))
            ->where('b.gebucht_at <=', $bis->format('Y-m-d H:i:s'))
            ->groupBy('art')
            ->get()->getResultArray();

        $leer     = ['mitglieder' => 0, 'couleur' => 0, 'bund' => 0];
        $ergebnis = ['menge' => $leer, 'cent' => $leer];

        foreach ($zeilen as $zeile) {
            $ergebnis['menge'][$zeile['art']] = (int) $zeile['menge'];
            $ergebnis['cent'][$zeile['art']]  = (int) $zeile['cent'];
        }

        return $ergebnis;
    }

    /**
     * Verbrauch je ISO-Woche (Mo–So, Europe/Berlin) der letzten `$wochen` Wochen inkl. der laufenden bis jetzt.
     * Ungültige oder bereichsfremde Ansicht → `alle`; `ansicht` im Ergebnis ist die tatsächlich verwendete.
     *
     * @return array{ansicht: string, wochen: list<string>, reihen: list<array{name: string, werte: list<int>}>}
     */
    public function wochenverbrauch(int $bereichId, string $ansicht, int $wochen = 12): array
    {
        $wochen = max(1, min(self::MAX_WOCHEN, $wochen));
        $jetzt  = service('uhr')->jetzt();
        $liste  = StatistikRechner::letzteWochen($jetzt, $wochen);
        $ab     = StatistikRechner::wochenBeginn($jetzt, $wochen);

        [$ansicht, $reihen, $schluessel] = $this->reihen($bereichId, $ansicht);

        if ($reihen === []) {
            return ['ansicht' => $ansicht, 'wochen' => $liste, 'reihen' => []];
        }

        $q = db_connect()->table('buchungen b')
            ->select("{$schluessel} AS reihe, DATE_FORMAT(b.gebucht_at, '%x-W%v') AS woche, SUM(b.menge) AS summe", false)
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('b.storniert_at', null)
            ->where('b.bestandswirksam', 1)
            ->where('b.gebucht_at >=', $ab->format('Y-m-d H:i:s'))
            ->where('b.gebucht_at <=', $jetzt->format('Y-m-d H:i:s'))
            ->whereIn($schluessel, array_keys($reihen))
            ->groupBy(['reihe', 'woche']);

        $werte = [];

        foreach ($q->get()->getResultArray() as $zeile) {
            $werte[(int) $zeile['reihe']][$zeile['woche']] = (int) $zeile['summe'];
        }

        $ergebnis = [];

        foreach ($reihen as $id => $reihe) {
            if ($reihe['nur_mit_werten'] && ! isset($werte[$id])) {
                continue;
            }

            $ergebnis[] = [
                'name'  => $reihe['name'],
                'werte' => array_map(static fn (string $woche): int => $werte[$id][$woche] ?? 0, $liste),
            ];
        }

        return ['ansicht' => $ansicht, 'wochen' => $liste, 'reihen' => $ergebnis];
    }

    /**
     * Auswahl für die Verlauf-Ansicht: nicht archivierte Kategorien des Bereichs mit ihren nicht archivierten Artikeln
     * (Sortierung wie überall), eine Abfrage.
     *
     * @return list<array{id: int, name: string, artikel: list<array{id: int, name: string}>}>
     */
    public function ansichtOptionen(int $bereichId): array
    {
        $zeilen = db_connect()->table('kategorien k')
            ->select('k.id AS kategorie_id, k.name AS kategorie_name, a.id AS artikel_id, a.name, a.einheit')
            ->join('artikel a', 'a.kategorie_id = k.id AND a.archiviert_at IS NULL', 'left')
            ->where('k.bereich_id', $bereichId)
            ->where('k.archiviert_at', null)
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();

        $kategorien = [];

        foreach ($zeilen as $z) {
            $id = (int) $z['kategorie_id'];
            $kategorien[$id] ??= ['id' => $id, 'name' => (string) $z['kategorie_name'], 'artikel' => []];

            if ($z['artikel_id'] !== null) {
                $kategorien[$id]['artikel'][] = [
                    'id'   => (int) $z['artikel_id'],
                    'name' => self::anzeigename((string) $z['name'], (string) $z['einheit']),
                ];
            }
        }

        return array_values($kategorien);
    }

    /**
     * Letzte `$anzahl` Lieferungen (gruppiert nach `erfolgt_at` + erfasst von), neueste zuerst.
     *
     * @return list<array{erfolgt_at: string, erfasst_von: string, zeilen: list<array{artikel: string, menge: int, einkaufspreis_cent: ?int}>}>
     */
    public function lieferhistorie(int $bereichId, int $anzahl = 20): array
    {
        $db     = db_connect();
        $basis  = static fn () => $db->table('bestandsbewegungen m')
            ->join('artikel a', 'a.id = m.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('m.art', 'lieferung');

        $gruppen = $basis()
            ->select('m.erfolgt_at, m.person_id')
            ->groupBy(['m.erfolgt_at', 'm.person_id'])
            ->orderBy('m.erfolgt_at', 'DESC')->orderBy('m.person_id', 'DESC')
            ->limit(max(1, $anzahl))
            ->get()->getResultArray();

        if ($gruppen === []) {
            return [];
        }

        $zeilen = $basis()
            ->select('m.erfolgt_at, m.person_id, m.menge, m.einkaufspreis_cent, a.name, a.einheit, p.anzeigename')
            ->join('personen p', 'p.id = m.person_id')
            ->where('m.erfolgt_at >=', end($gruppen)['erfolgt_at'])
            ->orderBy('m.id')
            ->get()->getResultArray();

        $ergebnis = [];

        foreach ($gruppen as $g) {
            $ergebnis[$g['erfolgt_at'] . '|' . $g['person_id']] = ['erfolgt_at' => (string) $g['erfolgt_at'], 'erfasst_von' => '', 'zeilen' => []];
        }

        foreach ($zeilen as $z) {
            $schluessel = $z['erfolgt_at'] . '|' . $z['person_id'];

            if (! isset($ergebnis[$schluessel])) {
                continue;
            }

            $ergebnis[$schluessel]['erfasst_von'] = (string) $z['anzeigename'];
            $ergebnis[$schluessel]['zeilen'][]    = [
                'artikel'            => self::anzeigename((string) $z['name'], (string) $z['einheit']),
                'menge'              => (int) $z['menge'],
                'einkaufspreis_cent' => $z['einkaufspreis_cent'] === null ? null : (int) $z['einkaufspreis_cent'],
            ];
        }

        return array_values($ergebnis);
    }

    /**
     * Schwund je abgeschlossenem Zeitraum (Statistik-Spec 3.1/3.2), neueste zuerst. Erfasst = Schwund-Bewegungen im Zeitraum
     * × Preis der Position dieser Auszählung (ohne Position: aktueller Preis); unerklärt/Überschuss = gespeicherte Differenzen
     * der Positionen ohne `start`; verkauft = Σ `verkauft` der Positionen. Start-Auszählung (`art = start`): alle Schwundwerte 0,
     * Quote null (S3-R5), `verkauft` bleibt die Summe der Positionen.
     *
     * @return list<array{auszaehlung_id: int, von: string, bis: string, art: string, erfasst_menge: int, erfasst_cent: int, unerklaert_menge: int, unerklaert_cent: int, ueberschuss_menge: int, ueberschuss_cent: int, verkauft: int, quote: ?float}>
     */
    public function schwundZeitraeume(int $bereichId, int $anzahl = 6): array
    {
        $daten = $this->schwundDaten($bereichId, $anzahl);
        $liste = [];

        foreach ($daten['zeitraeume'] as $i => $zeitraum) {
            $summe = self::SCHWUND_LEER;

            foreach ($daten['werte'] as $jeZeitraum) {
                foreach ($jeZeitraum[$i] ?? [] as $schluessel => $wert) {
                    $summe[$schluessel] += $wert;
                }
            }

            $summe['verkauft'] = $daten['verkauft'][$i];
            $quote             = $zeitraum['art'] === 'start' ? null
                : StatistikRechner::quote($summe['erfasst_menge'] + $summe['unerklaert_menge'], $summe['verkauft']);

            $liste[] = $zeitraum + $summe + ['quote' => $quote];
        }

        return $liste;
    }

    /**
     * Artikel mit dem meisten Schwund über die letzten `$zeitraeume` abgeschlossenen Zeiträume (Spec 3.3): nur Artikel mit
     * erfasstem oder unerklärtem Schwund, sortiert nach `gesamt_cent` absteigend, dann Name.
     *
     * @return list<array{artikel_id: int, name: string, erfasst_menge: int, unerklaert_menge: int, gesamt_cent: int, quote: ?float}>
     */
    public function schwundArtikel(int $bereichId, int $zeitraeume = 6, int $limit = 10): array
    {
        $daten = $this->schwundDaten($bereichId, $zeitraeume);
        $liste = [];

        foreach ($daten['werte'] as $artikelId => $jeZeitraum) {
            $summe = self::SCHWUND_LEER;

            foreach ($jeZeitraum as $werte) {
                foreach ($werte as $schluessel => $wert) {
                    $summe[$schluessel] += $wert;
                }
            }

            $fehlmenge = $summe['erfasst_menge'] + $summe['unerklaert_menge'];

            if ($fehlmenge === 0) {
                continue;
            }

            $liste[] = [
                'artikel_id'       => $artikelId,
                'name'             => $daten['namen'][$artikelId] ?? '',
                'erfasst_menge'    => $summe['erfasst_menge'],
                'unerklaert_menge' => $summe['unerklaert_menge'],
                'gesamt_cent'      => $summe['erfasst_cent'] + $summe['unerklaert_cent'],
                'quote'            => StatistikRechner::quote($fehlmenge, $summe['verkauft']),
            ];
        }

        usort($liste, static fn (array $x, array $y): int => [$y['gesamt_cent'], $x['name'], $x['artikel_id']] <=> [$x['gesamt_cent'], $y['name'], $y['artikel_id']]);

        return array_slice($liste, 0, max(0, $limit));
    }

    /**
     * Schwund eines Artikels je abgeschlossenem Zeitraum (neueste zuerst); Artikel eines anderen Bereichs → [].
     *
     * @return list<array{von: string, bis: string, erfasst_menge: int, unerklaert_menge: int, gesamt_cent: int}>
     */
    public function schwundArtikelVerlauf(int $bereichId, int $artikelId, int $zeitraeume = 6): array
    {
        $gehoertDazu = db_connect()->table('artikel a')->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('a.id', $artikelId)->where('k.bereich_id', $bereichId)->countAllResults() === 1;

        if (! $gehoertDazu) {
            return [];
        }

        $daten = $this->schwundDaten($bereichId, $zeitraeume, $artikelId);
        $liste = [];

        foreach ($daten['zeitraeume'] as $i => $zeitraum) {
            $werte   = $daten['werte'][$artikelId][$i] ?? self::SCHWUND_LEER;
            $liste[] = [
                'von'              => $zeitraum['von'],
                'bis'              => $zeitraum['bis'],
                'erfasst_menge'    => $werte['erfasst_menge'],
                'unerklaert_menge' => $werte['unerklaert_menge'],
                'gesamt_cent'      => $werte['erfasst_cent'] + $werte['unerklaert_cent'],
            ];
        }

        return $liste;
    }

    /**
     * Erfasster Schwund im laufenden Zeitraum (nach dem letzten Stichtag, ohne Abschluss ab Inbetriebnahme inklusiv), aktueller Preis.
     *
     * @return array{menge: int, cent: int}
     */
    public function schwundLaufend(int $bereichId): array
    {
        $zeitraeume = service('zeitraeume');
        $vergleich  = $zeitraeume->beginnInklusiv($bereichId) ? '>=' : '>';

        $zeile = db_connect()->table('bestandsbewegungen m')
            ->select('COALESCE(SUM(ABS(m.menge)), 0) AS menge, COALESCE(SUM(ABS(m.menge) * CAST(a.preis_cent AS SIGNED)), 0) AS cent', false)
            ->join('artikel a', 'a.id = m.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('m.art', 'schwund')
            ->where("m.erfolgt_at {$vergleich}", $zeitraeume->beginn($bereichId)->format('Y-m-d H:i:s'))
            ->get()->getRowArray();

        return ['menge' => (int) $zeile['menge'], 'cent' => (int) $zeile['cent']];
    }

    /**
     * Schwund-Rohwerte der letzten `$anzahl` abgeschlossenen Zeiträume mit festen vier Abfragen (Auszählungen, Positionen,
     * Bewegungen, Artikel). Zeitraum-Regel aus `AuszaehlungModel::zeitraum` (wie der Excel-Export). Zeiträume einer
     * Start-Auszählung tragen nichts zu `werte` bei (weder Schwund noch verkauft); `verkauft` je Zeitraum steht getrennt.
     *
     * @return array{zeitraeume: list<array{auszaehlung_id: int, von: string, bis: string, art: string}>, werte: array<int, array<int, array<string, int>>>, verkauft: list<int>, namen: array<int, string>}
     */
    private function schwundDaten(int $bereichId, int $anzahl, ?int $nurArtikel = null): array
    {
        $schluessel = "{$bereichId}|{$anzahl}|" . ($nurArtikel ?? '*');

        return $this->schwundCache[$schluessel] ??= $this->ladeSchwundDaten($bereichId, $anzahl, $nurArtikel);
    }

    /**
     * @return array{zeitraeume: list<array{auszaehlung_id: int, von: string, bis: string, art: string}>, werte: array<int, array<int, array<string, int>>>, verkauft: list<int>, namen: array<int, string>}
     */
    private function ladeSchwundDaten(int $bereichId, int $anzahl, ?int $nurArtikel): array
    {
        $auszaehlungen = (new AuszaehlungModel())->letzteMitZeitraum($bereichId, $anzahl);

        if ($auszaehlungen === []) {
            return ['zeitraeume' => [], 'werte' => [], 'verkauft' => [], 'namen' => []];
        }

        $db         = db_connect();
        $index      = [];
        $zeitraeume = [];
        $fenster    = [];

        foreach ($auszaehlungen as $i => $a) {
            $index[(int) $a['id']] = $i;
            $zeitraeume[]          = [
                'auszaehlung_id' => (int) $a['id'],
                'von'            => $a['zeitraum']['von']->format('Y-m-d H:i:s'),
                'bis'            => $a['zeitraum']['bis']->format('Y-m-d H:i:s'),
                'art'            => (string) $a['art'],
            ];
            $fenster[] = [$zeitraeume[$i]['von'], $zeitraeume[$i]['bis'], $a['zeitraum']['von_inklusiv']];
        }

        $werte    = [];
        $preise   = [];
        $verkauft = array_fill(0, count($zeitraeume), 0);

        $positionen = $db->table('auszaehlung_positionen')
            ->select('auszaehlung_id, artikel_id, verkauft, differenz, start, preis_cent')
            ->whereIn('auszaehlung_id', array_keys($index));

        if ($nurArtikel !== null) {
            $positionen->where('artikel_id', $nurArtikel);
        }

        foreach ($positionen->get()->getResultArray() as $p) {
            $i = $index[(int) $p['auszaehlung_id']];
            $verkauft[$i] += (int) $p['verkauft'];

            if ($zeitraeume[$i]['art'] === 'start') {
                continue;
            }

            $artikelId = (int) $p['artikel_id'];
            $differenz = (int) $p['differenz'];
            $preis     = (int) $p['preis_cent'];
            $w         = $werte[$artikelId][$i] ?? self::SCHWUND_LEER;
            $zaehlt    = (int) $p['start'] === 0;

            $preise[$artikelId][$i] = $preis;
            $w['verkauft'] += (int) $p['verkauft'];

            if ($zaehlt && $differenz < 0) {
                $w['unerklaert_menge'] -= $differenz;
                $w['unerklaert_cent'] -= $differenz * $preis;
            } elseif ($zaehlt && $differenz > 0) {
                $w['ueberschuss_menge'] += $differenz;
                $w['ueberschuss_cent'] += $differenz * $preis;
            }

            $werte[$artikelId][$i] = $w;
        }

        $bewegungen = $db->table('bestandsbewegungen m')
            ->select('m.artikel_id, m.erfolgt_at, SUM(ABS(m.menge)) AS menge', false)
            ->join('artikel a', 'a.id = m.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('m.art', 'schwund')
            ->where('m.erfolgt_at >=', end($fenster)[0])
            ->where('m.erfolgt_at <=', $fenster[0][1])
            ->groupBy(['m.artikel_id', 'm.erfolgt_at']);

        if ($nurArtikel !== null) {
            $bewegungen->where('m.artikel_id', $nurArtikel);
        }

        $erfasst = [];

        foreach ($bewegungen->get()->getResultArray() as $m) {
            $zeit = (string) $m['erfolgt_at'];

            foreach ($fenster as $i => [$von, $bis, $inklusiv]) {
                if (($zeit > $von || ($inklusiv && $zeit === $von)) && $zeit <= $bis) {
                    if ($zeitraeume[$i]['art'] === 'start') {
                        break;
                    }

                    $erfasst[(int) $m['artikel_id']][$i] = ($erfasst[(int) $m['artikel_id']][$i] ?? 0) + (int) $m['menge'];
                    break;
                }
            }
        }

        $ids     = array_keys($werte + $erfasst);
        $artikel = [];

        if ($ids !== []) {
            foreach ($db->table('artikel')->select('id, name, einheit, preis_cent')->whereIn('id', $ids)->get()->getResultArray() as $a) {
                $artikel[(int) $a['id']] = $a;
            }
        }

        foreach ($erfasst as $artikelId => $jeZeitraum) {
            foreach ($jeZeitraum as $i => $menge) {
                $w = $werte[$artikelId][$i] ?? self::SCHWUND_LEER;

                $w['erfasst_menge'] += $menge;
                $w['erfasst_cent'] += $menge * ($preise[$artikelId][$i] ?? (int) $artikel[$artikelId]['preis_cent']);

                $werte[$artikelId][$i] = $w;
            }
        }

        return [
            'zeitraeume' => $zeitraeume,
            'werte'      => $werte,
            'verkauft'   => $verkauft,
            'namen'      => array_map(static fn (array $a): string => self::anzeigename((string) $a['name'], (string) $a['einheit']), $artikel),
        ];
    }

    /**
     * Anzeigename „Name (Einheit)“ eines Artikels des Bereichs; fremder oder unbekannter Artikel → ''.
     */
    public function artikelName(int $bereichId, int $artikelId): string
    {
        $zeile = db_connect()->table('artikel a')->select('a.name, a.einheit')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('a.id', $artikelId)->where('k.bereich_id', $bereichId)
            ->get()->getRowArray();

        return $zeile === null ? '' : self::anzeigename((string) $zeile['name'], (string) $zeile['einheit']);
    }

    private static function anzeigename(string $name, string $einheit): string
    {
        return $einheit === '' ? $name : "{$name} ({$einheit})";
    }

    private function sammelkontoId(string $name): int
    {
        return $this->sammelkonten[$name] ??= (new PersonModel())->sammelkontoId($name);
    }

    /**
     * Ansicht prüfen und Reihen festlegen.
     *
     * @return array{0: string, 1: array<int, array{name: string, nur_mit_werten: bool}>, 2: string} [Ansicht, Reihen je ID, SQL-Schlüsselspalte]
     */
    private function reihen(int $bereichId, string $ansicht): array
    {
        $db = db_connect();

        if (preg_match('/^(kategorie|artikel):([1-9]\d{0,9})$/D', $ansicht, $treffer) === 1) {
            $id = (int) $treffer[2];

            $artikel = $db->table('artikel a')
                ->select('a.id, a.name, a.einheit, a.archiviert_at')
                ->join('kategorien k', 'k.id = a.kategorie_id')
                ->where('k.bereich_id', $bereichId)
                ->where($treffer[1] === 'kategorie' ? 'k.id' : 'a.id', $id)
                ->orderBy('a.sortierung')->orderBy('a.id')
                ->get()->getResultArray();

            $gueltig = $artikel !== [] || ($treffer[1] === 'kategorie'
                && $db->table('kategorien')->where('id', $id)->where('bereich_id', $bereichId)->countAllResults() === 1);

            if ($gueltig) {
                $reihen = [];

                foreach ($artikel as $a) {
                    $reihen[(int) $a['id']] = [
                        'name'           => self::anzeigename((string) $a['name'], (string) $a['einheit']),
                        'nur_mit_werten' => $treffer[1] === 'kategorie' && $a['archiviert_at'] !== null,
                    ];
                }

                return [$ansicht, $reihen, 'a.id'];
            }
        }

        $reihen = [];

        foreach ($db->table('kategorien')->select('id, name')->where('bereich_id', $bereichId)->where('archiviert_at', null)
            ->orderBy('sortierung')->orderBy('id')->get()->getResultArray() as $k) {
            $reihen[(int) $k['id']] = ['name' => (string) $k['name'], 'nur_mit_werten' => false];
        }

        return ['alle', $reihen, 'k.id'];
    }

    /**
     * @param list<int> $artikelIds
     *
     * @return array<int, int> artikel_id => Σ menge (nicht storniert, bestandswirksam) in (von, bis]
     */
    private function verbrauchJeArtikel(array $artikelIds, DateTimeImmutable $von, DateTimeImmutable $bis): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $summen = [];

        foreach (db_connect()->table('buchungen')->select('artikel_id, SUM(menge) AS summe')
            ->whereIn('artikel_id', $artikelIds)->where('storniert_at', null)->where('bestandswirksam', 1)
            ->where('gebucht_at >', $von->format('Y-m-d H:i:s'))->where('gebucht_at <=', $bis->format('Y-m-d H:i:s'))
            ->groupBy('artikel_id')->get()->getResultArray() as $zeile) {
            $summen[(int) $zeile['artikel_id']] = (int) $zeile['summe'];
        }

        return $summen;
    }

    /**
     * @param list<int> $artikelIds
     *
     * @return array<int, ?int>
     */
    private function gebinde(array $artikelIds): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $gebinde = [];

        foreach (db_connect()->table('artikel')->select('id, gebinde_groesse')->whereIn('id', $artikelIds)->get()->getResultArray() as $zeile) {
            $gebinde[(int) $zeile['id']] = $zeile['gebinde_groesse'] === null ? null : (int) $zeile['gebinde_groesse'];
        }

        return $gebinde;
    }

    /**
     * Einkaufspreis der jüngsten Lieferung mit Preis je Artikel (eine Abfrage, Fensterfunktion).
     *
     * @param list<int> $artikelIds
     *
     * @return array<int, int>
     */
    private function letzteEinkaufspreise(array $artikelIds): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($artikelIds), '?'));
        $zeilen      = db_connect()->query(
            "SELECT artikel_id, einkaufspreis_cent FROM (
                SELECT artikel_id, einkaufspreis_cent,
                       ROW_NUMBER() OVER (PARTITION BY artikel_id ORDER BY erfolgt_at DESC, id DESC) AS rang
                FROM bestandsbewegungen
                WHERE art = 'lieferung' AND einkaufspreis_cent IS NOT NULL AND artikel_id IN ({$platzhalter})
            ) letzte WHERE rang = 1",
            $artikelIds,
        )->getResultArray();

        $preise = [];

        foreach ($zeilen as $zeile) {
            $preise[(int) $zeile['artikel_id']] = (int) $zeile['einkaufspreis_cent'];
        }

        return $preise;
    }
}
