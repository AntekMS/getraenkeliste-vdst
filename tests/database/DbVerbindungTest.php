<?php

use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class DbVerbindungTest extends DbTestCase
{
    public function test_testdatenbank_erreichbar(): void
    {
        $this->assertSame('getraenkeliste_test', db_connect('tests')->getDatabase());
    }
}
