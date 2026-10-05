<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alias `angemeldet`: ohne (nicht archivierte) Person zurück zum Login.
 */
class AnmeldungFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('anmeldung')->person() === null) {
            return redirect()->to(site_url('login'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
