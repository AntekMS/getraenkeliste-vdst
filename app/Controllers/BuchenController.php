<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\BuchungsAntworten;
use App\Libraries\BuchungService;
use App\Models\ArtikelModel;
use App\Models\BuchungModel;
use App\Models\PersonModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Buchen am eigenen Gerät: Seite plus zwei JSON-Endpunkte (buchen, rückgängig).
 * Der Zugriff (angemeldet, Recht `buchen`) wird über Routenfilter gesichert.
 */
class BuchenController extends BaseController
{
    use BuchungsAntworten;

    private const SAMMELKONTEN = ['couleur' => 'Couleur', 'bund' => 'Bund'];

    public function index(): string
    {
        return view('buchen/index', [
            'modus'     => 'web',
            'bereiche'  => (new ArtikelModel())->buchbar(),
            'vorgangId' => BuchungService::neueVorgangId(),
        ]);
    }

    public function buchen(): ResponseInterface
    {
        $body = $this->jsonBody();
        $ich  = (int) service('anmeldung')->person()['id'];
        $konto = $body['konto'] ?? null;

        if (! is_string($body['vorgang_id'] ?? null) || ! is_string($konto)) {
            return $this->fehler('Ungültige Anfrage. Nicht gebucht.');
        }

        if ($konto === 'ich') {
            $kontoId = $ich;
        } elseif (isset(self::SAMMELKONTEN[$konto])) {
            $kontoId = (new PersonModel())->sammelkontoId(self::SAMMELKONTEN[$konto]);
        } else {
            return $this->fehler('Ungültiges Konto. Nicht gebucht.');
        }

        return $this->bucheAusBody($body, $kontoId, $ich, null, 'web');
    }

    public function rueckgaengig(): ResponseInterface
    {
        $vorgangId = $this->jsonBody()['vorgang_id'] ?? null;
        $ich       = (int) service('anmeldung')->person()['id'];

        if (! is_string($vorgangId)) {
            return $this->fehler('Ungültiger Vorgang.');
        }

        $zeile = (new BuchungModel())->where('vorgang_id', $vorgangId)->first();

        // Unbekannte und fremde Vorgänge sehen gleich aus: nichts über fremde IDs verraten.
        if ($zeile === null || ((int) $zeile['konto_id'] !== $ich && (int) ($zeile['gebucht_von_id'] ?? 0) !== $ich)) {
            return $this->fehler('Dieser Vorgang gehört nicht zu dir.', 403);
        }

        return $this->storniereAntwort($vorgangId, $ich);
    }
}
