<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Artikelbild;
use App\Models\ArtikelModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `GET artikelbild/<id>` (Filter `bild`): liefert das gespeicherte Bild. Der Dateiname kommt nur aus der DB;
 * auch archivierte Artikel behalten ihr Bild. Die URL trägt `?v=<bild_version>`, daher langes Caching.
 */
class ArtikelbildController extends BaseController
{
    public function zeige(int $id): ResponseInterface
    {
        $artikel = (new ArtikelModel())->select('bild_datei')->find($id);
        $pfad    = service('artikelbild')->pfad($artikel['bild_datei'] ?? null);

        if ($pfad === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType(Artikelbild::contentType($pfad))
            ->removeHeader('Cache-Control') // Standard der Response ist no-store; setHeader würde anhängen
            ->setHeader('Cache-Control', 'private, max-age=31536000, immutable')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'inline')
            ->setBody((string) file_get_contents($pfad));
    }
}
