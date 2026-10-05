<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use DateTimeImmutable;

class BuchungModel extends Model
{
    protected $table         = 'buchungen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'vorgang_id', 'konto_id', 'artikel_id', 'menge', 'einzelpreis_cent', 'quelle',
        'gebucht_von_id', 'geraet_id', 'gebucht_at', 'storniert_at', 'storniert_von_id', 'storno_grund',
    ];

    /**
     * Buchungen eines Kontos ab dem Stichtag, neueste zuerst (inkl. stornierter).
     *
     * @return list<array<string, mixed>> Buchungszeilen plus artikel_name, bereich_schluessel, bereich_name
     */
    public function fuerKonto(int $kontoId, DateTimeImmutable $ab): array
    {
        return $this->liste($ab)->where('b.konto_id', $kontoId)->get()->getResultArray();
    }

    /**
     * Was die Person auf Couleur und Bund gebucht hat (nicht auf das eigene Konto), neueste zuerst.
     *
     * @return list<array<string, mixed>> wie fuerKonto, plus konto_name
     */
    public function vonPersonAufSammelkonten(int $personId, DateTimeImmutable $ab): array
    {
        return $this->liste($ab)
            ->select('p.anzeigename AS konto_name')
            ->join('personen p', 'p.id = b.konto_id')
            ->where('p.typ', 'sammelkonto')
            ->where('b.gebucht_von_id', $personId)
            ->get()->getResultArray();
    }

    /**
     * Summe aus Menge x Einzelpreis der nicht stornierten Buchungen eines Kontos in einem Bereich.
     */
    public function offenerBetrag(int $kontoId, string $bereichSchluessel, DateTimeImmutable $ab): int
    {
        $zeile = $this->grundlage($ab)
            ->select('SUM(b.menge * b.einzelpreis_cent) AS summe', false)
            ->where('b.konto_id', $kontoId)
            ->where('bd.schluessel', $bereichSchluessel)
            ->where('b.storniert_at', null)
            ->get()->getRowArray();

        return (int) ($zeile['summe'] ?? 0);
    }

    private function liste(DateTimeImmutable $ab): BaseBuilder
    {
        return $this->grundlage($ab)
            ->select('b.*, a.name AS artikel_name, bd.schluessel AS bereich_schluessel, bd.name AS bereich_name')
            ->orderBy('b.gebucht_at', 'DESC')
            ->orderBy('b.id', 'DESC');
    }

    private function grundlage(DateTimeImmutable $ab): BaseBuilder
    {
        return $this->db->table('buchungen b')
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('bereiche bd', 'bd.id = k.bereich_id')
            ->where('b.gebucht_at >=', $ab->format('Y-m-d H:i:s'));
    }
}
