<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * R4: Deutsche Framework-Meldungen kommen aus codeigniter4/translations.
 *
 * @internal
 */
final class SprachdateienTest extends CIUnitTestCase
{
    public function test_validierungsmeldung_ist_deutsch(): void
    {
        $text = lang('Validation.required', ['field' => 'Name']);

        $this->assertNotSame('The Name field is required.', $text);
        $this->assertStringContainsString('Name', $text);
        $this->assertStringContainsString('erforderlich', $text);
    }

    public function test_csrf_meldung_ist_deutsch(): void
    {
        $this->assertNotSame('The action you requested is not allowed.', lang('Security.disallowedAction'));
    }
}
