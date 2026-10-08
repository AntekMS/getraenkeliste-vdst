<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Lieferumrechnung;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 */
final class LieferumrechnungTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{int, int, ?int, int}>
     */
    public static function gueltigeFaelle(): iterable
    {
        yield 'Kisten und Stück' => [2, 3, 20, 43];
        yield 'nur Stück ohne Gebinde' => [0, 7, null, 7];
        yield 'nur Kisten' => [3, 0, 24, 72];
        yield 'Kisten 0 mit Gebinde 0' => [0, 5, 0, 5];
        yield 'alles null' => [0, 0, null, 0];
    }

    /**
     * @dataProvider gueltigeFaelle
     */
    public function test_menge(int $kisten, int $stueck, ?int $gebinde, int $erwartet): void
    {
        $this->assertSame($erwartet, Lieferumrechnung::menge($kisten, $stueck, $gebinde));
    }

    /**
     * @return iterable<string, array{int, int, ?int}>
     */
    public static function ungueltigeFaelle(): iterable
    {
        yield 'Kisten ohne Gebinde' => [1, 0, null];
        yield 'Kisten mit Gebinde 0' => [1, 0, 0];
        yield 'negative Kisten' => [-1, 0, 20];
        yield 'negative Stück' => [0, -1, 20];
        yield 'negatives Gebinde' => [0, 1, -5];
    }

    /**
     * @dataProvider ungueltigeFaelle
     */
    public function test_ungueltig_wirft(int $kisten, int $stueck, ?int $gebinde): void
    {
        $this->expectException(InvalidArgumentException::class);
        Lieferumrechnung::menge($kisten, $stueck, $gebinde);
    }
}
