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
     * Legt einen Admin an (Mitglied, Gruppe aktiv). Die PIN bleibt leer; die Pflichtseite
     * nach dem ersten Login fordert sie an.
     */
    public function legeAdminAn(string $vorname, string $nachname, string $benutzername, string $passwort): int
    {
        $this->db->transStart();

        $id = (int) $this->insert([
            'vorname'                    => $vorname,
            'nachname'                   => $nachname,
            'anzeigename'                => trim($vorname . ' ' . $nachname),
            'gruppe'                     => 'aktiv',
            'typ'                        => 'mitglied',
            'benutzername'               => $benutzername,
            'passwort_hash'              => password_hash($passwort, PASSWORD_DEFAULT),
            'passwort_wechsel_erzwingen' => 0,
        ], true);

        (new PersonRolleModel())->insert(['person_id' => $id, 'rolle' => 'admin']);

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new RuntimeException('Admin konnte nicht angelegt werden.');
        }

        return $id;
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
