<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use RuntimeException;

class PersonModel extends Model
{
    use Transaktion;

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
     * Mitglieder (keine Sammelkonten) für die Admin-Liste.
     *
     * @param ?string $gruppe aktiv|ah|sonstige oder null für alle
     *
     * @return list<array<string, mixed>>
     */
    public function mitglieder(?string $gruppe, bool $archiviert): array
    {
        $builder = $this->where('typ', 'mitglied');

        if ($gruppe !== null) {
            $builder->where('gruppe', $gruppe);
        }

        $builder = $archiviert ? $builder->where('archiviert_at IS NOT NULL') : $builder->where('archiviert_at', null);

        return $builder->orderBy('nachname')->orderBy('vorname')->findAll();
    }

    /**
     * Legt ein Mitglied mit Einmal-Passwort an (Passwortwechsel beim ersten Login erzwungen, keine PIN).
     * Läuft innerhalb der Transaktion des Aufrufers; Fehler werfen dort eine Exception.
     *
     * @param array{vorname: string, nachname: string, anzeigename: string, gruppe: string, benutzername: string} $daten
     */
    public function legeMitgliedAn(array $daten, string $einmalPasswort): int
    {
        $id = $this->insert([
            ...$daten,
            'typ'                        => 'mitglied',
            'passwort_hash'              => password_hash($einmalPasswort, PASSWORD_DEFAULT),
            'passwort_wechsel_erzwingen' => 1,
        ], true);

        if ($id === false) {
            throw new RuntimeException('Person konnte nicht angelegt werden.');
        }

        return (int) $id;
    }

    public function benutzernameVergeben(string $benutzername, ?int $ausserPersonId = null): bool
    {
        $builder = $this->where('benutzername', $benutzername);

        if ($ausserPersonId !== null) {
            $builder->where('id !=', $ausserPersonId);
        }

        return $builder->first() !== null;
    }

    /**
     * @return list<string> mb_strtolower("vorname nachname") aller Mitglieder (auch archivierte)
     */
    public function vorhandeneNamen(): array
    {
        return array_map(
            static fn (array $p): string => mb_strtolower($p['vorname'] . ' ' . $p['nachname']),
            $this->where('typ', 'mitglied')->findAll(),
        );
    }

    /**
     * @return list<string>
     */
    public function vorhandeneBenutzernamen(): array
    {
        return array_column($this->select('benutzername')->where('benutzername IS NOT NULL')->findAll(), 'benutzername');
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
     * Namenskacheln des Tablets. `zuletzt` = die 12 aktiven Mitglieder (ohne Gruppe sonstige) mit der jüngsten Buchung
     * (MAX(gebucht_at) über alle Buchungen auf ihr Konto, auch stornierte; Personen ohne Buchung fehlen dort).
     *
     * @return array{fest: list<array<string, mixed>>, zuletzt: list<array<string, mixed>>, alle: list<array<string, mixed>>}
     */
    public function tabletKacheln(): array
    {
        $auswahl = "p.id, p.anzeigename, p.gruppe, (p.typ = 'sammelkonto' OR p.pin_hash IS NOT NULL) AS hat_pin";

        $fest = $this->db->query(
            "SELECT {$auswahl} FROM personen p WHERE p.typ = 'sammelkonto' AND p.archiviert_at IS NULL
             AND p.anzeigename IN ('Couleur', 'Bund') ORDER BY p.anzeigename = 'Bund', p.anzeigename",
        )->getResultArray();

        $zuletzt = $this->db->query(
            "SELECT {$auswahl} FROM personen p
             JOIN (SELECT konto_id, MAX(gebucht_at) AS zuletzt FROM buchungen GROUP BY konto_id) b ON b.konto_id = p.id
             WHERE p.typ = 'mitglied' AND p.archiviert_at IS NULL AND p.gruppe <> 'sonstige'
             ORDER BY b.zuletzt DESC, p.anzeigename LIMIT 12",
        )->getResultArray();

        $alle = $this->db->query(
            "SELECT {$auswahl} FROM personen p WHERE p.typ = 'mitglied' AND p.archiviert_at IS NULL ORDER BY p.anzeigename, p.id",
        )->getResultArray();

        $normal = static fn (array $zeilen): array => array_map(
            static fn (array $z): array => ['id' => (int) $z['id'], 'anzeigename' => $z['anzeigename'], 'gruppe' => $z['gruppe'], 'hat_pin' => (bool) $z['hat_pin']],
            $zeilen,
        );

        return ['fest' => $normal($fest), 'zuletzt' => $normal($zuletzt), 'alle' => $normal($alle)];
    }

    /**
     * @param array<string, mixed> $person
     */
    public function istAktiv(array $person): bool
    {
        return $person['archiviert_at'] === null;
    }

    /**
     * Was die Person nach dem ersten Login (oder einem PIN-Reset) noch einrichten muss.
     *
     * @param array<string, mixed> $person
     *
     * @return array{passwort: bool, pin: bool}
     */
    public function brauchtEinrichtung(array $person): array
    {
        return [
            'passwort' => (int) $person['passwort_wechsel_erzwingen'] === 1,
            'pin'      => $person['pin_hash'] === null,
        ];
    }
}
