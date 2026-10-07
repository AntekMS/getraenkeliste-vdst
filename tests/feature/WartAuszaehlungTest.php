<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartAuszaehlungTest extends DbTestCase
{
    private int $wart;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        \Config\Services::resetSingle('einstellungen');
        $this->uhrStellen('2026-10-10 12:00:30');
        $this->wart = $this->personAnlegen();
        $this->rolleGeben($this->wart, 'getraenkewart');
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function sende(array $daten): \CodeIgniter\Test\TestResponse
    {
        return $this->alsAngemeldet($this->wart)->post('wart/getraenke/auszaehlung', $daten + ['aktion' => 'entwurf'] + $this->csrf());
    }

    public function test_formular_zeigt_soll_stichtag_und_genau_einen_primaerknopf(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $a, 'art' => 'lieferung', 'menge' => 24, 'person_id' => $this->wart, 'erfolgt_at' => '2026-10-02 10:00:00',
        ]);

        $antwort = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung');

        $antwort->assertOK();
        $this->assertStringContainsString('data-soll="24"', $antwort->getBody());
        $antwort->assertSee('Helles');
        $this->assertStringContainsString('value="2026-10-10T12:00"', $antwort->getBody());
        $this->assertSame(1, substr_count($antwort->getBody(), 'btn-vdst"'));
        $this->assertStringContainsString('Entwurf speichern', $antwort->getBody());
    }

    public function test_stichtag_parameter_laedt_das_soll_neu(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $a, 'art' => 'lieferung', 'menge' => 24, 'person_id' => $this->wart, 'erfolgt_at' => '2026-10-05 10:00:00',
        ]);

        $antwort = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung?stichtag=2026-10-04T10:00');

        $this->assertStringContainsString('data-soll="0"', $antwort->getBody());
    }

    public function test_entwurf_speichern_legt_einen_entwurf_an_und_zeigt_ist_wieder(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => '5'], 'bemerkung' => 'Probe'])
            ->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));

        $this->assertSame(1, db_connect()->table('auszaehlungen')->where('status', 'entwurf')->countAllResults());
        $seite = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung');
        $this->assertStringContainsString('value="2026-10-09T08:00"', $seite->getBody());
        $this->assertStringContainsString('value="5"', $seite->getBody());
        $seite->assertSee('Probe');
    }

    public function test_zweites_speichern_ersetzt_den_entwurf(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => '5']]);
        $this->sende(['stichtag' => '2026-10-09T09:00', 'ist' => [$a => '']]);

        $this->assertSame(1, db_connect()->table('auszaehlungen')->countAllResults());
        $this->assertNull(db_connect()->table('auszaehlung_positionen')->get()->getRowArray()['ist']);
    }

    public function test_ungueltiger_stichtag_wird_mit_feldfehler_abgelehnt(): void
    {
        $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $this->sende(['stichtag' => 'gestern', 'ist' => []])->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_stichtag_in_der_zukunft_wird_abgelehnt(): void
    {
        $antwort = $this->sende(['stichtag' => '2026-10-10T12:05', 'ist' => []]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
        $antwort->assertSessionHas('fehler', ['stichtag' => 'Der Stichtag darf nicht in der Zukunft liegen.']);

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_ungueltiges_oder_negatives_ist_wird_abgelehnt(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        foreach (['-1', 'abc', '1,5'] as $wert) {
            $antwort = $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => $wert]]);

            $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
            $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Ist muss eine ganze Zahl ≥ 0 sein.']);
        }

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_unbekannte_aktion_wird_abgelehnt(): void
    {
        $this->sende(['aktion' => 'abschliessen', 'stichtag' => '2026-10-09T08:00', 'ist' => []]);

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_ungueltiger_stichtag_in_der_adresse_zeigt_feldfehler(): void
    {
        $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $seite = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung?stichtag=kaputt');

        $seite->assertOK();
        $seite->assertSee('Bitte einen gültigen Stichtag angeben.');
    }

    public function test_array_werte_fuehren_nicht_zu_einem_serverfehler(): void
    {
        $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung?stichtag[]=x&stichtag[]=y')->assertOK();
        $antwort = $this->sende(['stichtag' => ['x'], 'bemerkung' => ['y'], 'ist' => []]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());

        $this->sende(['stichtag' => '2026-10-09T08:00', 'bemerkung' => ['y'], 'ist' => []]);
        $this->assertSame(1, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_zu_lange_bemerkung_wird_mit_feldfehler_abgelehnt(): void
    {
        $antwort = $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [], 'bemerkung' => str_repeat('a', 1001)]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
        $antwort->assertSessionHas('fehler');
        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }
}
