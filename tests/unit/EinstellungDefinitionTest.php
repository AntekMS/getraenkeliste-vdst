<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\EinstellungDefinition;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class EinstellungDefinitionTest extends CIUnitTestCase
{
    public function test_storno_frist(): void
    {
        $this->assertIsString(EinstellungDefinition::validiere('storno_frist_min', 'abc'));
        $this->assertIsString(EinstellungDefinition::validiere('storno_frist_min', '121'));
        $this->assertIsString(EinstellungDefinition::validiere('storno_frist_min', '-1'));
        $this->assertNull(EinstellungDefinition::validiere('storno_frist_min', '0'));
        $this->assertNull(EinstellungDefinition::validiere('storno_frist_min', '120'));
    }

    public function test_weitere_werte(): void
    {
        $this->assertIsString(EinstellungDefinition::validiere('tablet_timeout_s', '9'));
        $this->assertNull(EinstellungDefinition::validiere('tablet_timeout_s', '300'));
        // Höchstens so lang wie die Tablet-Sitzung (300 s), sonst liefe die Sitzung vor dem Leerlauf-Timeout ab.
        $this->assertIsString(EinstellungDefinition::validiere('tablet_timeout_s', '301'));
        $this->assertIsString(EinstellungDefinition::validiere('erinnerung_tage', '366'));
        $this->assertNull(EinstellungDefinition::validiere('erinnerung_tage', '31'));
        $this->assertIsString(EinstellungDefinition::validiere('vereinsname', ''));
        $this->assertIsString(EinstellungDefinition::validiere('vereinsname', str_repeat('a', 101)));
        $this->assertNull(EinstellungDefinition::validiere('vereinsname', 'Verein'));
    }

    public function test_reichweite_tage(): void
    {
        $this->assertIsString(EinstellungDefinition::validiere('reichweite_tage', '6'));
        $this->assertNull(EinstellungDefinition::validiere('reichweite_tage', '7'));
        $this->assertNull(EinstellungDefinition::validiere('reichweite_tage', '90'));
        $this->assertIsString(EinstellungDefinition::validiere('reichweite_tage', '91'));
        $this->assertSame('30', EinstellungDefinition::DEFINITIONEN['reichweite_tage']['default']);
    }

    public function test_unbekannter_schluessel_ist_fehler(): void
    {
        $this->assertIsString(EinstellungDefinition::validiere('gibt_es_nicht', '1'));
    }

    public function test_inbetriebnahme_nicht_aenderbar(): void
    {
        $this->assertArrayHasKey('inbetriebnahme_at', EinstellungDefinition::DEFINITIONEN);
        $this->assertNotContains('inbetriebnahme_at', EinstellungDefinition::aenderbar());
        $this->assertContains('storno_frist_min', EinstellungDefinition::aenderbar());
        $this->assertIsString(EinstellungDefinition::validiere('inbetriebnahme_at', '2026-10-05 12:00:00'));
    }

    public function test_defaults(): void
    {
        $this->assertSame('10', EinstellungDefinition::DEFINITIONEN['storno_frist_min']['default']);
        $this->assertSame('Verein deutscher Studenten zu Erlangen', EinstellungDefinition::DEFINITIONEN['vereinsname']['default']);
    }
}
