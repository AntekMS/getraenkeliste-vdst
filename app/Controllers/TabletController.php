<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Anmeldung;
use CodeIgniter\HTTP\RedirectResponse;

class TabletController extends BaseController
{
    private const MELDUNG_CODE = 'Code ungültig oder abgelaufen.';

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
     * Platzhalter; Task 15 ersetzt ihn durch die Buchungsseite.
     */
    public function index(): string
    {
        return view('tablet/index');
    }
}
