<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class ArtikelModel extends Model
{
    protected $table         = 'artikel';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'kategorie_id', 'name', 'preis_cent', 'einheit', 'gebinde_groesse',
        'mindestbestand', 'bestand_fuehren', 'sortierung', 'archiviert_at',
    ];

    /**
     * Buchbare Artikel: nur aktive Bereiche, nicht archivierte Kategorien und Artikel.
     *
     * @return list<array{schluessel: string, name: string, kategorien: list<array{id: int, name: string, artikel: list<array{id: int, name: string, einheit: string, preis_cent: int}>}>}>
     */
    public function buchbar(): array
    {
        $zeilen = $this->buchbarQuery()
            ->select('a.id AS artikel_id, a.name AS artikel_name, a.einheit, a.preis_cent, k.id AS kategorie_id, k.name AS kategorie_name, b.schluessel, b.name AS bereich_name')
            ->orderBy('b.id')->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();

        $bereiche = [];

        foreach ($zeilen as $z) {
            $bereiche[$z['schluessel']] ??= ['schluessel' => $z['schluessel'], 'name' => $z['bereich_name'], 'kategorien' => []];
            $bereiche[$z['schluessel']]['kategorien'][$z['kategorie_id']] ??= ['id' => (int) $z['kategorie_id'], 'name' => $z['kategorie_name'], 'artikel' => []];
            $bereiche[$z['schluessel']]['kategorien'][$z['kategorie_id']]['artikel'][] = [
                'id' => (int) $z['artikel_id'], 'name' => $z['artikel_name'],
                'einheit' => $z['einheit'], 'preis_cent' => (int) $z['preis_cent'],
            ];
        }

        foreach ($bereiche as &$bereich) {
            $bereich['kategorien'] = array_values($bereich['kategorien']);
        }
        unset($bereich);

        return array_values($bereiche);
    }

    /**
     * @return ?array<string, mixed> Artikel inkl. bereich_schluessel, null wenn nicht buchbar
     */
    public function findeBuchbar(int $id): ?array
    {
        return $this->buchbarQuery()
            ->select('a.*, b.schluessel AS bereich_schluessel')
            ->where('a.id', $id)
            ->get()->getRowArray();
    }

    private function buchbarQuery(): BaseBuilder
    {
        return $this->db->table('artikel a')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('bereiche b', 'b.id = k.bereich_id')
            ->where('b.aktiv', 1)
            ->where('k.archiviert_at', null)
            ->where('a.archiviert_at', null);
    }
}
