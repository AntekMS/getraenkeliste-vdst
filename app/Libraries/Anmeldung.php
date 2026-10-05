<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Login am eigenen Gerät: Passwortprüfung mit Fehlversuch-Sperre und Sitzung.
 */
final class Anmeldung
{
    public const MELDUNG_FALSCH   = 'Benutzername oder Passwort falsch.';
    public const MELDUNG_GESPERRT = 'Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.';

    public const MERK_COOKIE = 'gl_merken';

    private const DATUMSFORMAT = 'Y-m-d H:i:s';

    /** @var ?array<string, mixed> Cookie-Änderung dieses Requests (setzen oder ['loeschen' => true]) */
    private ?array $merkAktion = null;

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
        ];
        response()->setCookie($this->merkAktion);
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
