<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\BestandRechner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BestandRechnerTest extends CIUnitTestCase
{
    public function test_bestand_ist_plus_bewegungen_minus_verkauf(): void
    {
        $this->assertSame(29, BestandRechner::bestand(10, 24, 5));
        $this->assertSame(-3, BestandRechner::bestand(0, 2, 5));
        $this->assertSame(0, BestandRechner::bestand(0, 0, 0));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function ampelFaelle(): iterable
    {
        yield 'negativ' => [-1, 5, 'negativ'];
        yield 'negativ ohne Mindestbestand' => [-1, 0, 'negativ'];
        yield 'leer' => [0, 5, 'leer'];
        yield 'leer bei Mindestbestand 0' => [0, 0, 'leer'];
        yield 'niedrig' => [4, 5, 'niedrig'];
        yield 'genau Mindestbestand ist ok' => [5, 5, 'ok'];
        yield 'ok' => [20, 5, 'ok'];
        yield 'ok bei Mindestbestand 0' => [1, 0, 'ok'];
    }

    /**
     * @dataProvider ampelFaelle
     */
    public function test_ampel(int $bestand, int $mindest, string $erwartet): void
    {
        $this->assertSame($erwartet, BestandRechner::ampel($bestand, $mindest));
    }
}
