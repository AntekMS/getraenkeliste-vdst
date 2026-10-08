<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\PinSperre;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * Hochzählen und Sperren: siehe Tests\Feature\VersuchszaehlerTest.
 *
 * @internal
 */
final class PinSperreTest extends CIUnitTestCase
{
    public function test_ist_gesperrt_grenzen(): void
    {
        $bis = new DateTimeImmutable('2026-10-05 12:05:00');

        $this->assertTrue(PinSperre::istGesperrt($bis, new DateTimeImmutable('2026-10-05 12:04:59')));
        $this->assertFalse(PinSperre::istGesperrt($bis, new DateTimeImmutable('2026-10-05 12:05:00')));
        $this->assertFalse(PinSperre::istGesperrt(null, new DateTimeImmutable('2026-10-05 12:00:00')));
    }
}
