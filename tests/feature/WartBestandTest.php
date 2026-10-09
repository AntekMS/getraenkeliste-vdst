<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartBestandTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->inbetriebnahmeSetzen('2026-10-01 00:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');
    }

    private function mitRolle(string $rolle): int
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, $rolle);

        return $id;
    }

    public function test_getraenkewart_sieht_bestand(): void
    {
        $this->artikelAnlegen(['name' => 'Helles']);

        $antwort = $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/bestand');

        $antwort->assertStatus(200);
        $antwort->assertSee('Helles');
        $antwort->assertSee('Lieferung erfassen');
        $antwort->assertSee('wart/getraenke/lieferung');
    }

    public function test_mitglied_bekommt_403(): void
    {
        $this->alsAngemeldet($this->personAnlegen())->get('wart/getraenke/bestand')->assertStatus(403);
    }

    public function test_kiosk_ist_404_auch_fuer_admin(): void
    {
        $admin = $this->mitRolle('admin');

        try {
            $status = $this->alsAngemeldet($admin)->get('wart/kiosk/bestand')->getStatusCode();
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status);
    }

    public function test_getraenkewart_auf_kiosk_403(): void
    {
        $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/kiosk/bestand')->assertStatus(403);
    }

    public function test_negativer_bestand_zeigt_warnhinweis(): void
    {
        $a = $this->artikelAnlegen();
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => str_repeat('a', 36), 'konto_id' => $this->personAnlegen(), 'artikel_id' => $a, 'menge' => 3,
            'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_at' => '2026-10-09 10:00:00',
        ]);

        $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/bestand')
            ->assertSee('Der Bestand ist negativ');
    }

    public function test_ohne_negativen_bestand_kein_hinweis(): void
    {
        $this->artikelAnlegen();

        $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/bestand')
            ->assertDontSee('Der Bestand ist negativ');
    }
}
