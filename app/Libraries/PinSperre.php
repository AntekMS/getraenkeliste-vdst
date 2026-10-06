<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Fehlversuch-Sperre, gilt fuer PIN und Login: Schwelle, Dauer und Sperrprüfung.
 * Das Hochzählen passiert atomar in der DB ({@see Versuchszaehler}).
 */
final class PinSperre
{
    public const MAX_FEHLVERSUCHE = 5;
    public const SPERRE_MINUTEN   = 5;

    public static function istGesperrt(?DateTimeImmutable $gesperrtBis, DateTimeImmutable $jetzt): bool
    {
        return $gesperrtBis !== null && $jetzt < $gesperrtBis;
    }
}
