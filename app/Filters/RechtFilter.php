<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Berechtigung;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `recht:<aktion>`: bereichsunabhängige Rechteprüfung über Berechtigung::darf().
 * Bereichsgebundene Prüfungen passieren im Controller.
 */
class RechtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $anmeldung = service('anmeldung');

        if ($anmeldung->person() === null) {
            return redirect()->to(site_url('login'));
        }

        foreach ($arguments ?? [] as $aktion) {
            if (! Berechtigung::darf($anmeldung->rollen(), $aktion)) {
                return service('response')
                    ->setStatusCode(403)
                    ->setBody(view('errors/keine_berechtigung'));
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
