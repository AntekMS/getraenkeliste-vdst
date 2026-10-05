<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\ProtokollModel;

/**
 * Schreibt Änderungen ins Protokoll. Hash-Felder (Schlüssel auf `_hash`, nur oberste Ebene)
 * werden nie gespeichert.
 */
final class Protokollierer
{
    /**
     * @param ?array<string, mixed> $alt
     * @param ?array<string, mixed> $neu
     */
    public function schreibe(int $personId, string $aktion, string $tabelle, ?int $datensatzId, ?array $alt = null, ?array $neu = null): void
    {
        (new ProtokollModel())->insert([
            'person_id'    => $personId,
            'aktion'       => $aktion,
            'tabelle'      => $tabelle,
            'datensatz_id' => $datensatzId,
            'alt'          => $this->alsJson($alt),
            'neu'          => $this->alsJson($neu),
            'erfolgt_at'   => service('uhr')->jetzt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param ?array<string, mixed> $daten
     */
    private function alsJson(?array $daten): ?string
    {
        if ($daten === null) {
            return null;
        }

        foreach (array_keys($daten) as $schluessel) {
            if (str_ends_with((string) $schluessel, '_hash')) {
                unset($daten[$schluessel]);
            }
        }

        return json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
