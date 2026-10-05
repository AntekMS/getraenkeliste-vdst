<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class GeraetModel extends Model
{
    protected $table         = 'geraete';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'token_hash', 'zuletzt_gesehen_at', 'gesperrt_at'];
}
