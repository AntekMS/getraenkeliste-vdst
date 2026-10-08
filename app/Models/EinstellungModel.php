<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EinstellungModel extends Model
{
    protected $table            = 'einstellungen';
    protected $primaryKey       = 'schluessel';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $allowedFields    = ['schluessel', 'wert'];
}
