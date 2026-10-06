<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Berechtigung;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `recht:<aktion>` oder `recht:<aktion>@<bereich>`: Rechteprüfung über Berechtigung::darf()
 * (mit Bereich für die Wart-Routen; der Controller löst den Bereich zusätzlich auf und liefert 404 bei unbekannt/inaktiv).
 */
class RechtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $anmeldung = service('anmeldung');

        if ($anmeldung->person() === null) {
            return redirect()->to(site_url('login'));
        }

        // Fail closed: `recht` ohne Aktion ist ein Konfigurationsfehler und darf nie durchlassen.
        if ($arguments === null || $arguments === []) {
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('errors/keine_berechtigung'));
        }

        foreach ($arguments as $argument) {
            // `aktion@bereich`: am ersten `@` trennen; ein leerer Bereich ist ein Konfigurationsfehler (fail closed).
            [$aktion, $bereich] = array_pad(explode('@', $argument, 2), 2, null);

            if ($bereich === '' || ! Berechtigung::darf($anmeldung->rollen(), $aktion, $bereich)) {
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
