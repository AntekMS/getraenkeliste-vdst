<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Anmeldung;
use App\Models\PersonModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `angemeldet`: ohne (nicht archivierte) Person zurück zum Login. Solange Passwort oder
 * PIN noch eingerichtet werden müssen, führt alles zur Pflichtseite `konto/einrichten`;
 * Routen mit Argument `frei` (Pflichtseite selbst, Abmelden) sind davon ausgenommen.
 */
class AnmeldungFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $anmeldung = service('anmeldung');
        $person    = $anmeldung->person();

        if ($person === null && $anmeldung->ausCookieAnmelden($request->getCookie(Anmeldung::MERK_COOKIE))) {
            $person = $anmeldung->person();
        }

        if ($person === null) {
            return redirect()->to(site_url('login'))->withCookies();
        }

        if (! in_array('frei', (array) $arguments, true)) {
            $offen = (new PersonModel())->brauchtEinrichtung($person);

            if ($offen['passwort'] || $offen['pin']) {
                return redirect()->to(site_url('konto/einrichten'))->withCookies();
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Cookie-Änderungen (Rotation, Löschen) gehen so auch bei Redirects ohne withCookies() raus.
        service('anmeldung')->merkCookieAnwenden($response);

        return null;
    }
}
