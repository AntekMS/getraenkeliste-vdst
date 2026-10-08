<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Geraete;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `kein_tablet` (für `login`; `angemeldet` prüft dasselbe): Ein Request mit gültigem,
 * nicht gesperrtem Geräte-Cookie gehört einem Tablet und geht zu `tablet`. Ein gesperrtes oder
 * unbekanntes Geräte-Cookie zählt als „kein Gerät“ (normaler Login bleibt möglich).
 */
class KeinTabletFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return service('geraete')->nichtTabletAntwort($request->getCookie(Geraete::COOKIE));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
