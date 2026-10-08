<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Models\BereichModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Gemeinsame Helfer der Wart-Controller: Bereich aus der Route (unbekannt/inaktiv → 404) und robuste
 * Eingabewerte (Array-Parameter wie `bemerkung[]=x` zählen als leer, nie als 500).
 */
trait WartEingaben
{
    /** Skalare Werte als Text, alles andere (Array, null) als ''. */
    private function text(mixed $wert): string
    {
        return is_scalar($wert) ? (string) $wert : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function bereich(string $schluessel): array
    {
        $bereich = (new BereichModel())->where('schluessel', $schluessel)->where('aktiv', 1)->first();

        if ($bereich === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $bereich;
    }
}
