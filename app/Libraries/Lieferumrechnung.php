<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

final class Lieferumrechnung
{
    /** Menge in Stück = Kisten × Gebindegröße + Stück. */
    public static function menge(int $kisten, int $stueck, ?int $gebinde): int
    {
        if ($kisten < 0 || $stueck < 0 || ($gebinde !== null && $gebinde < 0)) {
            throw new InvalidArgumentException('Mengen dürfen nicht negativ sein.');
        }
        if ($kisten > 0 && ($gebinde === null || $gebinde === 0)) {
            throw new InvalidArgumentException('Für Kisten ist keine Gebindegröße hinterlegt.');
        }

        return $kisten * ($gebinde ?? 0) + $stueck;
    }
}
