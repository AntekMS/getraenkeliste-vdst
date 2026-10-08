<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Reihenfolge-Logik für „hoch/runter“ (rein, ohne Datenbank).
 */
final class Sortierung
{
    /**
     * Vertauscht $id in der geordneten Liste mit dem Nachbarn.
     *
     * @param list<int>       $ids       geordnete IDs, wird verändert
     * @param 'hoch'|'runter' $richtung
     *
     * @return bool false, wenn nichts zu tun ist (Rand oder unbekannte ID)
     */
    public static function tausche(array &$ids, int $id, string $richtung): bool
    {
        $index = array_search($id, $ids, true);

        if ($index === false) {
            return false;
        }

        $ziel = $richtung === 'hoch' ? $index - 1 : $index + 1;

        if ($ziel < 0 || $ziel >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$ziel]] = [$ids[$ziel], $ids[$index]];

        return true;
    }
}
