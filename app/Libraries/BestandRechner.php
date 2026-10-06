<?php

declare(strict_types=1);

namespace App\Libraries;

final class BestandRechner
{
    /** Bestand = letzter gezählter Ist-Wert + Bewegungen seither − Verkauf seither. */
    public static function bestand(int $letzterIst, int $bewegungenSeither, int $verkauftSeither): int
    {
        return $letzterIst + $bewegungenSeither - $verkauftSeither;
    }

    /** Vorrang: negativ > leer > niedrig > ok. */
    public static function ampel(int $bestand, int $mindestbestand): string
    {
        if ($bestand < 0) {
            return 'negativ';
        }
        if ($bestand === 0) {
            return 'leer';
        }

        return $bestand < $mindestbestand ? 'niedrig' : 'ok';
    }
}
