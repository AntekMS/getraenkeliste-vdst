<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;

class PersonModel extends Model
{
    protected $table         = 'personen';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'vorname', 'nachname', 'anzeigename', 'gruppe', 'typ', 'benutzername',
        'passwort_hash', 'passwort_wechsel_erzwingen', 'login_fehlversuche', 'login_gesperrt_bis',
        'pin_hash', 'pin_fehlversuche', 'pin_gesperrt_bis', 'archiviert_at',
    ];

    /**
     * @return list<string> Rollen aus person_rollen, plus 'mitglied' bei typ = mitglied
     */
    public function rollen(int $personId): array
    {
        $person = $this->find($personId);

        if ($person === null) {
            return [];
        }

        $rollen = array_column(
            $this->db->table('person_rollen')->select('rolle')->where('person_id', $personId)->orderBy('rolle')->get()->getResultArray(),
            'rolle',
        );

        return $person['typ'] === 'mitglied' ? ['mitglied', ...$rollen] : $rollen;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function findeAktivNachBenutzername(string $benutzername): ?array
    {
        return $this->where('benutzername', $benutzername)->where('archiviert_at', null)->first();
    }

    /**
     * @param 'Couleur'|'Bund' $name
     */
    public function sammelkontoId(string $name): int
    {
        $konto = $this->where('typ', 'sammelkonto')->where('anzeigename', $name)->first();

        if ($konto === null) {
            throw new RuntimeException("Sammelkonto {$name} fehlt.");
        }

        return (int) $konto['id'];
    }

    /**
     * @param array<string, mixed> $person
     */
    public function istAktiv(array $person): bool
    {
        return $person['archiviert_at'] === null;
    }
}
