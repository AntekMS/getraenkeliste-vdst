<?php

declare(strict_types=1);

namespace App\Controllers\Wart;

use App\Controllers\BaseController;
use App\Models\BereichModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Bestandsseite des Warts. Der Bereich kommt aus der Route; unbekannte oder inaktive
 * Bereiche (Kiosk bis Stufe 3) sind 404.
 */
class BestandController extends BaseController
{
    public function index(string $bereichSchluessel): string
    {
        $bereich = (new BereichModel())->where('schluessel', $bereichSchluessel)->where('aktiv', 1)->first();

        if ($bereich === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $gruppen = service('bestand')->fuerBereich((int) $bereich['id']);
        $negativ = false;

        foreach ($gruppen as $gruppe) {
            foreach ($gruppe['artikel'] as $artikel) {
                $negativ = $negativ || $artikel['ampel'] === 'negativ';
            }
        }

        return view('wart/bestand', ['bereich' => $bereich, 'gruppen' => $gruppen, 'negativ' => $negativ]);
    }
}
