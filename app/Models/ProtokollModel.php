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
}
