<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Abrechnungszeitraum: beginnt mit der Inbetriebnahme bzw. nach dem letzten Stichtag.
 * Der Stichtag selbst gehoert noch zum abgeschlossenen (eingefrorenen) Zeitraum.
 */
final class ZeitraumErmittler
{
    public static function gehoertZumLaufendenZeitraum(
        DateTimeImmutable $zeitpunkt,
        DateTimeImmutable $inbetriebnahme,
        ?DateTimeImmutable $letzterStichtag,
    ): bool {
        return $letzterStichtag === null ? $zeitpunkt >= $inbetriebnahme : $zeitpunkt > $letzterStichtag;
    }

    public static function istEingefroren(DateTimeImmutable $zeitpunkt, ?DateTimeImmutable $letzterStichtag): bool
    {
        return $letzterStichtag !== null && $zeitpunkt <= $letzterStichtag;
    }
}
