<?php

declare(strict_types=1);

namespace Tests\Database;

use Tests\Support\DbTestCase;

final class ProtokolliererTest extends DbTestCase
{
    public function testHashFelderWerdenNichtGespeichert(): void
    {
        $id = $this->personAnlegen();
        $this->uhrStellen('2026-10-05 08:30:00');

        service('protokollierer')->schreibe(
            $id,
            'geaendert',
            'personen',
            $id,
            ['passwort_hash' => 'alt-geheim', 'pin_hash' => 'x', 'vorname' => 'Jörg'],
            ['passwort_hash' => 'neu-geheim', 'vorname' => 'Jürgen'],
        );

        $zeile = db_connect()->table('protokoll')->get()->getRowArray();

        $this->assertStringNotContainsString('hash', $zeile['alt'] . $zeile['neu']);
        $this->assertStringNotContainsString('geheim', $zeile['alt'] . $zeile['neu']);
        // MySQL normalisiert JSON-Spalten beim Lesen (Leerzeichen nach dem Doppelpunkt), daher dekodiert vergleichen.
        $this->assertSame(['vorname' => 'Jörg'], json_decode($zeile['alt'], true));
        $this->assertStringContainsString('Jörg', $zeile['alt']);
        $this->assertSame(['vorname' => 'Jürgen'], json_decode($zeile['neu'], true));
        $this->assertSame('2026-10-05 08:30:00', $zeile['erfolgt_at']);
        $this->assertSame($id, (int) $zeile['datensatz_id']);
    }

    public function testNullFuerAltUndNeuBleibtNull(): void
    {
        $id = $this->personAnlegen();

        service('protokollierer')->schreibe($id, 'angelegt', 'personen', $id);

        $zeile = db_connect()->table('protokoll')->get()->getRowArray();
        $this->assertNull($zeile['alt']);
        $this->assertNull($zeile['neu']);
    }
}
