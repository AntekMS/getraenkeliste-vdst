<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AnmeldeTokenModel extends Model
{
    protected $table         = 'anmelde_tokens';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['person_id', 'selector', 'token_hash', 'gueltig_bis', 'zuletzt_genutzt_at'];

    /**
     * Löscht alle Remember-Tokens der Person (Passwortwechsel, Archivierung, Abmelden).
     */
    public function loescheFuerPerson(int $personId): void
    {
        $this->where('person_id', $personId)->delete();
    }
}
