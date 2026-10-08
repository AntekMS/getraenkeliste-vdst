<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\ZeitraumErmittler;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * @internal
 */
final class ZeitraumErmittlerTest extends CIUnitTestCase
{
    private function z(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s);
    }

    public function test_ohne_stichtag_zaehlt_ab_inbetriebnahme(): void
    {
        $inbetrieb = $this->z('2026-10-01 08:00:00');

        $this->assertTrue(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-01 08:00:00'), $inbetrieb, null));
        $this->assertTrue(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-05 12:00:00'), $inbetrieb, null));
        $this->assertFalse(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-01 07:59:59'), $inbetrieb, null));
    }

    public function test_mit_stichtag_zaehlt_nach_stichtag(): void
    {
        $inbetrieb = $this->z('2026-10-01 08:00:00');
        $stichtag  = $this->z('2026-10-10 18:00:00');

        $this->assertFalse(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-10 18:00:00'), $inbetrieb, $stichtag));
        $this->assertTrue(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-10 18:00:01'), $inbetrieb, $stichtag));
        $this->assertFalse(ZeitraumErmittler::gehoertZumLaufendenZeitraum($this->z('2026-10-05 12:00:00'), $inbetrieb, $stichtag));
    }

    public function test_eingefroren(): void
    {
        $stichtag = $this->z('2026-10-10 18:00:00');

        $this->assertFalse(ZeitraumErmittler::istEingefroren($this->z('2026-10-05 12:00:00'), null));
        $this->assertTrue(ZeitraumErmittler::istEingefroren($this->z('2026-10-10 18:00:00'), $stichtag));
        $this->assertFalse(ZeitraumErmittler::istEingefroren($this->z('2026-10-10 18:00:01'), $stichtag));
    }
}
