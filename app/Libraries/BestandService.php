<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\BereichModel;
use App\Models\BestandsbewegungModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Laufender Bestand je Artikel (Spec 6.2): Ist der letzten abgeschlossenen Auszählung (sonst 0)
 * + Bewegungen im laufenden Zeitraum − nicht stornierte Buchungsmengen im laufenden Zeitraum
 * (inkl. Korrekturbuchungen). Der Zeitraum kommt aus `zeitraeume()`; je Aufruf wenige Aggregatabfragen.
 */
class BestandService
{
    private const MAX_BEMERKUNG   = 255;
    private const MAX_LIEFERMENGE = 1000000;

    /**
     * Artikel mit Bestandsführung (nicht archiviert) je Kategorie in Sortierung.
     *
     * @return list<array{kategorie_id: int, kategorie_name: string, artikel: list<array{artikel_id: int, name: string, einheit: string, mindestbestand: int, bestand: int, ampel: string}>}>
     */
    public function fuerBereich(int $bereichId): array
    {
        $artikel = db_connect()->table('artikel a')
            ->select('a.id, a.name, a.einheit, a.mindestbestand, k.id AS kategorie_id, k.name AS kategorie_name')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)
            ->where('a.bestand_fuehren', 1)
            ->where('a.archiviert_at', null)
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();

        $bestaende = $this->bestaende($bereichId, array_map(static fn (array $a): int => (int) $a['id'], $artikel));
        $gruppen   = [];

        foreach ($artikel as $a) {
            $kid = (int) $a['kategorie_id'];
            $gruppen[$kid] ??= ['kategorie_id' => $kid, 'kategorie_name' => (string) $a['kategorie_name'], 'artikel' => []];

            $bestand = $bestaende[(int) $a['id']];
            $mindest = (int) $a['mindestbestand'];

            $gruppen[$kid]['artikel'][] = [
                'artikel_id'     => (int) $a['id'],
                'name'           => (string) $a['name'],
                'einheit'        => (string) $a['einheit'],
                'mindestbestand' => $mindest,
                'bestand'        => $bestand,
                'ampel'          => BestandRechner::ampel($bestand, $mindest),
            ];
        }

        return array_values($gruppen);
    }

    public function einzeln(int $artikelId): int
    {
        $zeile = db_connect()->table('artikel a')
            ->select('k.bereich_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('a.id', $artikelId)
            ->get()->getRowArray();

        if ($zeile === null) {
            return 0;
        }

        return $this->bestaende((int) $zeile['bereich_id'], [$artikelId])[$artikelId];
    }

    /**
     * Lieferung: eine Bewegung je Zeile mit Menge, alles oder nichts. Zeitpunkt = jetzt.
     *
     * @param array<int|string, array{artikel_id: int, kisten: int, stueck: int, einkaufspreis: ?string}> $zeilen
     *
     * @return int Anzahl Bewegungen
     */
    public function liefere(int $bereichId, int $personId, array $zeilen, ?string $bemerkung): int
    {
        helper('betrag');

        $bemerkung = $bemerkung === null || trim($bemerkung) === '' ? null : trim($bemerkung);

        if ($bemerkung !== null && mb_strlen($bemerkung) > self::MAX_BEMERKUNG) {
            throw new BewegungAbgelehnt('Die Bemerkung ist zu lang.', ['bemerkung' => 'Die Bemerkung ist zu lang (höchstens ' . self::MAX_BEMERKUNG . ' Zeichen).']);
        }

        $anzahl = 0;

        $this->schreibend($bereichId, function (string $jetzt) use ($bereichId, $personId, $zeilen, $bemerkung, &$anzahl): void {
            $aktive = array_filter($zeilen, static fn (array $z): bool => $z['kisten'] !== 0 || $z['stueck'] !== 0);

            if ($aktive === []) {
                throw new BewegungAbgelehnt('Bitte mindestens eine Menge eintragen.');
            }

            $gebinde = $this->artikelDesBereichs($bereichId, array_map(static fn (array $z): int => (int) $z['artikel_id'], $aktive));
            $fehler  = [];
            $neue    = [];

            foreach ($aktive as $i => $z) {
                $artikelId = (int) $z['artikel_id'];

                if (! array_key_exists($artikelId, $gebinde)) {
                    $fehler["zeilen.{$i}.artikel_id"] = 'Bitte einen Artikel dieses Bereichs wählen.';

                    continue;
                }

                $preis = $z['einkaufspreis'] === null || trim($z['einkaufspreis']) === '' ? null : betrag_in_cent($z['einkaufspreis']);

                if ($z['einkaufspreis'] !== null && trim($z['einkaufspreis']) !== '' && $preis === null) {
                    $fehler["zeilen.{$i}.einkaufspreis"] = 'Bitte einen gültigen Preis angeben, z. B. 0,85.';
                }

                try {
                    $menge = Lieferumrechnung::menge($z['kisten'], $z['stueck'], $gebinde[$artikelId]);
                } catch (\InvalidArgumentException) {
                    $fehler["zeilen.{$i}.kisten"] = 'Für diesen Artikel ist keine Gebindegröße hinterlegt.';

                    continue;
                }

                if ($menge > self::MAX_LIEFERMENGE) {
                    $fehler["zeilen.{$i}.kisten"] = 'Menge zu groß.';

                    continue;
                }

                $neue[] = ['artikel_id' => $artikelId, 'art' => 'lieferung', 'menge' => $menge, 'einkaufspreis_cent' => $preis, 'bemerkung' => $bemerkung];
            }

            if ($fehler !== []) {
                throw new BewegungAbgelehnt('Bitte die markierten Felder prüfen. Nichts gespeichert.', $fehler);
            }

            foreach ($neue as $bewegung) {
                $this->speichere($bewegung, $personId, $jetzt, 'lieferung');
            }

            $anzahl = count($neue);
        });

        return $anzahl;
    }

    /**
     * Schwund (Eingabe positiv, gespeichert negativ) oder Korrektur (± , nie 0). Bemerkung ist Pflicht.
     *
     * @param 'schwund'|'korrektur' $art
     *
     * @return int ID der Bewegung
     */
    public function bucheBewegung(int $bereichId, int $personId, int $artikelId, string $art, int $menge, string $bemerkung): int
    {
        if (! in_array($art, ['schwund', 'korrektur'], true)) {
            throw new \InvalidArgumentException('Unzulässige Bewegungsart: ' . $art);
        }

        $bemerkung = trim($bemerkung);
        $fehler    = [];

        if ($bemerkung === '') {
            $fehler['bemerkung'] = 'Bitte eine Bemerkung angeben.';
        } elseif (mb_strlen($bemerkung) > self::MAX_BEMERKUNG) {
            $fehler['bemerkung'] = 'Die Bemerkung ist zu lang (höchstens ' . self::MAX_BEMERKUNG . ' Zeichen).';
        }

        if ($menge === 0) {
            $fehler['menge'] = 'Die Menge darf nicht 0 sein.';
        } elseif ($art === 'schwund' && $menge < 0) {
            $fehler['menge'] = 'Bitte beim Schwund die verlorene Menge positiv angeben.';
        }

        if ($fehler !== []) {
            throw new BewegungAbgelehnt('Bitte die markierten Felder prüfen. Nichts gespeichert.', $fehler);
        }

        $id = 0;

        $this->schreibend($bereichId, function (string $jetzt) use ($bereichId, $personId, $artikelId, $art, $menge, $bemerkung, &$id): void {
            if (! array_key_exists($artikelId, $this->artikelDesBereichs($bereichId, [$artikelId]))) {
                throw new BewegungAbgelehnt('Bitte einen Artikel dieses Bereichs wählen.', ['artikel_id' => 'Bitte einen Artikel dieses Bereichs wählen.']);
            }

            $id = $this->speichere([
                'artikel_id' => $artikelId, 'art' => $art, 'menge' => $art === 'schwund' ? -$menge : $menge,
                'einkaufspreis_cent' => null, 'bemerkung' => $bemerkung,
            ], $personId, $jetzt, $art);
        });

        return $id;
    }

    /**
     * Schreib-Transaktion nach S2-R1: Bereich sperren (erste Anweisung), Stichtag frisch lesen, erst dann schreiben.
     * Lock-Wait/Deadlock (1205/1213) wird zur deutschen Meldung (S2-R2).
     *
     * @param callable(string): void $arbeit bekommt „jetzt“ (Y-m-d H:i:s)
     */
    private function schreibend(int $bereichId, callable $arbeit): void
    {
        $model = new BestandsbewegungModel();

        try {
            $model->transaktion(function () use ($bereichId, $arbeit): void {
                (new BereichModel())->sperre([$bereichId]);
                service('zeitraeume')->vergiss();
                $jetzt = service('uhr')->jetzt();

                if (service('zeitraeume')->istEingefroren($jetzt, $bereichId)) {
                    throw new BewegungAbgelehnt('Dieser Zeitraum ist abgeschlossen.');
                }

                $arbeit($jetzt->format('Y-m-d H:i:s'));
            });
        } catch (DatabaseException $e) {
            if (BuchungService::sperrfehlerAbgelehnt($e) !== null) {
                throw new BewegungAbgelehnt('Gerade wird abgerechnet – bitte gleich erneut versuchen.');
            }

            throw $e;
        }
    }

    /**
     * @param list<int> $artikelIds
     *
     * @return array<int, ?int> artikel_id => Gebindegröße; nur Artikel des Bereichs, nicht archiviert, mit Bestandsführung
     */
    private function artikelDesBereichs(int $bereichId, array $artikelIds): array
    {
        $ids = array_values(array_filter(array_unique($artikelIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $treffer = db_connect()->table('artikel a')
            ->select('a.id, a.gebinde_groesse')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('k.bereich_id', $bereichId)->where('a.bestand_fuehren', 1)->where('a.archiviert_at', null)
            ->whereIn('a.id', $ids)->get()->getResultArray();
        $ergebnis = [];

        foreach ($treffer as $zeile) {
            $ergebnis[(int) $zeile['id']] = $zeile['gebinde_groesse'] === null ? null : (int) $zeile['gebinde_groesse'];
        }

        return $ergebnis;
    }

    /**
     * @param array{artikel_id: int, art: string, menge: int, einkaufspreis_cent: ?int, bemerkung: ?string} $bewegung
     */
    private function speichere(array $bewegung, int $personId, string $jetzt, string $aktion): int
    {
        $zeile = $bewegung + ['person_id' => $personId, 'erfolgt_at' => $jetzt];
        $id    = (new BestandsbewegungModel())->insert($zeile, true);

        if ($id === false) {
            throw new DatabaseException('Bewegung konnte nicht gespeichert werden.');
        }

        service('protokollierer')->schreibe($personId, $aktion, 'bestandsbewegungen', (int) $id, null, $zeile);

        return (int) $id;
    }

    /**
     * @param list<int> $artikelIds
     *
     * @return array<int, int> artikel_id => Bestand
     */
    private function bestaende(int $bereichId, array $artikelIds): array
    {
        $bestaende = [];

        foreach ($this->aggregat($bereichId, $artikelIds) as $id => $a) {
            $bestaende[$id] = BestandRechner::bestand($a['anfangsbestand'], $a['lieferungen'] + $a['schwund_erfasst'] + $a['korrekturen'], $a['verkauft']);
        }

        return $bestaende;
    }

    /**
     * Einzige Quelle für Anfangsbestand und Bewegungs-/Verkaufssummen (Bestandsseite und Auszählungs-Soll).
     * Fenster: Beginn des laufenden Zeitraums (inklusiv nur ohne Abschluss) bis `$bis` inklusive (null = offen).
     * Anfangsbestand = Ist der letzten abgeschlossenen Auszählung (sonst 0); stornierte Buchungen zählen nicht.
     *
     * @param list<int> $artikelIds
     *
     * @return array<int, array{anfangsbestand: int, lieferungen: int, schwund_erfasst: int, korrekturen: int, verkauft: int}>
     */
    public function aggregat(int $bereichId, array $artikelIds, ?\DateTimeImmutable $bis = null): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $zeitraeume = service('zeitraeume');
        $beginn     = $zeitraeume->beginn($bereichId)->format('Y-m-d H:i:s');
        $vergleich  = $zeitraeume->beginnInklusiv($bereichId) ? '>=' : '>';
        $db         = db_connect();

        $ist    = [];
        $letzte = (new \App\Models\AuszaehlungModel())->letzteAbgeschlossene($bereichId);

        if ($letzte !== null) {
            $positionen = $db->table('auszaehlung_positionen')->select('artikel_id, ist')
                ->where('auszaehlung_id', $letzte['id'])->whereIn('artikel_id', $artikelIds)->get()->getResultArray();

            foreach ($positionen as $p) {
                $ist[(int) $p['artikel_id']] = (int) $p['ist'];
            }
        }

        $bewegung = function (string $art) use ($db, $artikelIds, $vergleich, $beginn, $bis): array {
            $q = $db->table('bestandsbewegungen')->select('artikel_id, SUM(menge) AS summe')
                ->where('art', $art)->whereIn('artikel_id', $artikelIds)->where("erfolgt_at {$vergleich}", $beginn)->groupBy('artikel_id');

            if ($bis !== null) {
                $q->where('erfolgt_at <=', $bis->format('Y-m-d H:i:s'));
            }

            return $this->summen($q);
        };

        $q = $db->table('buchungen')->select('artikel_id, SUM(menge) AS summe')
            ->whereIn('artikel_id', $artikelIds)->where('storniert_at', null)->where("gebucht_at {$vergleich}", $beginn)->groupBy('artikel_id');

        if ($bis !== null) {
            $q->where('gebucht_at <=', $bis->format('Y-m-d H:i:s'));
        }

        $lieferungen = $bewegung('lieferung');
        $schwund     = $bewegung('schwund');
        $korrekturen = $bewegung('korrektur');
        $verkauft    = $this->summen($q);
        $ergebnis    = [];

        foreach ($artikelIds as $id) {
            $ergebnis[$id] = [
                'anfangsbestand' => $ist[$id] ?? 0, 'lieferungen' => $lieferungen[$id] ?? 0, 'schwund_erfasst' => $schwund[$id] ?? 0,
                'korrekturen' => $korrekturen[$id] ?? 0, 'verkauft' => $verkauft[$id] ?? 0,
            ];
        }

        return $ergebnis;
    }

    /**
     * @return array<int, int>
     */
    private function summen(BaseBuilder $abfrage): array
    {
        $summen = [];

        foreach ($abfrage->get()->getResultArray() as $zeile) {
            $summen[(int) $zeile['artikel_id']] = (int) $zeile['summe'];
        }

        return $summen;
    }
}
