<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Anmelderegeln;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AnmelderegelnTest extends CIUnitTestCase
{
    public function test_pin(): void
    {
        $this->assertNull(Anmelderegeln::pinFehler('1234'));
        $this->assertNull(Anmelderegeln::pinFehler('123456'));
        $this->assertIsString(Anmelderegeln::pinFehler('123'));
        $this->assertIsString(Anmelderegeln::pinFehler('1234567'));
        $this->assertIsString(Anmelderegeln::pinFehler('12a4'));
        $this->assertIsString(Anmelderegeln::pinFehler("1234\n"));
    }

    public function test_passwort(): void
    {
        $this->assertIsString(Anmelderegeln::passwortFehler('1234567'));
        $this->assertNull(Anmelderegeln::passwortFehler('12345678'));
    }

    public function test_benutzername_normalisieren(): void
    {
        $this->assertSame('max.muster', Anmelderegeln::benutzernameNormalisieren(' Max.Muster '));
        $this->assertNull(Anmelderegeln::benutzernameNormalisieren('ä'));
        $this->assertNull(Anmelderegeln::benutzernameNormalisieren('ab'));
        $this->assertNull(Anmelderegeln::benutzernameNormalisieren('max muster'));
        $this->assertNull(Anmelderegeln::benutzernameNormalisieren(str_repeat('a', 41)));
    }
}
