<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\EinmalPasswort;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EinmalPasswortTest extends CIUnitTestCase
{
    public function test_laenge_und_alphabet(): void
    {
        $passwort = EinmalPasswort::erzeuge();

        $this->assertSame(10, strlen($passwort));
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{10}$/', $passwort);
        $this->assertSame(16, strlen(EinmalPasswort::erzeuge(16)));
    }

    public function test_aufrufe_unterscheiden_sich(): void
    {
        $this->assertNotSame(EinmalPasswort::erzeuge(), EinmalPasswort::erzeuge());
    }
}
