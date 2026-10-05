<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class HealthTest extends CIUnitTestCase
{
    public function test_app_laeuft_in_berliner_zeit(): void
    {
        $this->assertSame('Europe/Berlin', date_default_timezone_get());
    }
}
