<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Anmelderegeln;
use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\RedirectResponse;

class KontoController extends BaseController
{
    private const MELDUNG_PASSWORT_UNGLEICH = 'Die Passwörter stimmen nicht überein.';
    private const MELDUNG_PIN_UNGLEICH      = 'Die PINs stimmen nicht überein.';
    private const MELDUNG_AKTUELL_FALSCH    = 'Das aktuelle Passwort ist falsch.';
    private const MELDUNG_PASSWORT_GLEICH   = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';

    public function einrichtenForm(): string|RedirectResponse
    {
        $offen = (new PersonModel())->brauchtEinrichtung(service('anmeldung')->person());

        if (! $offen['passwort'] && ! $offen['pin']) {
            return redirect()->to(site_url('buchen'));
        }

        return view('konto/einrichten', ['offen' => $offen]);
    }

    public function einrichten(): RedirectResponse
    {
        $person = service('anmeldung')->person();
        $offen  = (new PersonModel())->brauchtEinrichtung($person);

        if (! $offen['passwort'] && ! $offen['pin']) {
            return redirect()->to(site_url('buchen'));
        }

        $fehler = [];
        $daten  = [];

        if ($offen['passwort']) {
            $passwort = (string) $this->request->getPost('passwort_neu');
            $fehler[] = $this->passwortFehler($passwort, (string) $this->request->getPost('passwort_wiederholen'), (string) $person['passwort_hash']);
            $daten    = $this->passwortDaten($passwort);
        }

        if ($offen['pin']) {
            $pin      = (string) $this->request->getPost('pin');
            $fehler[] = $this->pinFehler($pin, (string) $this->request->getPost('pin_wiederholen'));
            $daten    = [...$daten, ...$this->pinDaten($pin)];
        }

        $fehler = array_values(array_filter($fehler));

        if ($fehler !== []) {
            return redirect()->to(site_url('konto/einrichten'))->with('error', implode(' ', $fehler));
        }

        $this->speichere((int) $person['id'], $daten, $offen['passwort']);

        return redirect()->to(site_url('buchen'))->with('success', 'Alles eingerichtet.');
    }

    public function index(): string
    {
        return view('konto/index', ['person' => service('anmeldung')->person()]);
    }

    public function passwortAendern(): RedirectResponse
    {
        $person = service('anmeldung')->person();

        if (! $this->aktuellesPasswortStimmt($person)) {
            return redirect()->to(site_url('konto'))->with('error', self::MELDUNG_AKTUELL_FALSCH);
        }

        $passwort = (string) $this->request->getPost('passwort_neu');
        $fehler   = $this->passwortFehler($passwort, (string) $this->request->getPost('passwort_wiederholen'), (string) $person['passwort_hash']);

        if ($fehler !== null) {
            return redirect()->to(site_url('konto'))->with('error', $fehler);
        }

        $this->speichere((int) $person['id'], $this->passwortDaten($passwort), true);

        return redirect()->to(site_url('konto'))->with('success', 'Passwort geändert.')->withCookies();
    }

    public function pinAendern(): RedirectResponse
    {
        $person = service('anmeldung')->person();

        if (! $this->aktuellesPasswortStimmt($person)) {
            return redirect()->to(site_url('konto'))->with('error', self::MELDUNG_AKTUELL_FALSCH);
        }

        $pin    = (string) $this->request->getPost('pin');
        $fehler = $this->pinFehler($pin, (string) $this->request->getPost('pin_wiederholen'));

        if ($fehler !== null) {
            return redirect()->to(site_url('konto'))->with('error', $fehler);
        }

        $this->speichere((int) $person['id'], $this->pinDaten($pin), false);

        return redirect()->to(site_url('konto'))->with('success', 'PIN geändert.');
    }

    /**
     * @param array<string, mixed> $person
     */
    private function aktuellesPasswortStimmt(array $person): bool
    {
        return password_verify((string) $this->request->getPost('passwort_aktuell'), (string) $person['passwort_hash']);
    }

    private function passwortFehler(string $passwort, string $wiederholt, string $bisherigerHash): ?string
    {
        return Anmelderegeln::passwortFehler($passwort)
            ?? ($passwort !== $wiederholt ? self::MELDUNG_PASSWORT_UNGLEICH : null)
            ?? (password_verify($passwort, $bisherigerHash) ? self::MELDUNG_PASSWORT_GLEICH : null);
    }

    private function pinFehler(string $pin, string $wiederholt): ?string
    {
        return Anmelderegeln::pinFehler($pin)
            ?? ($pin !== $wiederholt ? self::MELDUNG_PIN_UNGLEICH : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function passwortDaten(string $passwort): array
    {
        return [
            'passwort_hash'              => password_hash($passwort, PASSWORD_DEFAULT),
            'passwort_wechsel_erzwingen' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pinDaten(string $pin): array
    {
        return [
            'pin_hash'         => password_hash($pin, PASSWORD_DEFAULT),
            'pin_fehlversuche' => 0,
            'pin_gesperrt_bis' => null,
        ];
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function speichere(int $personId, array $daten, bool $passwortGeaendert): void
    {
        (new PersonModel())->update($personId, $daten);

        if ($passwortGeaendert) {
            (new AnmeldeTokenModel())->loescheFuerPerson($personId);
            service('anmeldung')->merkCookieLoeschen();
            session()->regenerate(true);
        }
    }
}
