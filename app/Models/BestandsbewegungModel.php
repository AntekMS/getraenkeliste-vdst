<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class BestandsbewegungModel extends Model
{
    use Transaktion;

    protected $table         = 'bestandsbewegungen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['artikel_id', 'art', 'menge', 'einkaufspreis_cent', 'bemerkung', 'person_id', 'erfolgt_at'];
}
