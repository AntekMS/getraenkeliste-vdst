<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use DateTimeImmutable;
use DateTimeZone;

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

    /**
     * Die letzten `$anzahl` abgeschlossenen Auszählungen des Bereichs (neueste zuerst), je mit `zeitraum` (siehe `zeitraum()`).
     * Lädt eine Auszählung mehr, um zu wissen, ob die älteste geladene einen Vorgänger hat (eine Abfrage).
     *
     * @return list<array<string, mixed>>
     */
    public function letzteMitZeitraum(int $bereichId, int $anzahl): array
    {
        $zeilen = $this->abgeschlossenQuery($bereichId)->findAll(max(1, $anzahl) + 1);
        $liste  = [];

        foreach (array_slice($zeilen, 0, max(1, $anzahl)) as $i => $zeile) {
            $liste[] = $zeile + ['zeitraum' => self::zeitraum($zeile, ! isset($zeilen[$i + 1]))];
        }

        return $liste;
    }

    /**
     * Zeitraum einer abgeschlossenen Auszählung = (zeitraum_von, stichtag]; `zeitraum_von` gehört nur dazu, wenn es die
     * erste abgeschlossene Auszählung des Bereichs ist (Beginn = Inbetriebnahme). Einzige Stelle dieser Regel (Export, Statistik).
     *
     * @param array<string, mixed> $auszaehlung
     *
     * @return array{von: DateTimeImmutable, bis: DateTimeImmutable, von_inklusiv: bool}
     */
    public static function zeitraum(array $auszaehlung, bool $ersteAbgeschlossene): array
    {
        $zone = new DateTimeZone('Europe/Berlin');

        return [
            'von'          => new DateTimeImmutable((string) $auszaehlung['zeitraum_von'], $zone),
            'bis'          => new DateTimeImmutable((string) $auszaehlung['stichtag'], $zone),
            'von_inklusiv' => $ersteAbgeschlossene,
        ];
    }

    private function abgeschlossenQuery(int $bereichId): static
    {
        return $this->where('bereich_id', $bereichId)->where('status', 'abgeschlossen')
            ->orderBy('stichtag', 'DESC')->orderBy('id', 'DESC');
    }
}
