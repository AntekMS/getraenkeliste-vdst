<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * Alias `tablet_csrf`: CSRF-Prüfung für Tablet-Routen (diese sind vom globalen `csrf` ausgenommen).
 * Ein Formular-POST mit ungültigem Token (z. B. Tablet über Nacht, Session abgelaufen) führt mit
 * deutschem Hinweis zur Namensauswahl, nicht zu einem 403-Bildschirm oder dem Referrer. JSON/AJAX bleibt 403.
 */
class TabletCsrfFilter extends CSRF
{
    public const MELDUNG = 'Die Sitzung war abgelaufen. Bitte den Namen erneut wählen.';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        try {
            service('security')->verify($request);
        } catch (SecurityException $e) {
            if ($request->isAJAX()) {
                throw $e;
            }

            // Eine Antwort aus `before` überspringt `after` des Geräte-Filters: Cookie hier direkt anwenden.
            $antwort = redirect()->to(site_url('tablet'))->with('error', self::MELDUNG);
            service('geraete')->cookieAnwenden($antwort);

            return $antwort;
        }

        return null;
    }
}
