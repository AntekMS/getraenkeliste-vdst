<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\StornoFrist;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * @internal
 */
final class StornoFristTest extends CIUnitTestCase
{
    public function test_zehn_minuten_frist(): void
    {
        $gebucht = new DateTimeImmutable('2026-10-05 12:00:00');

        $this->assertTrue(StornoFrist::istOffen($gebucht, new DateTimeImmutable('2026-10-05 12:09:59'), 10));
        $this->assertTrue(StornoFrist::istOffen($gebucht, new DateTimeImmutable('2026-10-05 12:10:00'), 10));
        $this->assertFalse(StornoFrist::istOffen($gebucht, new DateTimeImmutable('2026-10-05 12:10:01'), 10));
    }

    public function test_frist_null_nur_exakt_gleiche_sekunde(): void
    {
        $gebucht = new DateTimeImmutable('2026-10-05 12:00:00');

        $this->assertTrue(StornoFrist::istOffen($gebucht, $gebucht, 0));
        $this->assertFalse(StornoFrist::istOffen($gebucht, new DateTimeImmutable('2026-10-05 12:00:01'), 0));
    }
}
