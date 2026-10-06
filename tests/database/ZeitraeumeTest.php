<?php

declare(strict_types=1);

namespace Tests\Database;

use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class ZeitraeumeTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-01-01 08:00:00']);
    }

    private function z(string $zeit): DateTimeImmutable
    {
        return new DateTimeImmutable($zeit, new DateTimeZone('Europe/Berlin'));
    }

    public function test_ohne_auszaehlung_beginnt_der_zeitraum_inklusiv_bei_inbetriebnahme(): void
    {
        $z       = service('zeitraeume');
        $bereich = $this->bereichId('getraenke');

        $this->assertNull($z->letzterStichtag($bereich));
        $this->assertSame('2026-01-01 08:00:00', $z->beginn($bereich)->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Berlin', $z->beginn($bereich)->getTimezone()->getName());
        $this->assertTrue($z->beginnInklusiv($bereich));
        $this->assertFalse($z->istEingefroren($this->z('2026-01-01 07:00:00'), $bereich));
        $this->assertSame([], $z->fruehere($bereich));
    }

    public function test_nach_abschluss_beginnt_der_zeitraum_exklusiv_am_stichtag_entwurf_zaehlt_nicht(): void
    {
        $bereich = $this->bereichId('getraenke');
        $this->auszaehlungAnlegen('2026-03-01 18:00:00');
        $this->auszaehlungAnlegen('2026-04-01 18:00:00', 'entwurf');

        $z = service('zeitraeume');

        $this->assertSame('2026-03-01 18:00:00', $z->letzterStichtag($bereich)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 18:00:00', $z->beginn($bereich)->format('Y-m-d H:i:s'));
        $this->assertFalse($z->beginnInklusiv($bereich));
    }

    public function test_eingefroren_bis_einschliesslich_grenzsekunde(): void
    {
        $bereich = $this->bereichId('getraenke');
        $this->auszaehlungAnlegen('2026-03-01 18:00:00');

        $z = service('zeitraeume');

        $this->assertTrue($z->istEingefroren($this->z('2026-02-15 10:00:00'), $bereich));
        $this->assertTrue($z->istEingefroren($this->z('2026-03-01 18:00:00'), $bereich));
        $this->assertFalse($z->istEingefroren($this->z('2026-03-01 18:00:01'), $bereich));
    }

    public function test_bereiche_sind_unabhaengig(): void
    {
        $getraenke = $this->bereichId('getraenke');
        $kiosk     = $this->bereichId('kiosk');
        $this->auszaehlungAnlegen('2026-03-01 18:00:00', 'abgeschlossen', 'kiosk');

        $z = service('zeitraeume');

        $this->assertNull($z->letzterStichtag($getraenke));
        $this->assertTrue($z->beginnInklusiv($getraenke));
        $this->assertFalse($z->istEingefroren($this->z('2026-02-15 10:00:00'), $getraenke));
        $this->assertTrue($z->istEingefroren($this->z('2026-02-15 10:00:00'), $kiosk));
        $this->assertSame('2026-03-01 18:00:00', $z->beginn($kiosk)->format('Y-m-d H:i:s'));
    }

    public function test_fruehere_zeitraeume_neueste_zuerst(): void
    {
        $bereich = $this->bereichId('getraenke');
        $erste   = $this->auszaehlungAnlegen('2026-03-01 18:00:00');
        $zweite  = $this->auszaehlungAnlegen('2026-05-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-03-01 18:00:00']);
        $this->auszaehlungAnlegen('2026-06-01 18:00:00', 'entwurf');

        $liste = service('zeitraeume')->fruehere($bereich);

        $this->assertCount(2, $liste);
        $this->assertSame($zweite, $liste[0]['auszaehlung_id']);
        $this->assertSame('2026-03-01 18:00:00', $liste[0]['von']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 18:00:00', $liste[0]['bis']->format('Y-m-d H:i:s'));
        $this->assertFalse($liste[0]['von_inklusiv']);
        $this->assertSame($erste, $liste[1]['auszaehlung_id']);
        $this->assertSame('2026-01-01 08:00:00', $liste[1]['von']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 18:00:00', $liste[1]['bis']->format('Y-m-d H:i:s'));
        $this->assertTrue($liste[1]['von_inklusiv']);
    }

    public function test_cache_bis_vergiss(): void
    {
        $bereich = $this->bereichId('getraenke');
        $z       = service('zeitraeume');

        $this->assertNull($z->letzterStichtag($bereich));

        $this->auszaehlungAnlegen('2026-03-01 18:00:00');
        $this->assertNull($z->letzterStichtag($bereich));

        $z->vergiss();
        $this->assertSame('2026-03-01 18:00:00', $z->letzterStichtag($bereich)->format('Y-m-d H:i:s'));
    }
}
