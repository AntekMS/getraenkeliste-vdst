<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Geraete;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `bild` (Artikelbilder): durchlassen bei persönlicher Anmeldung (Session) oder gültigem, nicht gesperrtem
 * Tablet-Cookie; sonst 403. Kein Redirect (Bilder werden per <img> geladen), keine Cookie-Erneuerung, kein Schreiben.
 */
class BildFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('anmeldung')->person() !== null || service('geraete')->istGueltigesToken($request->getCookie(Geraete::COOKIE))) {
            return null;
        }

        return service('response')->setStatusCode(403)->setBody(view('errors/keine_berechtigung'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
