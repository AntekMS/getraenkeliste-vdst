<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class GeraetModel extends Model
{
    use Transaktion;

    protected $table         = 'geraete';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'token_hash', 'zuletzt_gesehen_at', 'gesperrt_at'];

    /**
     * @return ?array<string, mixed>
     */
    public function findeNachToken(string $token): ?array
    {
        return $this->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function alle(): array
    {
        return $this->orderBy('name')->orderBy('id')->findAll();
    }
}
