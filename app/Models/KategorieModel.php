<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class KategorieModel extends Model
{
    use Transaktion;

    protected $table         = 'kategorien';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['bereich_id', 'name', 'sortierung', 'archiviert_at'];

    /**
     * Nächste Sortierposition am Ende des Bereichs.
     */
    public function naechsteSortierung(int $bereichId): int
    {
        $max = $this->selectMax('sortierung')->where('bereich_id', $bereichId)->first();

        return (int) ($max['sortierung'] ?? 0) + 1;
    }

    /**
     * Tauscht die Position mit dem Nachbarn (unter den nicht archivierten Kategorien des Bereichs).
     * Am Rand passiert nichts. Die Positionen werden dabei lückenlos neu vergeben (Gleichstände).
     *
     * @param 'hoch'|'runter' $richtung
     */
    public function verschiebe(int $id, string $richtung): void
    {
        $kategorie = $this->find($id);

        if ($kategorie === null || $kategorie['archiviert_at'] !== null) {
            return;
        }

        $this->transaktion(function () use ($kategorie, $id, $richtung): void {
            $ids = array_map('intval', array_column(
                $this->where('bereich_id', $kategorie['bereich_id'])->where('archiviert_at', null)
                    ->orderBy('sortierung')->orderBy('id')->findAll(),
                'id',
            ));

            if (! Sortierung::tausche($ids, $id, $richtung)) {
                return;
            }

            foreach ($ids as $position => $kategorieId) {
                $this->update($kategorieId, ['sortierung' => $position + 1]);
            }
        });
    }
}
