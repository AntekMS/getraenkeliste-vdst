<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\StatistikRechner;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @internal
 */
final class StatistikRechnerTest extends CIUnitTestCase
{
    private function tag(string $datum): DateTimeImmutable
    {
        return new DateTimeImmutable($datum, new DateTimeZone('Europe/Berlin'));
    }

    public function test_grundlage_tage(): void
    {
        $start = $this->tag('2026-10-01 18:00:00');

        $this->assertSame(5, StatistikRechner::grundlageTage($start, $this->tag('2026-10-06 09:00:00')));
        $this->assertSame(28, StatistikRechner::grundlageTage($start, $this->tag('2026-11-10 09:00:00')));
        $this->assertSame(1, StatistikRechner::grundlageTage($start, $this->tag('2026-10-01 23:00:00')));
    }

    public function test_tagesverbrauch(): void
    {
        $this->assertSame(2.5, StatistikRechner::tagesverbrauch(70, 28));
    }

    public function test_reichweite(): void
    {
        $this->assertSame(14, StatistikRechner::reichweiteTage(29, 2.0));
        $this->assertSame(0, StatistikRechner::reichweiteTage(0, 2.0));
        $this->assertSame(0, StatistikRechner::reichweiteTage(-3, 2.0));
        $this->assertNull(StatistikRechner::reichweiteTage(5, 0.0));
    }

    public function test_vorschlag(): void
    {
        $this->assertSame(['stueck' => 60, 'kisten' => 3], StatistikRechner::vorschlag(2.0, 30, 10, 25, 20));
        $this->assertSame(['stueck' => 45, 'kisten' => null], StatistikRechner::vorschlag(2.0, 30, 10, 25, null));
        $this->assertSame(['stueck' => 45, 'kisten' => null], StatistikRechner::vorschlag(2.0, 30, 10, 25, 0));
        $this->assertNull(StatistikRechner::vorschlag(2.0, 30, 10, 100, 20));
        $this->assertNull(StatistikRechner::vorschlag(0.0, 30, 0, 0, 20));
    }

    public function test_anteile(): void
    {
        $this->assertSame(['mitglieder' => 60.0, 'couleur' => 30.0, 'bund' => 10.0], StatistikRechner::anteile(['mitglieder' => 60, 'couleur' => 30, 'bund' => 10]));
        $this->assertSame(['mitglieder' => null, 'couleur' => null, 'bund' => null], StatistikRechner::anteile(['mitglieder' => 0, 'couleur' => 0, 'bund' => 0]));
        $this->assertSame(33.3, StatistikRechner::anteile(['mitglieder' => 1, 'couleur' => 1, 'bund' => 1])['bund']);
    }

    public function test_quote(): void
    {
        $this->assertSame(1.5, StatistikRechner::quote(3, 200));
        $this->assertNull(StatistikRechner::quote(3, 0));
    }

    public function test_iso_woche_jahreswechsel(): void
    {
        $this->assertSame('2026-W53', StatistikRechner::isoWoche($this->tag('2026-12-31')));
        $this->assertSame('2027-W01', StatistikRechner::isoWoche($this->tag('2027-01-04')));
    }

    public function test_letzte_wochen_ueber_jahreswechsel(): void
    {
        $wochen = StatistikRechner::letzteWochen($this->tag('2027-01-10 12:00:00'), 12);

        $this->assertCount(12, $wochen);
        $this->assertCount(12, array_unique($wochen));
        $this->assertSame('2027-W01', $wochen[11]);
        $this->assertSame('2026-W53', $wochen[10]);
        $this->assertSame('2026-W52', $wochen[9]);
        $this->assertSame('2026-W51', $wochen[8]);
    }

    public function test_zeitumstellung_woche_genau_einmal(): void
    {
        // Sommerzeit endet am 2026-10-25 (KW 43).
        $wochen = StatistikRechner::letzteWochen($this->tag('2026-10-30 08:00:00'), 4);

        $this->assertSame(['2026-W41', '2026-W42', '2026-W43', '2026-W44'], $wochen);
        $this->assertSame('2026-W43', StatistikRechner::isoWoche($this->tag('2026-10-25')));
    }

    public function test_grundlage_tage_zukunft_und_zeitumstellung(): void
    {
        $this->assertSame(1, StatistikRechner::grundlageTage($this->tag('2026-10-10 10:00:00'), $this->tag('2026-10-01 10:00:00')));
        $this->assertSame(2, StatistikRechner::grundlageTage($this->tag('2026-10-24 18:00:00'), $this->tag('2026-10-26 09:00:00')));
    }

    public function test_vorschlag_ohne_gleitkomma_rauschen(): void
    {
        $verbrauch = StatistikRechner::tagesverbrauch(3, 10);

        $this->assertSame(['stueck' => 9, 'kisten' => null], StatistikRechner::vorschlag($verbrauch, 30, 0, 0, null));
        $this->assertSame(['stueck' => 9, 'kisten' => 1], StatistikRechner::vorschlag($verbrauch, 30, 0, 0, 9));
        $this->assertSame(['stueck' => 18, 'kisten' => 2], StatistikRechner::vorschlag(0.6, 30, 0, 0, 9));
        $this->assertSame(['stueck' => 21, 'kisten' => null], StatistikRechner::vorschlag(0.7, 30, 0, 0, null));
    }

    public function test_letzte_wochen_um_die_zeitumstellung(): void
    {
        $this->assertSame(['2026-W42', '2026-W43'], StatistikRechner::letzteWochen($this->tag('2026-10-25 23:30:00'), 2));
        $this->assertSame(['2026-W43', '2026-W44'], StatistikRechner::letzteWochen($this->tag('2026-10-26 00:30:00'), 2));
    }
}
