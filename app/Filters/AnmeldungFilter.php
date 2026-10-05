<?php

declare(strict_types=1);

namespace App\Filters;

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
        $person = service('anmeldung')->person();

        if ($person === null) {
            return redirect()->to(site_url('login'));
        }

        if (! in_array('frei', (array) $arguments, true)) {
            $offen = (new PersonModel())->brauchtEinrichtung($person);

            if ($offen['passwort'] || $offen['pin']) {
                return redirect()->to(site_url('konto/einrichten'));
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
