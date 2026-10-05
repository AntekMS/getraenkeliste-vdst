<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\PinSperre;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * @internal
 */
final class PinSperreTest extends CIUnitTestCase
{
    public function test_vierter_fehlversuch_sperrt_noch_nicht(): void
    {
        $r = PinSperre::nachFehlversuch(3, new DateTimeImmutable('2026-10-05 12:00:00'));

        $this->assertSame(4, $r['fehlversuche']);
        $this->assertNull($r['gesperrt_bis']);
    }

    public function test_fuenfter_fehlversuch_sperrt_fuenf_minuten_und_setzt_zurueck(): void
    {
        $jetzt = new DateTimeImmutable('2026-10-05 12:00:00');
        $r     = PinSperre::nachFehlversuch(4, $jetzt);

        $this->assertSame(0, $r['fehlversuche']);
        $this->assertEquals(new DateTimeImmutable('2026-10-05 12:05:00'), $r['gesperrt_bis']);
    }

    public function test_ist_gesperrt_grenzen(): void
    {
        $bis = new DateTimeImmutable('2026-10-05 12:05:00');

        $this->assertTrue(PinSperre::istGesperrt($bis, new DateTimeImmutable('2026-10-05 12:04:59')));
        $this->assertFalse(PinSperre::istGesperrt($bis, new DateTimeImmutable('2026-10-05 12:05:00')));
        $this->assertFalse(PinSperre::istGesperrt(null, new DateTimeImmutable('2026-10-05 12:00:00')));
    }
}
