<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Login am eigenen Gerät: Passwortprüfung mit Fehlversuch-Sperre und Sitzung.
 */
final class Anmeldung
{
    public const MELDUNG_FALSCH   = 'Benutzername oder Passwort falsch.';
    public const MELDUNG_GESPERRT = 'Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.';

    public const MERK_COOKIE = 'gl_merken';

    /** Session-Schlüssel: Fingerabdruck des Passwort-Hashes bei der Anmeldung (Passwortwechsel/-Reset beendet andere Sitzungen). */
    public const SITZUNG_FINGERABDRUCK = 'passwort_fingerabdruck';

    public const PRUEFUNG_OK       = 'ok';
    public const PRUEFUNG_FALSCH   = 'falsch';
    public const PRUEFUNG_GESPERRT = 'gesperrt';

    /** Bcrypt-Hash ohne zugehöriges Passwort: unbekannte Benutzer kosten dieselbe Prüfzeit wie bekannte. */
    private const DUMMY_HASH = '$2y$10$r6IHv5clgzpWCQFT/civUOBD087BsAsAJbEMvL3UOSqCqSEBpJdDy';

    /** @var ?array<string, mixed> Cookie-Änderung dieses Requests (setzen oder ['loeschen' => true]) */
    private ?array $merkAktion = null;

    /**
     * Unbekannte und archivierte Personen bekommen dieselbe Meldung wie ein falsches Passwort.
     * Sperre und Zähler: {@see passwortPruefen()}.
     *
     * @return array{ok: bool, person: ?array<string, mixed>, meldung: ?string}
     */
    public function pruefePasswort(string $benutzername, string $passwort): array
    {
        $name   = Anmelderegeln::benutzernameNormalisieren($benutzername);
        $person = $name === null ? null : (new PersonModel())->findeAktivNachBenutzername($name);

        if ($person === null || $person['passwort_hash'] === null) {
            password_verify($passwort, self::DUMMY_HASH);

            return ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_FALSCH];
        }

        return match ($this->passwortPruefen($person, $passwort)) {
            self::PRUEFUNG_OK       => ['ok' => true, 'person' => $person, 'meldung' => null],
            self::PRUEFUNG_GESPERRT => ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_GESPERRT],
            default                 => ['ok' => false, 'person' => null, 'meldung' => self::MELDUNG_FALSCH],
        };
    }

    /**
     * Passwortprüfung mit Login-Sperre (Login und Bestätigung mit aktuellem Passwort im Konto).
     * Der Versuch wird VOR dem Hash-Vergleich atomar beansprucht; solange gesperrt, wird auch das
     * richtige Passwort abgelehnt und der Zähler nicht verändert.
     *
     * @param array<string, mixed> $person
     *
     * @return self::PRUEFUNG_*
     */
    public function passwortPruefen(array $person, string $passwort): string
    {
        $zaehler = new Versuchszaehler('login');

        if (! $zaehler->beanspruchen((int) $person['id'], service('uhr')->jetzt())) {
            return self::PRUEFUNG_GESPERRT;
        }

        if (! password_verify($passwort, (string) $person['passwort_hash'])) {
            return self::PRUEFUNG_FALSCH;
        }

        $zaehler->erfolg((int) $person['id']);

        return self::PRUEFUNG_OK;
    }

    public static function fingerabdruck(?string $passwortHash): string
    {
        return hash('sha256', (string) $passwortHash);
    }

    public function anmelden(int $personId): void
    {
        session()->regenerate(true);
        session()->set([
            'person_id'                 => $personId,
            self::SITZUNG_FINGERABDRUCK => self::fingerabdruck((new PersonModel())->find($personId)['passwort_hash'] ?? null),
        ]);
    }

    /**
     * Nach eigenem Passwortwechsel: die aktuelle Sitzung bleibt gültig, alle anderen enden beim nächsten Request.
     */
    public function fingerabdruckAktualisieren(string $neuerPasswortHash): void
    {
        session()->set(self::SITZUNG_FINGERABDRUCK, self::fingerabdruck($neuerPasswortHash));
    }

    /**
     * Stellt ohne Sitzung die Anmeldung aus dem Cookie `gl_merken` wieder her (Token wird rotiert).
     * Archivierte Person: nicht angemeldet, ihre Tokens werden gelöscht. Ungültiges Cookie wird verworfen.
     */
    public function ausCookieAnmelden(?string $cookie): bool
    {
        if ($cookie === null || $cookie === '') {
            return false;
        }

        $tokens  = new AnmeldeTokenModel();
        $treffer = $tokens->rotiere($cookie, service('uhr')->jetzt());

        if ($treffer === null) {
            $this->merkCookieLoeschen();

            return false;
        }

        $personen = new PersonModel();
        $person   = $personen->find($treffer['person_id']);

        if ($person === null || ! $personen->istAktiv($person)) {
            $tokens->loescheFuerPerson($treffer['person_id']);
            $this->merkCookieLoeschen();

            return false;
        }

        $this->anmelden($treffer['person_id']);
        $this->merkCookieSetzen($treffer['cookie']);

        return true;
    }

    public function merkenEinrichten(int $personId): void
    {
        $this->merkCookieSetzen((new AnmeldeTokenModel())->erzeuge($personId, service('uhr')->jetzt()));
    }

    /**
     * Abmelden am eigenen Gerät: nur das Token dieses Cookies wird gelöscht, das Cookie läuft ab.
     */
    public function merkenBeenden(?string $cookie): void
    {
        if ($cookie !== null && $cookie !== '') {
            (new AnmeldeTokenModel())->loescheCookie($cookie);
        }

        $this->merkCookieLoeschen();
    }

    public function merkCookieLoeschen(): void
    {
        $this->merkAktion = ['loeschen' => true];
        response()->deleteCookie(self::MERK_COOKIE);
    }

    /**
     * Wendet die in diesem Request ausgeführte Cookie-Änderung auf die tatsächlich gesendete Antwort an
     * (Filter `after`), damit sie auch bei Redirects ohne `withCookies()` nicht verloren geht.
     */
    public function merkCookieAnwenden(ResponseInterface $antwort): void
    {
        if ($this->merkAktion === null) {
            return;
        }

        if (isset($this->merkAktion['loeschen'])) {
            $antwort->deleteCookie(self::MERK_COOKIE);

            return;
        }

        $antwort->setCookie($this->merkAktion);
    }

    private function merkCookieSetzen(string $wert): void
    {
        $this->merkAktion = [
            'name'     => self::MERK_COOKIE,
            'value'    => $wert,
            'expire'   => AnmeldeTokenModel::GUELTIG_TAGE * 86400,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => config('Cookie')->secure,
        ];
        response()->setCookie($this->merkAktion);
    }

    public function abmelden(): void
    {
        session()->destroy();
        session()->remove(array_keys($_SESSION ?? []));
    }

    /**
     * Aktuelle, nicht archivierte Person, deren Passwort seit der Anmeldung nicht gewechselt/zurückgesetzt
     * wurde (Fingerabdruck in der Session); sonst Abmeldung und null.
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

        if (
            $person === null
            || ! $model->istAktiv($person)
            || ! hash_equals(self::fingerabdruck($person['passwort_hash']), (string) session(self::SITZUNG_FINGERABDRUCK))
        ) {
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
