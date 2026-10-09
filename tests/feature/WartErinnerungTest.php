<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartErinnerungTest extends DbTestCase
{
    private function inbetriebnahme(string $zeit): void
    {
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => $zeit]);
    }

    private function mitRolle(string $rolle): int
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, $rolle);

        return $id;
    }

    public function test_wart_sieht_banner_ohne_auszaehlung_nach_32_tagen(): void
    {
        $this->inbetriebnahme('2026-09-01 08:00:00');
        $this->uhrStellen('2026-10-03 12:00:00');

        $antwort = $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/einkauf');

        $antwort->assertStatus(200);
        $antwort->assertSee('Es gab noch keine Auszählung.');
        $antwort->assertSee('wart/getraenke/auszaehlung');
    }

    public function test_wart_sieht_tage_seit_letzter_auszaehlung(): void
    {
        $this->inbetriebnahme('2026-01-01 08:00:00');
        $this->auszaehlungAnlegen('2026-09-01 10:00:00');
        $this->uhrStellen('2026-10-03 09:00:00');

        $antwort = $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/einkauf');

        $antwort->assertSee('Die letzte Auszählung ist 32 Tage her.');
    }

    public function test_kein_banner_bis_zur_schwelle_und_nach_frischer_auszaehlung(): void
    {
        $this->inbetriebnahme('2026-01-01 08:00:00');
        $this->auszaehlungAnlegen('2026-09-02 10:00:00');
        $this->uhrStellen('2026-10-03 09:00:00'); // genau 31 Tage

        $antwort = $this->alsAngemeldet($this->mitRolle('getraenkewart'))->get('wart/getraenke/einkauf');

        $antwort->assertDontSee('Auszählung ist');
        $antwort->assertDontSee('Es gab noch keine Auszählung.');
    }

    public function test_mitglied_sieht_kein_banner(): void
    {
        $this->inbetriebnahme('2026-01-01 08:00:00');
        $this->uhrStellen('2026-10-03 09:00:00');

        $antwort = $this->alsAngemeldet($this->personAnlegen())->get('meine-buchungen');

        $antwort->assertStatus(200);
        $antwort->assertDontSee('Es gab noch keine Auszählung.');
    }

    public function test_admin_sieht_banner_nur_fuer_aktive_bereiche(): void
    {
        $this->inbetriebnahme('2026-01-01 08:00:00');
        $this->uhrStellen('2026-10-03 09:00:00');

        $antwort = $this->alsAngemeldet($this->mitRolle('admin'))->get('meine-buchungen');

        $antwort->assertSee('wart/getraenke/auszaehlung');
        $antwort->assertDontSee('wart/kiosk/auszaehlung');
    }

    public function test_tage_zaehlen_volle_kalendertage(): void
    {
        $this->inbetriebnahme('2026-01-01 08:00:00');
        $this->auszaehlungAnlegen('2026-10-01 23:59:00');
        $this->uhrStellen('2026-10-03 00:01:00');

        $this->assertSame(2, service('zeitraeume')->tageSeitLetztemAbschluss($this->bereichId('getraenke')));
    }
}
