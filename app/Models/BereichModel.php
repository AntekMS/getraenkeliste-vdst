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
}
