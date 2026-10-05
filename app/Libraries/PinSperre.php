<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Fehlversuch-Sperre, gilt fuer PIN und Login.
 */
final class PinSperre
{
    public const MAX_FEHLVERSUCHE = 5;
    public const SPERRE_MINUTEN   = 5;

    public static function istGesperrt(?DateTimeImmutable $gesperrtBis, DateTimeImmutable $jetzt): bool
    {
        return $gesperrtBis !== null && $jetzt < $gesperrtBis;
    }

    /**
     * @return array{fehlversuche: int, gesperrt_bis: ?DateTimeImmutable}
     */
    public static function nachFehlversuch(int $bisherigeFehlversuche, DateTimeImmutable $jetzt): array
    {
        $fehlversuche = $bisherigeFehlversuche + 1;

        if ($fehlversuche >= self::MAX_FEHLVERSUCHE) {
            return [
                'fehlversuche' => 0,
                'gesperrt_bis' => $jetzt->modify('+' . self::SPERRE_MINUTEN . ' minutes'),
            ];
        }

        return ['fehlversuche' => $fehlversuche, 'gesperrt_bis' => null];
    }
}
