<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class AuthController extends BaseController
{
    public function loginForm(): string|RedirectResponse
    {
        if (service('anmeldung')->person() !== null) {
            return redirect()->to(site_url('buchen'));
        }

        return view('auth/login');
    }

    public function login(): RedirectResponse
    {
        $anmeldung = service('anmeldung');
        $ergebnis  = $anmeldung->pruefePasswort(
            (string) $this->request->getPost('benutzername'),
            (string) $this->request->getPost('passwort'),
        );

        if (! $ergebnis['ok']) {
            return redirect()->to(site_url('login'))->with('error', $ergebnis['meldung']);
        }

        $anmeldung->anmelden((int) $ergebnis['person']['id']);

        return redirect()->to(site_url('buchen'));
    }

    public function logout(): RedirectResponse
    {
        service('anmeldung')->abmelden();

        return redirect()->to(site_url('login'));
    }
}
