<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Einzige Quelle für „jetzt“ (Europe/Berlin). Zeitabhängiger Code holt die Zeit
 * ausschließlich über `service('uhr')->jetzt()`; Tests fixieren sie über
 * `DbTestCase::uhrStellen()`.
 */
final class Uhr
{
    /**
     * @param ?string $festerZeitpunkt nur für Tests: feste Zeit (Europe/Berlin), z. B. '2026-10-05 12:00:00'
     */
    public function __construct(private readonly ?string $festerZeitpunkt = null)
    {
    }

    public function jetzt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->festerZeitpunkt ?? 'now', new DateTimeZone('Europe/Berlin'));
    }
}
