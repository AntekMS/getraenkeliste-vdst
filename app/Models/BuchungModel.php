<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use DateTimeImmutable;

class BuchungModel extends Model
{
    use Transaktion;

    private const FORMAT = 'Y-m-d H:i:s';

    protected $table         = 'buchungen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'vorgang_id', 'konto_id', 'artikel_id', 'menge', 'einzelpreis_cent', 'quelle',
        'gebucht_von_id', 'geraet_id', 'gebucht_at', 'storniert_at', 'storniert_von_id', 'storno_grund', 'bemerkung',
    ];

    /**
     * Buchungen eines Kontos ab `$ab` (inklusiv nur vor der ersten Auszählung), neueste zuerst (inkl. stornierter).
     * Mit `$bereichId` nur dieser Bereich – der laufende Zeitraum beginnt je Bereich anders.
     *
     * @return list<array<string, mixed>> Buchungszeilen plus artikel_name, bereich_id, bereich_schluessel, bereich_name
     */
    public function fuerKonto(int $kontoId, DateTimeImmutable $ab, bool $abInklusiv = true, ?int $bereichId = null): array
    {
        return $this->liste($ab, $abInklusiv, $bereichId)->where('b.konto_id', $kontoId)->get()->getResultArray();
    }

    /**
     * Was die Person auf Couleur und Bund gebucht hat (nicht auf das eigene Konto), neueste zuerst.
     *
     * @return list<array<string, mixed>> wie fuerKonto, plus konto_name
     */
    public function vonPersonAufSammelkonten(int $personId, DateTimeImmutable $ab, bool $abInklusiv = true, ?int $bereichId = null): array
    {
        return $this->liste($ab, $abInklusiv, $bereichId)
            ->select('p.anzeigename AS konto_name')
            ->join('personen p', 'p.id = b.konto_id')
            ->where('p.typ', 'sammelkonto')
            ->where('b.gebucht_von_id', $personId)
            ->get()->getResultArray();
    }

    /**
     * Summe aus Menge x Einzelpreis der nicht stornierten Buchungen eines Kontos in einem Bereich ab `$ab`.
     */
    public function offenerBetrag(int $kontoId, string $bereichSchluessel, DateTimeImmutable $ab, bool $abInklusiv = true): int
    {
        return $this->summe($this->grundlage($ab, $abInklusiv)->where('b.konto_id', $kontoId)->where('bd.schluessel', $bereichSchluessel));
    }

    /**
     * Eigene Summe in einem abgeschlossenen Zeitraum: `$von` exklusiv (inklusiv nur beim ersten Zeitraum ab
     * Inbetriebnahme), `$bis` (Stichtag) inklusiv; ohne stornierte.
     */
    public function summeImZeitraum(int $kontoId, int $bereichId, DateTimeImmutable $von, DateTimeImmutable $bis, bool $vonInklusiv = false): int
    {
        return $this->summe($this->grundlage($von, $vonInklusiv, $bereichId)
            ->where('b.konto_id', $kontoId)
            ->where('b.gebucht_at <=', $bis->format(self::FORMAT)));
    }

    /**
     * Verwaltungsliste des Warts: Buchungen eines Bereichs ab `$von`, neueste zuerst (inkl. stornierter), danach `paginate()`.
     * Filter: `person` (Konto-ID), `artikel` (ID), `tag` (`JJJJ-MM-TT`); nicht skalare oder leere Werte zählen nicht.
     *
     * @param array<string, mixed> $filter
     */
    public function imZeitraum(int $bereichId, DateTimeImmutable $von, bool $vonInklusiv, array $filter): static
    {
        $this->select('buchungen.*, a.name AS artikel_name, k.bereich_id, konto.anzeigename AS konto_name, von.anzeigename AS gebucht_von_name')
            ->join('artikel a', 'a.id = buchungen.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('personen konto', 'konto.id = buchungen.konto_id')
            ->join('personen von', 'von.id = buchungen.gebucht_von_id', 'left')
            ->where('k.bereich_id', $bereichId)
            ->where($vonInklusiv ? 'buchungen.gebucht_at >=' : 'buchungen.gebucht_at >', $von->format(self::FORMAT))
            ->orderBy('buchungen.gebucht_at', 'DESC')
            ->orderBy('buchungen.id', 'DESC');

        $person  = $filter['person'] ?? null;
        $artikel = $filter['artikel'] ?? null;
        $tag     = $filter['tag'] ?? null;

        if (is_int($person) && $person > 0) {
            $this->where('buchungen.konto_id', $person);
        }

        if (is_int($artikel) && $artikel > 0) {
            $this->where('buchungen.artikel_id', $artikel);
        }

        if (is_string($tag) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag) === 1) {
            $this->where('buchungen.gebucht_at >=', $tag . ' 00:00:00')->where('buchungen.gebucht_at <=', $tag . ' 23:59:59');
        }

        return $this;
    }

    private function summe(BaseBuilder $builder): int
    {
        $zeile = $builder->select('SUM(b.menge * CAST(b.einzelpreis_cent AS SIGNED)) AS summe', false)
            ->where('b.storniert_at', null)
            ->get()->getRowArray();

        return (int) ($zeile['summe'] ?? 0);
    }

    private function liste(DateTimeImmutable $ab, bool $abInklusiv, ?int $bereichId): BaseBuilder
    {
        return $this->grundlage($ab, $abInklusiv, $bereichId)
            ->select('b.*, a.name AS artikel_name, bd.id AS bereich_id, bd.schluessel AS bereich_schluessel, bd.name AS bereich_name')
            ->orderBy('b.gebucht_at', 'DESC')
            ->orderBy('b.id', 'DESC');
    }

    private function grundlage(DateTimeImmutable $ab, bool $abInklusiv, ?int $bereichId = null): BaseBuilder
    {
        $builder = $this->db->table('buchungen b')
            ->join('artikel a', 'a.id = b.artikel_id')
            ->join('kategorien k', 'k.id = a.kategorie_id')
            ->join('bereiche bd', 'bd.id = k.bereich_id')
            ->where($abInklusiv ? 'b.gebucht_at >=' : 'b.gebucht_at >', $ab->format(self::FORMAT));

        return $bereichId === null ? $builder : $builder->where('bd.id', $bereichId);
    }
}
