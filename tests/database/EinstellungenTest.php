<?php

declare(strict_types=1);

namespace Tests\Database;

use Tests\Support\DbTestCase;

final class EinstellungenTest extends DbTestCase
{
    public function testDefaultLesbar(): void
    {
        $e = service('einstellungen');

        $this->assertSame(10, $e->int('storno_frist_min'));
        $this->assertSame('Verein deutscher Studenten zu Erlangen', $e->text('vereinsname'));
        $this->assertSame('Europe/Berlin', $e->inbetriebnahme()->getTimezone()->getName());
    }

    public function testSetzeSpeichertUndProtokolliert(): void
    {
        $admin = $this->personAnlegen();
        $this->uhrStellen('2026-10-05 12:00:00');

        $this->assertNull(service('einstellungen')->setze('storno_frist_min', '15', $admin));

        $this->assertSame(15, service('einstellungen')->int('storno_frist_min'));
        $this->seeInDatabase('einstellungen', ['schluessel' => 'storno_frist_min', 'wert' => '15']);

        $zeile = db_connect()->table('protokoll')->get()->getRowArray();
        $this->assertSame($admin, (int) $zeile['person_id']);
        $this->assertSame('geaendert', $zeile['aktion']);
        $this->assertSame('einstellungen', $zeile['tabelle']);
        $this->assertNull($zeile['datensatz_id']);
        $this->assertSame(['wert' => '10'], json_decode($zeile['alt'], true));
        $this->assertSame(['wert' => '15'], json_decode($zeile['neu'], true));
        $this->assertSame('2026-10-05 12:00:00', $zeile['erfolgt_at']);
    }

    public function testNichtAenderbarerSchluesselWirdAbgelehnt(): void
    {
        $admin  = $this->personAnlegen();
        $vorher = service('einstellungen')->text('inbetriebnahme_at');

        $this->assertNotNull(service('einstellungen')->setze('inbetriebnahme_at', '2020-01-01 00:00:00', $admin));

        $this->assertSame($vorher, service('einstellungen')->text('inbetriebnahme_at'));
        $this->seeInDatabase('einstellungen', ['schluessel' => 'inbetriebnahme_at', 'wert' => $vorher]);
        $this->assertSame(0, db_connect()->table('protokoll')->countAllResults());
    }

    public function testUngueltigerWertAendertNichts(): void
    {
        $admin = $this->personAnlegen();

        $this->assertNotNull(service('einstellungen')->setze('storno_frist_min', 'abc', $admin));

        $this->assertSame(10, service('einstellungen')->int('storno_frist_min'));
        $this->seeInDatabase('einstellungen', ['schluessel' => 'storno_frist_min', 'wert' => '10']);
        $this->assertSame(0, db_connect()->table('protokoll')->countAllResults());
    }

    public function testUnveraenderterWertSchreibtKeinProtokoll(): void
    {
        $admin = $this->personAnlegen();

        $this->assertNull(service('einstellungen')->setze('storno_frist_min', '10', $admin));

        $this->assertSame(0, db_connect()->table('protokoll')->countAllResults());
    }

    public function testWerteWerdenProRequestEinmalGeladen(): void
    {
        $e = service('einstellungen');
        $this->assertSame(10, $e->int('storno_frist_min'));

        db_connect()->table('einstellungen')->where('schluessel', 'storno_frist_min')->update(['wert' => '99']);

        $this->assertSame(10, $e->int('storno_frist_min'));
    }

    public function testCacheLecktNichtZwischenTests(): void
    {
        // Der Test davor hat die Tabelle auf 99 gesetzt; nach frischer Migration muss wieder 10 gelten.
        $this->assertSame(10, service('einstellungen')->int('storno_frist_min'));
    }

    public function testUhrStellenFixiertJetzt(): void
    {
        $this->uhrStellen('2026-01-02 03:04:05');

        $this->assertSame('2026-01-02 03:04:05', service('uhr')->jetzt()->format('Y-m-d H:i:s'));
    }

    public function testUhrOhneFixierungLiefertEchteZeit(): void
    {
        $this->assertEqualsWithDelta(time(), service('uhr')->jetzt()->getTimestamp(), 5);
        $this->assertSame('Europe/Berlin', service('uhr')->jetzt()->getTimezone()->getName());
    }
}
