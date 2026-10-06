<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;

/**
 * Laufender Bestand je Artikel (Spec 6.2): Ist der letzten abgeschlossenen Auszählung (sonst 0)
 * + Bewegungen im laufenden Zeitraum − nicht stornierte Buchungsmengen im laufenden Zeitraum
 * (inkl. Korrekturbuchungen). Der Zeitraum kommt aus `zeitraeume()`; je Aufruf wenige Aggregatabfragen.
 */
class BestandService
{
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
     * @param list<int> $artikelIds
     *
     * @return array<int, int> artikel_id => Bestand
     */
    private function bestaende(int $bereichId, array $artikelIds): array
    {
        if ($artikelIds === []) {
            return [];
        }

        $zeitraeume = service('zeitraeume');
        $beginn     = $zeitraeume->beginn($bereichId)->format('Y-m-d H:i:s');
        $vergleich  = $zeitraeume->beginnInklusiv($bereichId) ? '>=' : '>';
        $db         = db_connect();

        $ist    = [];
        $letzte = $db->table('auszaehlungen')->select('id')
            ->where('bereich_id', $bereichId)->where('status', 'abgeschlossen')
            ->orderBy('stichtag', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        if ($letzte !== null) {
            $positionen = $db->table('auszaehlung_positionen')->select('artikel_id, ist')
                ->where('auszaehlung_id', $letzte['id'])->whereIn('artikel_id', $artikelIds)->get()->getResultArray();

            foreach ($positionen as $p) {
                $ist[(int) $p['artikel_id']] = (int) $p['ist'];
            }
        }

        $bewegungen = $this->summen(
            $db->table('bestandsbewegungen')->select('artikel_id, SUM(menge) AS summe')
                ->whereIn('artikel_id', $artikelIds)->where("erfolgt_at {$vergleich}", $beginn)->groupBy('artikel_id'),
        );
        $verkauft = $this->summen(
            $db->table('buchungen')->select('artikel_id, SUM(menge) AS summe')
                ->whereIn('artikel_id', $artikelIds)->where('storniert_at', null)->where("gebucht_at {$vergleich}", $beginn)->groupBy('artikel_id'),
        );

        $bestaende = [];

        foreach ($artikelIds as $id) {
            $bestaende[$id] = BestandRechner::bestand($ist[$id] ?? 0, $bewegungen[$id] ?? 0, $verkauft[$id] ?? 0);
        }

        return $bestaende;
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
