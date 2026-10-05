<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class FreischaltcodeModel extends Model
{
    protected $table         = 'freischaltcodes';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['code_hash', 'gueltig_bis', 'erstellt_von_id', 'eingeloest_at'];
}
