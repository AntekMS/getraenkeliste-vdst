<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Zusammengesetzter Schlüssel (person_id, rolle): nur einfügen und per where() löschen.
 */
class PersonRolleModel extends Model
{
    protected $table            = 'person_rollen';
    protected $primaryKey       = 'person_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $allowedFields    = ['person_id', 'rolle'];
}
