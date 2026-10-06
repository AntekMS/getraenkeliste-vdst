<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\BuchungsAntworten;
use App\Libraries\Anmeldung;
use App\Libraries\BuchungService;
use App\Libraries\Geraete;
use App\Libraries\PinSperre;
use App\Models\ArtikelModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Kühlschrank-Tablet: Freischaltung, Namenskacheln, PIN, Buchen, Rückgängig.
 * Die Tablet-Sitzung ist vom persönlichen Login getrennt (`tablet_konto_id`, `tablet_seit`,
 * `tablet_letzter_vorgang`, nie `person_id`) und gilt höchstens 300 s ab der Namenswahl.
 */
class TabletController extends BaseController
{
    use BuchungsAntworten;

    public const MELDUNG_PIN_FALSCH    = 'PIN falsch.';
    public const MELDUNG_GESPERRT      = 'Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.';
    public const MELDUNG_OHNE_PIN      = 'Bitte zuerst am eigenen Gerät eine PIN setzen.';
    public const MELDUNG_NICHT_WAEHLBAR = 'Diese Person ist am Tablet nicht wählbar.';
    public const MELDUNG_ABGELAUFEN    = 'Sitzung abgelaufen – bitte Namen erneut wählen.';

    private const MELDUNG_CODE   = 'Code ungültig oder abgelaufen.';
    private const SITZUNG_MAX_S  = 300;
    private const DATUMSFORMAT   = 'Y-m-d H:i:s';
    private const SITZUNG_SCHLUESSEL = ['tablet_konto_id', 'tablet_seit', 'tablet_letzter_vorgang'];

    public function freischaltenForm(): string
    {
        return view('tablet/freischalten');
    }

    public function freischalten(): RedirectResponse
    {
        $zurueck = redirect()->to(site_url('tablet/freischalten'));
        $name    = trim((string) $this->request->getPost('name'));

        if ($name === '' || mb_strlen($name) > 100) {
            return $zurueck->withInput()->with('error', 'Bitte einen Namen mit höchstens 100 Zeichen angeben.');
        }

        if (service('geraete')->freischalten((string) $this->request->getPost('code'), $name) === null) {
            return $zurueck->withInput()->with('error', self::MELDUNG_CODE);
        }

        // Das Tablet gehört dem Gerät, nicht einer Person: persönlichen Login (Session + Merk-Token) beenden.
        $anmeldung = service('anmeldung');
        $anmeldung->merkenBeenden($this->request->getCookie(Anmeldung::MERK_COOKIE));
        $anmeldung->abmelden();

        return redirect()->to(site_url('tablet'));
    }

    /**
     * Namenskacheln. Der Aufruf beendet jede laufende Tablet-Sitzung.
     */
    public function index(): string
    {
        $this->sitzungLeeren();

        return view('tablet/namen', ['kacheln' => (new PersonModel())->tabletKacheln()]);
    }

    public function waehlen(int $id): RedirectResponse
    {
        $this->sitzungLeeren();

        $person = (new PersonModel())->find($id);

        if ($person === null || $person['archiviert_at'] !== null) {
            return $this->zurNamensauswahl(self::MELDUNG_NICHT_WAEHLBAR);
        }

        if ($person['typ'] === 'sammelkonto') {
            $this->sitzungStarten($id);

            return redirect()->to(site_url('tablet/buchen'));
        }

        if ($person['pin_hash'] === null) {
            return $this->zurNamensauswahl(self::MELDUNG_OHNE_PIN);
        }

        return redirect()->to(site_url('tablet/pin/' . $id));
    }

    public function pinForm(int $id): string|RedirectResponse
    {
        $person = $this->pinPerson($id);

        if ($person === null) {
            return $this->zurNamensauswahl(self::MELDUNG_NICHT_WAEHLBAR);
        }

        return view('tablet/pin', ['person' => $person, 'timeoutS' => $this->timeoutS()]);
    }

    public function pinPruefen(int $id): RedirectResponse
    {
        $model  = new PersonModel();
        $person = $this->pinPerson($id);

        if ($person === null) {
            return $this->zurNamensauswahl(self::MELDUNG_NICHT_WAEHLBAR);
        }

        $jetzt = service('uhr')->jetzt();
        $bis   = $person['pin_gesperrt_bis'] === null
            ? null
            : new DateTimeImmutable($person['pin_gesperrt_bis'], new DateTimeZone('Europe/Berlin'));

        // Sperre VOR dem Hash-Vergleich: gesperrt = auch die richtige PIN wird abgelehnt, Zähler bleibt.
        if (PinSperre::istGesperrt($bis, $jetzt)) {
            return $this->zurNamensauswahl(self::MELDUNG_GESPERRT);
        }

        $pin = (string) $this->request->getPost('pin');

        if (preg_match('/^\d{4,6}$/', $pin) !== 1 || ! password_verify($pin, $person['pin_hash'])) {
            $neu = PinSperre::nachFehlversuch((int) $person['pin_fehlversuche'], $jetzt);
            $ok  = $model->update($id, [
                'pin_fehlversuche' => $neu['fehlversuche'],
                'pin_gesperrt_bis' => $neu['gesperrt_bis']?->format(self::DATUMSFORMAT),
            ]);

            if ($neu['gesperrt_bis'] !== null) {
                return $this->zurNamensauswahl(self::MELDUNG_GESPERRT);
            }

            // Schlägt das Zählen fehl, bleibt die Anmeldung trotzdem verwehrt (fail closed).
            return redirect()->to(site_url('tablet/pin/' . $id))
                ->with('error', $ok === false ? 'PIN konnte nicht geprüft werden. Bitte erneut versuchen.' : self::MELDUNG_PIN_FALSCH);
        }

        if ($model->update($id, ['pin_fehlversuche' => 0, 'pin_gesperrt_bis' => null]) === false) {
            return redirect()->to(site_url('tablet/pin/' . $id))->with('error', 'PIN konnte nicht geprüft werden. Bitte erneut versuchen.');
        }

        $this->sitzungStarten($id);

        return redirect()->to(site_url('tablet/buchen'));
    }

    public function buchenSeite(): string|RedirectResponse
    {
        $konto = $this->sitzungKonto();

        if ($konto === null) {
            return $this->zurNamensauswahl(self::MELDUNG_ABGELAUFEN);
        }

        return view('buchen/index', [
            'modus'     => 'tablet',
            'kontoName' => $konto['anzeigename'],
            'timeoutS'  => $this->timeoutS(),
            'bereiche'  => (new ArtikelModel())->buchbar(),
            'vorgangId' => BuchungService::neueVorgangId(),
        ]);
    }

    public function buchen(): ResponseInterface
    {
        $konto = $this->sitzungKonto();

        if ($konto === null) {
            return $this->fehler(self::MELDUNG_ABGELAUFEN, 401);
        }

        $body = $this->jsonBody();

        if (! is_string($body['vorgang_id'] ?? null)) {
            return $this->fehler('Ungültige Anfrage. Nicht gebucht.');
        }

        $geraet = service('geraete')->ausCookie($this->request->getCookie(Geraete::COOKIE));

        if ($geraet === null) {
            return $this->fehler('Dieses Tablet ist nicht freigeschaltet.', 403);
        }

        $gebuchtVon = $konto['typ'] === 'mitglied' ? (int) $konto['id'] : null;

        return $this->bucheAusBody(
            $body,
            (int) $konto['id'],
            $gebuchtVon,
            (int) $geraet['id'],
            'tablet',
            static function (string $vorgangId): void {
                session()->set('tablet_letzter_vorgang', $vorgangId);
            },
        );
    }

    public function rueckgaengig(): ResponseInterface
    {
        $konto = $this->sitzungKonto();

        if ($konto === null) {
            return $this->fehler(self::MELDUNG_ABGELAUFEN, 401);
        }

        $vorgangId = $this->jsonBody()['vorgang_id'] ?? null;

        if (! is_string($vorgangId)) {
            return $this->fehler('Ungültiger Vorgang.');
        }

        // Nur der zuletzt in dieser Tablet-Sitzung gebuchte Vorgang.
        if ($vorgangId !== session('tablet_letzter_vorgang')) {
            return $this->fehler('Dieser Vorgang kann hier nicht rückgängig gemacht werden.', 403);
        }

        return $this->storniereAntwort($vorgangId, $konto['typ'] === 'mitglied' ? (int) $konto['id'] : null);
    }

    public function fertig(): RedirectResponse
    {
        $this->sitzungLeeren();

        return redirect()->to(site_url('tablet'));
    }

    /**
     * Wählbare Person für die PIN-Eingabe: Mitglied, nicht archiviert, PIN gesetzt.
     *
     * @return ?array<string, mixed>
     */
    private function pinPerson(int $id): ?array
    {
        $person = (new PersonModel())->find($id);

        if ($person === null || $person['typ'] !== 'mitglied' || $person['archiviert_at'] !== null || $person['pin_hash'] === null) {
            return null;
        }

        return $person;
    }

    private function sitzungStarten(int $kontoId): void
    {
        session()->set([
            'tablet_konto_id'        => $kontoId,
            'tablet_seit'            => service('uhr')->jetzt()->format(self::DATUMSFORMAT),
            'tablet_letzter_vorgang' => null,
        ]);
    }

    private function sitzungLeeren(): void
    {
        session()->remove(self::SITZUNG_SCHLUESSEL);
    }

    /**
     * Konto der laufenden Tablet-Sitzung (höchstens 300 s alt, Person nicht inzwischen archiviert); sonst null.
     *
     * @return ?array<string, mixed>
     */
    private function sitzungKonto(): ?array
    {
        $id   = session('tablet_konto_id');
        $seit = session('tablet_seit');

        if ($id === null || $seit === null) {
            return null;
        }

        $alter = service('uhr')->jetzt()->getTimestamp()
            - (new DateTimeImmutable((string) $seit, new DateTimeZone('Europe/Berlin')))->getTimestamp();
        $konto = $alter > self::SITZUNG_MAX_S ? null : (new PersonModel())->find((int) $id);

        if ($konto === null || $konto['archiviert_at'] !== null) {
            $this->sitzungLeeren();

            return null;
        }

        return $konto;
    }

    private function zurNamensauswahl(string $fehler): RedirectResponse
    {
        return redirect()->to(site_url('tablet'))->with('error', $fehler);
    }

    private function timeoutS(): int
    {
        return max(5, service('einstellungen')->int('tablet_timeout_s'));
    }
}
