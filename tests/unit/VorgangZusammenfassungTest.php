<?php

declare(strict_types=1);

use App\Libraries\BuchungService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class VorgangZusammenfassungTest extends CIUnitTestCase
{
    public function test_beispiel_aus_der_spec(): void
    {
        $text = BuchungService::zusammenfassung([
            ['name' => 'Helles', 'menge' => 3, 'einzelpreis_cent' => 150],
            ['name' => 'Spezi', 'menge' => 1, 'einzelpreis_cent' => 200],
        ]);

        $this->assertSame('3× Helles, 1× Spezi – 6,50 €', $text);
    }
}
