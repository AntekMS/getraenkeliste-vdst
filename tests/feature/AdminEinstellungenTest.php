<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\BuchungAbgelehnt;
use App\Models\BuchungModel;
use App\Models\EinstellungModel;
use App\Models\ProtokollModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AdminEinstellungenTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen('2026-10-05 12:00:00');
        $this->admin = $this->personAnlegen(['benutzername' => 'chef']);
        $this->rolleGeben($this->admin, 'admin');
    }

    /**
     * @param array<string, string> $felder
     */
    private function speichern(array $felder): TestResponse
    {
        return $this->alsAngemeldet($this->admin)->post('admin/einstellungen', $felder + $this->csrf());
    }

    private function protokollAnzahl(): int
    {
        return (new ProtokollModel())->where('tabelle', 'einstellungen')->countAllResults();
    }

    public function test_formular_zeigt_aenderbare_felder_und_inbetriebnahme_nur_lesend(): void
    {
        $antwort = $this->alsAngemeldet($this->admin)->get('admin/einstellungen');

        $antwort->assertOK();
        $antwort->assertSee('Storno-Frist (Minuten)');
        $this->assertStringContainsString('name="storno_frist_min"', $antwort->getBody());
        $this->assertStringContainsString('name="vereinsname"', $antwort->getBody());
        $this->assertStringNotContainsString('name="inbetriebnahme_at"', $antwort->getBody());
        $antwort->assertSee(date('d.m.Y H:i', strtotime((string) (new EinstellungModel())->find('inbetriebnahme_at')['wert'])));
    }

    public function test_speichern_zeigt_flash_und_protokolliert_nur_geaenderte(): void
    {
        $antwort = $this->speichern(['storno_frist_min' => '5', 'tablet_timeout_s' => '30', 'vereinsname' => 'Verein deutscher Studenten zu Erlangen', 'erinnerung_tage' => '31']);

        $antwort->assertRedirectTo(site_url('admin/einstellungen'));
        $antwort->assertSessionHas('success', 'Einstellungen gespeichert.');
        $this->assertSame(5, service('einstellungen')->int('storno_frist_min'));
        $this->assertSame(1, $this->protokollAnzahl());
    }

    public function test_storno_frist_aendern_wirkt_auf_storno(): void
    {
        $person  = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-01-01 00:00:00']);
        $buchung = (int) (new BuchungModel())->insert([
            'vorgang_id' => 'aaaaaaaa-0000-4000-8000-000000000000', 'konto_id' => $person, 'artikel_id' => $artikel, 'menge' => 1,
            'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_von_id' => $person, 'gebucht_at' => '2026-10-05 11:59:59',
        ], true);

        $this->speichern(['storno_frist_min' => '0'])->assertSessionHas('success');
        $this->resetServices();
        $this->uhrStellen('2026-10-05 12:00:00');

        $this->expectException(BuchungAbgelehnt::class);
        $this->expectExceptionMessage('Die Storno-Frist ist abgelaufen.');
        service('buchungen')->storniereBuchung($buchung, $person);
    }

    public function test_ungueltiger_wert_zeigt_fehler(): void
    {
        $antwort = $this->speichern(['storno_frist_min' => '500', 'vereinsname' => 'Neuer Name']);

        $antwort->assertOK();
        $antwort->assertSee('Bitte eine ganze Zahl von 0 bis 120 eingeben.');
        $this->assertStringContainsString('value="500"', $antwort->getBody());
        $this->assertSame(10, service('einstellungen')->int('storno_frist_min'));
        $this->assertSame(0, $this->protokollAnzahl());
    }

    public function test_inbetriebnahme_nicht_aenderbar(): void
    {
        $vorher = (new EinstellungModel())->find('inbetriebnahme_at')['wert'];

        $this->speichern(['inbetriebnahme_at' => '2020-01-01 00:00:00']);

        $this->assertSame($vorher, (new EinstellungModel())->find('inbetriebnahme_at')['wert']);
        $this->assertSame(0, $this->protokollAnzahl());
    }
}
