<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AuszaehlungModel extends Model
{
    use Transaktion;

    protected $table         = 'auszaehlungen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'bereich_id', 'art', 'stichtag', 'zeitraum_von', 'status', 'erstellt_von_id',
        'abgeschlossen_at', 'datei_pfad', 'bemerkung',
    ];

    /**
     * Die zuletzt abgeschlossene Auszählung des Bereichs (größter Stichtag) oder null.
     *
     * @return array<string, mixed>|null
     */
    public function letzteAbgeschlossene(int $bereichId): ?array
    {
        return $this->abgeschlossenQuery($bereichId)->first();
    }

    /**
     * Der offene Entwurf des Bereichs oder null.
     *
     * @return array<string, mixed>|null
     */
    public function entwurf(int $bereichId): ?array
    {
        return $this->where('bereich_id', $bereichId)->where('status', 'entwurf')->orderBy('id', 'DESC')->first();
    }

    /**
     * Alle abgeschlossenen Auszählungen des Bereichs, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function abgeschlossene(int $bereichId): array
    {
        return $this->abgeschlossenQuery($bereichId)->findAll();
    }

    /**
     * Abgeschlossene Auszählungen des Bereichs für die Liste (neueste zuerst) mit `abgeschlossen_von` (Anzeigename).
     *
     * @return list<array<string, mixed>>
     */
    public function liste(int $bereichId): array
    {
        return $this->db->table('auszaehlungen au')
            ->select('au.*, p.anzeigename AS abgeschlossen_von')
            ->join('personen p', 'p.id = au.erstellt_von_id')
            ->where('au.bereich_id', $bereichId)->where('au.status', 'abgeschlossen')
            ->orderBy('au.stichtag', 'DESC')->orderBy('au.id', 'DESC')
            ->get()->getResultArray();
    }

    private function abgeschlossenQuery(int $bereichId): static
    {
        return $this->where('bereich_id', $bereichId)->where('status', 'abgeschlossen')
            ->orderBy('stichtag', 'DESC')->orderBy('id', 'DESC');
    }
}
