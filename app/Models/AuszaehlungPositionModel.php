<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AuszaehlungPositionModel extends Model
{
    use Transaktion;

    protected $table         = 'auszaehlung_positionen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'auszaehlung_id', 'artikel_id', 'anfangsbestand', 'lieferungen', 'schwund_erfasst', 'korrekturen',
        'verkauft', 'soll', 'ist', 'differenz', 'start', 'preis_cent',
    ];

    /**
     * Positionen einer Auszählung in Kategorie-/Artikel-Sortierung, mit Artikel- und Kategoriename.
     *
     * @return list<array<string, mixed>>
     */
    public function fuer(int $auszaehlungId): array
    {
        return $this->db->table('auszaehlung_positionen p')
            ->select('p.*, a.name AS artikel_name, a.einheit, a.gebinde_groesse, k.id AS kategorie_id, k.name AS kategorie_name')
            ->join('artikel a', 'a.id = p.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->where('p.auszaehlung_id', $auszaehlungId)
            ->orderBy('k.sortierung')->orderBy('k.id')->orderBy('a.sortierung')->orderBy('a.id')
            ->get()->getResultArray();
    }
}
