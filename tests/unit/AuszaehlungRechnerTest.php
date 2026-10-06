<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\AuszaehlungRechner;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;

/**
 * @internal
 */
final class AuszaehlungRechnerTest extends CIUnitTestCase
{
    public function test_position_mit_schwund(): void
    {
        $p = AuszaehlungRechner::position(10, 20, -2, 0, 8, 18, 150, false);

        $this->assertSame(20, $p['soll']);
        $this->assertSame(18, $p['ist']);
        $this->assertSame(-2, $p['differenz']);
        $this->assertSame(-300, $p['differenz_cent']);
        $this->assertSame(10, $p['anfangsbestand']);
        $this->assertSame(20, $p['lieferungen']);
        $this->assertSame(-2, $p['schwund_erfasst']);
        $this->assertSame(0, $p['korrekturen']);
        $this->assertSame(8, $p['verkauft']);
        $this->assertFalse($p['start']);
    }

    public function test_position_mit_korrekturen_im_soll(): void
    {
        $p = AuszaehlungRechner::position(10, 0, 0, -3, 2, 5, 100, false);

        $this->assertSame(5, $p['soll']);
        $this->assertSame(0, $p['differenz']);
        $this->assertSame(0, $p['differenz_cent']);
    }

    public function test_position_ueberschuss(): void
    {
        $p = AuszaehlungRechner::position(10, 0, 0, 0, 0, 13, 150, false);

        $this->assertSame(3, $p['differenz']);
        $this->assertSame(450, $p['differenz_cent']);
    }

    public function test_position_ohne_ist(): void
    {
        $p = AuszaehlungRechner::position(10, 0, 0, 0, 4, null, 150, false);

        $this->assertSame(6, $p['soll']);
        $this->assertNull($p['ist']);
        $this->assertNull($p['differenz']);
        $this->assertNull($p['differenz_cent']);
    }

    public function test_start_position_zeigt_differenz(): void
    {
        $p = AuszaehlungRechner::position(0, 0, 0, 0, 0, 12, 150, true);

        $this->assertTrue($p['start']);
        $this->assertSame(12, $p['differenz']);
    }

    public function test_schwund_cent_summiert_betraege_negativer_positionen(): void
    {
        $positionen = [
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 8, 150, false),    // -300
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 9, 200, false),    // -200
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 13, 150, false),   // Überschuss
            AuszaehlungRechner::position(10, 0, 0, 0, 0, null, 150, false), // nicht gezählt
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 10, 150, false),   // 0
        ];

        $this->assertSame(500, AuszaehlungRechner::schwundCent($positionen, 'abschluss'));
    }

    public function test_schwund_cent_ignoriert_start_positionen(): void
    {
        $positionen = [
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 8, 150, true),
            AuszaehlungRechner::position(10, 0, 0, 0, 0, 8, 150, false),
        ];

        $this->assertSame(300, AuszaehlungRechner::schwundCent($positionen, 'abschluss'));
    }

    public function test_schwund_cent_ist_null_bei_art_start(): void
    {
        $positionen = [AuszaehlungRechner::position(10, 0, 0, 0, 0, 8, 150, false)];

        $this->assertSame(0, AuszaehlungRechner::schwundCent($positionen, 'start'));
    }

    public function test_schwund_cent_leer(): void
    {
        $this->assertSame(0, AuszaehlungRechner::schwundCent([], 'abschluss'));
    }

    /**
     * @return iterable<string, array{string, ?string, string, ?string}>
     */
    public static function stichtagFaelle(): iterable
    {
        $fehlerFrueh = 'Der Stichtag muss nach dem letzten Abschluss liegen.';
        $fehlerZukunft = 'Der Stichtag darf nicht in der Zukunft liegen.';

        yield 'gleich letztem Stichtag' => ['2026-10-01 12:00:00', '2026-10-01 12:00:00', '2026-10-05 12:00:00', $fehlerFrueh];
        yield 'vor letztem Stichtag' => ['2026-09-30 12:00:00', '2026-10-01 12:00:00', '2026-10-05 12:00:00', $fehlerFrueh];
        yield 'eine Minute nach letztem' => ['2026-10-01 12:01:00', '2026-10-01 12:00:00', '2026-10-05 12:00:00', null];
        yield 'kein letzter Stichtag' => ['2026-10-01 12:00:00', null, '2026-10-05 12:00:00', null];
        yield 'genau jetzt' => ['2026-10-05 12:00:00', '2026-10-01 12:00:00', '2026-10-05 12:00:00', null];
        yield 'eine Minute in der Zukunft' => ['2026-10-05 12:01:00', '2026-10-01 12:00:00', '2026-10-05 12:00:00', $fehlerZukunft];
        yield 'Zukunft ohne letzten Stichtag' => ['2026-10-06 00:00:00', null, '2026-10-05 12:00:00', $fehlerZukunft];
    }

    /**
     * @dataProvider stichtagFaelle
     */
    public function test_pruefe_stichtag(string $stichtag, ?string $letzter, string $jetzt, ?string $erwartet): void
    {
        $this->assertSame($erwartet, AuszaehlungRechner::pruefeStichtag(
            new DateTimeImmutable($stichtag),
            $letzter === null ? null : new DateTimeImmutable($letzter),
            new DateTimeImmutable($jetzt),
        ));
    }
}
