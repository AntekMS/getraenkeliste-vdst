<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class BereichModel extends Model
{
    protected $table         = 'bereiche';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['schluessel', 'name', 'verwalter_rolle', 'aktiv'];

    /**
     * @return list<array<string, mixed>>
     */
    public function aktive(): array
    {
        return $this->where('aktiv', 1)->orderBy('id')->findAll();
    }

    /**
     * Sperrt die Bereichszeilen bis zum Ende der laufenden Transaktion (Entscheidung 1: Buchen, Storno,
     * Bewegungen und Abschluss serialisieren sich je Bereich). Nur innerhalb einer Transaktion aufrufen,
     * und zwar als erste Abfrage darin, damit danach gelesene Stichtage aktuell sind. Sortiert gegen Deadlocks.
     *
     * @param list<int> $bereichIds
     */
    public function sperre(array $bereichIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $bereichIds)));
        sort($ids);

        if ($ids === []) {
            return;
        }

        $platzhalter = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->query("SELECT id FROM bereiche WHERE id IN ({$platzhalter}) ORDER BY id FOR UPDATE", $ids);
    }
}
