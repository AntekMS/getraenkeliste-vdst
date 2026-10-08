<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Anmelderegeln;
use App\Libraries\EinmalPasswort;
use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use App\Models\PersonRolleModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class PersonenController extends BaseController
{
    /** Vergebbare Rollen; `mitglied` ergibt sich aus dem Typ. */
    private const ROLLEN  = ['getraenkewart', 'kioskwart', 'kassenwart', 'admin'];
    private const GRUPPEN = ['aktiv', 'ah', 'sonstige'];

    private const MELDUNG_NAME_VERGEBEN = 'Benutzername existiert bereits.';
    private const FELDER                = ['vorname', 'nachname', 'anzeigename', 'gruppe', 'benutzername'];

    public function index(): string
    {
        $gruppe = (string) $this->request->getGet('gruppe');
        $gruppe = in_array($gruppe, self::GRUPPEN, true) ? $gruppe : null;
        $archiv = $this->request->getGet('archiviert') === 'ja';

        return view('admin/personen/index', [
            'personen'  => (new PersonModel())->mitglieder($gruppe, $archiv),
            'gruppe'    => $gruppe ?? 'alle',
            'archiviert' => $archiv,
        ]);
    }

    public function neu(): string
    {
        return view('admin/personen/formular', ['person' => null, 'rollen' => []]);
    }

    public function anlegen(): RedirectResponse
    {
        [$daten, $fehler] = $this->pruefeFelder(null);

        if ($fehler !== null) {
            return redirect()->to(site_url('admin/personen/neu'))->withInput()->with('error', $fehler);
        }

        $passwort = EinmalPasswort::erzeuge();
        $rollen   = $this->gewaehlteRollen();
        $model    = new PersonModel();
        $id       = 0;

        try {
            $model->transaktion(function () use ($model, $daten, $passwort, $rollen, &$id): void {
                $id = $model->legeMitgliedAn($daten, $passwort);
                $this->rollenSchreiben($id, [], $rollen);
                service('protokollierer')->schreibe($this->adminId(), 'angelegt', 'personen', $id, null, $daten + ['rollen' => $rollen]);
            });
        } catch (DatabaseException $e) {
            if (! $model->benutzernameVergeben($daten['benutzername'])) {
                throw $e;
            }

            return redirect()->to(site_url('admin/personen/neu'))->withInput()->with('error', self::MELDUNG_NAME_VERGEBEN);
        }

        return $this->einmalpasswoerterZeigen([['name' => $daten['anzeigename'], 'benutzername' => $daten['benutzername'], 'passwort' => $passwort]]);
    }

    public function bearbeiten(int $id): string
    {
        $person = $this->mitglied($id);

        return view('admin/personen/formular', [
            'person'    => $person,
            'rollen'    => $this->rollenVon($id),
            'ich'       => $this->adminId() === $id,
        ]);
    }

    public function speichern(int $id): RedirectResponse
    {
        $person = $this->mitglied($id);
        $zurueck = redirect()->to(site_url("admin/personen/{$id}"));

        [$daten, $fehler] = $this->pruefeFelder($id);
        $rollenAlt        = $this->rollenVon($id);
        $rollenNeu        = $this->gewaehlteRollen();

        if ($fehler === null && $id === $this->adminId() && in_array('admin', $rollenAlt, true) && ! in_array('admin', $rollenNeu, true)) {
            $fehler = 'Du kannst dir die Admin-Rolle nicht selbst entziehen.';
        }

        if ($fehler !== null) {
            return $zurueck->withInput()->with('error', $fehler);
        }

        $alt = $neu = [];

        foreach (self::FELDER as $feld) {
            if ((string) $person[$feld] !== $daten[$feld]) {
                $alt[$feld] = $person[$feld];
                $neu[$feld] = $daten[$feld];
            }
        }

        $model = new PersonModel();

        try {
            $model->transaktion(function () use ($model, $id, $neu, $alt, $rollenAlt, $rollenNeu): void {
                $protokoll = service('protokollierer');

                if ($neu !== []) {
                    $model->update($id, $neu);
                    $protokoll->schreibe($this->adminId(), 'geaendert', 'personen', $id, $alt, $neu);
                }

                if ($rollenAlt !== $rollenNeu) {
                    $this->rollenSchreiben($id, $rollenAlt, $rollenNeu);
                    $protokoll->schreibe($this->adminId(), 'rollen_geaendert', 'personen', $id, ['rollen' => $rollenAlt], ['rollen' => $rollenNeu]);
                }
            });
        } catch (DatabaseException $e) {
            if (! $model->benutzernameVergeben($daten['benutzername'], $id)) {
                throw $e;
            }

            return $zurueck->withInput()->with('error', self::MELDUNG_NAME_VERGEBEN);
        }

        return $zurueck->with('success', 'Gespeichert.');
    }

    public function passwortReset(int $id): RedirectResponse
    {
        $person = $this->mitglied($id);

        // Der Passwort-Fingerabdruck in der Session würde den Admin vor der Anzeige des Einmal-Passworts abmelden.
        if ($id === $this->adminId()) {
            return redirect()->to(site_url("admin/personen/{$id}"))->with('error', 'Dein eigenes Passwort änderst du unter Konto.');
        }

        if ($person['archiviert_at'] !== null) {
            return redirect()->to(site_url("admin/personen/{$id}"))->with('error', 'Archivierte Personen haben kein Passwort.');
        }

        $passwort = EinmalPasswort::erzeuge();
        $model    = new PersonModel();

        $model->transaktion(function () use ($model, $id, $passwort): void {
            $model->update($id, [
                'passwort_hash'              => password_hash($passwort, PASSWORD_DEFAULT),
                'passwort_wechsel_erzwingen' => 1,
                'login_fehlversuche'         => 0,
                'login_gesperrt_bis'         => null,
            ]);
            (new AnmeldeTokenModel())->loescheFuerPerson($id);
            service('protokollierer')->schreibe($this->adminId(), 'passwort_reset', 'personen', $id);
        });

        return $this->einmalpasswoerterZeigen([['name' => $person['anzeigename'], 'benutzername' => $person['benutzername'], 'passwort' => $passwort]]);
    }

    public function pinReset(int $id): RedirectResponse
    {
        $this->mitglied($id);
        $model = new PersonModel();

        $model->transaktion(function () use ($model, $id): void {
            $model->update($id, ['pin_hash' => null, 'pin_fehlversuche' => 0, 'pin_gesperrt_bis' => null]);
            service('protokollierer')->schreibe($this->adminId(), 'pin_reset', 'personen', $id);
        });

        return redirect()->to(site_url("admin/personen/{$id}"))->with('success', 'PIN zurückgesetzt. Die Person legt beim nächsten Login eine neue an.');
    }

    public function archivieren(int $id): RedirectResponse
    {
        $person  = $this->mitglied($id);
        $zurueck = redirect()->to(site_url("admin/personen/{$id}"));

        if ($id === $this->adminId()) {
            return $zurueck->with('error', 'Du kannst dich nicht selbst archivieren.');
        }

        if ($person['archiviert_at'] !== null) {
            return $zurueck->with('error', 'Die Person ist bereits archiviert.');
        }

        $jetzt = service('uhr')->jetzt()->format('Y-m-d H:i:s');
        $model = new PersonModel();

        $model->transaktion(function () use ($model, $id, $jetzt): void {
            $model->update($id, ['archiviert_at' => $jetzt]);
            (new AnmeldeTokenModel())->loescheFuerPerson($id);
            service('protokollierer')->schreibe($this->adminId(), 'archiviert', 'personen', $id, ['archiviert_at' => null], ['archiviert_at' => $jetzt]);
        });

        return redirect()->to(site_url('admin/personen'))->with('success', 'Person archiviert.');
    }

    /**
     * Zeigt die Einmal-Passwörter genau einmal: Der Flash überlebt nur den nächsten Request.
     */
    public function einmalpasswoerter(): string|RedirectResponse
    {
        $liste = session()->getFlashdata('einmalpasswoerter');

        if (! is_array($liste) || $liste === []) {
            return redirect()->to(site_url('admin/personen'))->with('error', 'Die Einmal-Passwörter werden nur einmal angezeigt.');
        }

        return view('admin/personen/einmalpasswoerter', ['liste' => $liste]);
    }

    /**
     * @param list<array{name: string, benutzername: string, passwort: string}> $liste
     */
    private function einmalpasswoerterZeigen(array $liste): RedirectResponse
    {
        return redirect()->to(site_url('admin/personen/einmalpasswoerter'))->with('einmalpasswoerter', $liste);
    }

    private function adminId(): int
    {
        return (int) service('anmeldung')->person()['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function mitglied(int $id): array
    {
        $person = (new PersonModel())->find($id);

        if ($person === null || $person['typ'] !== 'mitglied') {
            throw PageNotFoundException::forPageNotFound();
        }

        return $person;
    }

    /**
     * @return array{0: array<string, string>, 1: ?string} Felder (normalisiert) und Fehlertext
     */
    private function pruefeFelder(?int $personId): array
    {
        $vorname  = trim((string) $this->request->getPost('vorname'));
        $nachname = trim((string) $this->request->getPost('nachname'));
        $gruppe   = (string) $this->request->getPost('gruppe');
        $anzeige  = trim((string) $this->request->getPost('anzeigename'));
        $name     = Anmelderegeln::benutzernameNormalisieren((string) $this->request->getPost('benutzername'));

        $daten = [
            'vorname'      => $vorname,
            'nachname'     => $nachname,
            'anzeigename'  => $anzeige !== '' ? $anzeige : trim($vorname . ' ' . $nachname),
            'gruppe'       => $gruppe,
            'benutzername' => $name ?? '',
        ];

        $fehler = match (true) {
            $vorname === '' || $nachname === ''        => 'Vor- und Nachname sind erforderlich.',
            mb_strlen($vorname) > 100 || mb_strlen($nachname) > 100 => 'Vor- und Nachname dürfen höchstens 100 Zeichen lang sein.',
            mb_strlen($daten['anzeigename']) > 200 => 'Der Anzeigename darf höchstens 200 Zeichen lang sein.',
            ! in_array($gruppe, self::GRUPPEN, true)   => 'Bitte eine Gruppe wählen.',
            $name === null                             => 'Benutzername ungültig (3 bis 40 Zeichen: a-z, 0-9, Punkt, Unterstrich, Minus).',
            (new PersonModel())->benutzernameVergeben($name, $personId) => self::MELDUNG_NAME_VERGEBEN,
            default                                    => null,
        };

        return [$daten, $fehler];
    }

    /**
     * @return list<string> gewählte Rollen in fester Reihenfolge, nur gültige
     */
    private function gewaehlteRollen(): array
    {
        $gewaehlt = (array) $this->request->getPost('rollen');

        return array_values(array_filter(self::ROLLEN, static fn (string $r): bool => in_array($r, $gewaehlt, true)));
    }

    /**
     * @return list<string>
     */
    private function rollenVon(int $id): array
    {
        $rollen = (new PersonModel())->rollen($id);

        return array_values(array_filter(self::ROLLEN, static fn (string $r): bool => in_array($r, $rollen, true)));
    }

    /**
     * @param list<string> $alt
     * @param list<string> $neu
     */
    private function rollenSchreiben(int $personId, array $alt, array $neu): void
    {
        $model = new PersonRolleModel();

        foreach (array_diff($alt, $neu) as $rolle) {
            $model->where('person_id', $personId)->where('rolle', $rolle)->delete();
        }

        foreach (array_diff($neu, $alt) as $rolle) {
            $model->insert(['person_id' => $personId, 'rolle' => $rolle]);
        }
    }
}
