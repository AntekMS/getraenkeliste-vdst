<?php

declare(strict_types=1);

namespace App\Libraries;

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
        $personen = new PersonModel();
        $couleur  = $personen->sammelkontoId('Couleur');
        $bund     = $personen->sammelkontoId('Bund');
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
        $ab     = $jetzt->modify('monday this week')->setTime(0, 0)->modify('-' . ($wochen - 1) . ' weeks');

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
            ->select('m.erfolgt_at, m.person_id, m.menge, m.einkaufspreis_cent, a.name, p.anzeigename')
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
                'artikel'            => (string) $z['name'],
                'menge'              => (int) $z['menge'],
                'einkaufspreis_cent' => $z['einkaufspreis_cent'] === null ? null : (int) $z['einkaufspreis_cent'],
            ];
        }

        return array_values($ergebnis);
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
                        'name'           => $a['einheit'] === '' ? (string) $a['name'] : "{$a['name']} ({$a['einheit']})",
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
