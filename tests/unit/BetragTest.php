<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BetragTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('betrag');
    }

    /**
     * @dataProvider normalisiereProvider
     */
    public function test_normalisiere_betrag(?string $eingabe, ?string $erwartet): void
    {
        $this->assertSame($erwartet, normalisiere_betrag($eingabe));
    }

    public static function normalisiereProvider(): array
    {
        return [
            'deutsches Komma'                 => ['10,50', '10.50'],
            'Tausenderpunkt mit Komma'        => ['1.234,56', '1234.56'],
            'Tausenderpunkt ohne Komma'       => ['1.000', '1000'],
            'mehrere Tausendergruppen'        => ['1.234.567', '1234567'],
            'Punkt als Dezimaltrenner (2 NK)' => ['10.50', '10.50'],
            'Punkt als Dezimaltrenner (1 NK)' => ['1.5', '1.5'],
            'ganze Zahl'                      => ['1000', '1000'],
            'mit Whitespace'                  => ['  42,00  ', '42.00'],
            'null bleibt null'                => [null, null],
            'leerer String'                   => ['', ''],
        ];
    }

    /**
     * @dataProvider centProvider
     */
    public function test_betrag_in_cent(?string $eingabe, ?int $erwartet): void
    {
        $this->assertSame($erwartet, betrag_in_cent($eingabe));
    }

    public static function centProvider(): array
    {
        return [
            'Komma'               => ['1,50', 150],
            'Punkt'               => ['1.50', 150],
            'ganze Zahl'          => ['2', 200],
            'eine Nachkommastelle' => ['0,5', 50],
            'Tausender mit Komma' => ['1.000,00', 100000],
            'Tausenderpunkt'      => ['1.000', 100000],
            'Whitespace'          => [' 3,20 ', 320],
            'null Euro'           => ['0', 0],
            'leer'                => ['', null],
            'Text'                => ['abc', null],
            'drei Nachkommastellen' => ['1,234', null],
            'negativ'             => ['-1,00', null],
            'null'                => [null, null],
            'sieben Euro-Stellen' => ['9999999,99', 999999999],
            'acht Euro-Stellen'   => ['10000000', null],
            'absurd lang'         => [str_repeat('9', 40), null],
        ];
    }

    /**
     * @dataProvider formatProvider
     */
    public function test_formatiere_cent(int $cent, string $erwartet): void
    {
        $this->assertSame($erwartet, formatiere_cent($cent));
    }

    public static function formatProvider(): array
    {
        return [
            'glatt'     => [650, '6,50 €'],
            'null'      => [0, '0,00 €'],
            'Tausender' => [123456, '1.234,56 €'],
            'negativ'   => [-120, '-1,20 €'],
        ];
    }
}
