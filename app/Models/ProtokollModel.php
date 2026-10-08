<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ProtokollModel extends Model
{
    protected $table         = 'protokoll';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['person_id', 'aktion', 'tabelle', 'datensatz_id', 'alt', 'neu', 'erfolgt_at'];

    /**
     * Query-Builder fuer die Admin-Ansicht (neueste zuerst, mit Personenname), danach `paginate()`.
     * Filter: `person` (ID), `tabelle`, `von`/`bis` (Y-m-d, beide Tage eingeschlossen); leere Werte zaehlen nicht.
     *
     * @param array{person?: ?int, tabelle?: ?string, von?: ?string, bis?: ?string} $filter
     */
    public function gefiltert(array $filter): static
    {
        $this->select('protokoll.*, personen.anzeigename')
            ->join('personen', 'personen.id = protokoll.person_id', 'left')
            ->orderBy('protokoll.erfolgt_at', 'DESC')
            ->orderBy('protokoll.id', 'DESC');

        if (! empty($filter['person'])) {
            $this->where('protokoll.person_id', (int) $filter['person']);
        }

        if (! empty($filter['tabelle'])) {
            $this->where('protokoll.tabelle', $filter['tabelle']);
        }

        if (! empty($filter['von'])) {
            $this->where('protokoll.erfolgt_at >=', $filter['von'] . ' 00:00:00');
        }

        if (! empty($filter['bis'])) {
            $this->where('protokoll.erfolgt_at <=', $filter['bis'] . ' 23:59:59');
        }

        return $this;
    }

    /**
     * @return list<string> Tabellennamen, die im Protokoll vorkommen
     */
    public function tabellen(): array
    {
        return array_column($this->db->table('protokoll')->distinct()->select('tabelle')->orderBy('tabelle')->get()->getResultArray(), 'tabelle');
    }
}
