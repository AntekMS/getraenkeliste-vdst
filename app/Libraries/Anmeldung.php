<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\PersonModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Login am eigenen Gerät: Passwortprüfung mit Fehlversuch-Sperre und Sitzung.
 */
final class Anmeldung
{
    public const MELDUNG_FALSCH   = 'Benutzername oder Passwort falsch.';
    public const MELDUNG_GESPERRT = 'Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.';

    private const DATUMSFORMAT = 'Y-m-d H:i:s';

    /**
     * Prüft die Sperre VOR dem Passwort: Solange gesperrt, wird auch das richtige Passwort
     * abgelehnt und der Zähler nicht verändert. Unbekannte und archivierte Personen
     * bekommen dieselbe Meldung wie ein falsches Passwort.
     *
     * @return array{ok: bool, person: ?array<string, mixed>, meldung: ?string}
     */
    public function pruefePasswort(string $benutzername, string $passwort): array
    {
        $name   = Anmelderegeln::benutzernameNormalisieren($benutzername);
        $model  = new PersonModel();
        $person = $name === null ? null : $model->findeAktivNachBenutzername($name);

        if ($person === null || $person['passwort_hash'] === null) {
            return ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_FALSCH];
        }

        $jetzt = service('uhr')->jetzt();
        $bis   = $person['login_gesperrt_bis'] === null
            ? null
            : new DateTimeImmutable($person['login_gesperrt_bis'], new DateTimeZone('Europe/Berlin'));

        if (PinSperre::istGesperrt($bis, $jetzt)) {
            return ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_GESPERRT];
        }

        if (! password_verify($passwort, $person['passwort_hash'])) {
            $neu = PinSperre::nachFehlversuch((int) $person['login_fehlversuche'], $jetzt);
            $model->update((int) $person['id'], [
                'login_fehlversuche' => $neu['fehlversuche'],
                'login_gesperrt_bis' => $neu['gesperrt_bis']?->format(self::DATUMSFORMAT),
            ]);

            return ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_FALSCH];
        }

        $model->update((int) $person['id'], ['login_fehlversuche' => 0, 'login_gesperrt_bis' => null]);

        return ['ok' => true, 'person' => $person, 'meldung' => null];
    }

    public function anmelden(int $personId): void
    {
        session()->regenerate(true);
        session()->set('person_id', $personId);
    }

    public function abmelden(): void
    {
        session()->destroy();
        session()->remove(array_keys($_SESSION ?? []));
    }

    /**
     * Aktuelle, nicht archivierte Person; sonst Abmeldung und null.
     *
     * @return ?array<string, mixed>
     */
    public function person(): ?array
    {
        $id = session('person_id');

        if ($id === null) {
            return null;
        }

        $model  = new PersonModel();
        $person = $model->find((int) $id);

        if ($person === null || ! $model->istAktiv($person)) {
            $this->abmelden();

            return null;
        }

        return $person;
    }

    /**
     * @return list<string>
     */
    public function rollen(): array
    {
        $person = $this->person();

        return $person === null ? [] : (new PersonModel())->rollen((int) $person['id']);
    }
}
