<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class BuchungModel extends Model
{
    protected $table         = 'buchungen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'vorgang_id', 'konto_id', 'artikel_id', 'menge', 'einzelpreis_cent', 'quelle',
        'gebucht_von_id', 'geraet_id', 'gebucht_at', 'storniert_at', 'storniert_von_id', 'storno_grund',
    ];
}
