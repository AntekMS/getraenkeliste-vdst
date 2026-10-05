<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class KategorieModel extends Model
{
    protected $table         = 'kategorien';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['bereich_id', 'name', 'sortierung', 'archiviert_at'];
}
