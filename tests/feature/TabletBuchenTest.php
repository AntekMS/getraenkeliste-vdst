<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\BuchungService;
use App\Models\BuchungModel;
use App\Models\FreischaltcodeModel;
use App\Models\GeraetModel;
use App\Models\PersonModel;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class TabletBuchenTest extends DbTestCase
{
    private const JETZT = '2026-10-05 12:00:00';

    private int $geraetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen(self::JETZT);

        $admin = $this->personAnlegen();
        $this->rolleGeben($admin, 'admin');
        $code  = (new FreischaltcodeModel())->erzeuge($admin, service('uhr')->jetzt());
        $token = service('geraete')->freischalten($code, 'Kühlschrank');
        $this->assertNotNull($token);
        $this->geraetId = (int) (new GeraetModel())->findeNachToken($token)['id'];
        service('superglobals')->setCookie('gl_geraet', $token);
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetCookie('gl_geraet');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $felder
     * @param array<string, mixed> $session
     */
    private function formular(string $pfad, array $felder = [], array $session = []): TestResponse
    {
        return $this->withSession(['csrf_test_name' => 'test-token', ...$session])->post($pfad, [...$this->csrf(), ...$felder]);
    }

    /**
     * @param array<string, mixed> $daten
     * @param array<string, mixed> $session
     */
    private function json(string $pfad, array $daten, array $session): TestResponse
    {
        return $this->withSession(['csrf_test_name' => 'test-token', ...$session])
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token', 'X-Requested-With' => 'XMLHttpRequest'])
            ->withBodyFormat('json')
            ->post($pfad, $daten);
    }

    /**
     * @return array<string, mixed>
     */
    private function antwort(TestResponse $antwort): array
    {
        return json_decode($antwort->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function sitzung(int $kontoId, string $seit = self::JETZT, ?string $letzter = null): array
    {
        return ['tablet_konto_id' => $kontoId, 'tablet_seit' => $seit, 'tablet_letzter_vorgang' => $letzter];
    }

    /**
     * @return array<string, mixed>
     */
    private function warenkorb(int $artikel, int $menge = 1): array
    {
        return ['vorgang_id' => BuchungService::neueVorgangId(), 'positionen' => [['artikel_id' => $artikel, 'menge' => $menge]]];
    }

    public function test_namensauswahl_zeigt_couleur_bund_und_12_zuletzt_aktive(): void
    {
        $artikel = $this->artikelAnlegen();
        $ids     = [];

        for ($i = 1; $i <= 14; $i++) {
            $ids[$i] = $this->personAnlegen(['anzeigename' => sprintf('Person %02d', $i)]);
            $this->uhrStellen(sprintf('2026-10-05 08:%02d:00', $i));
            (new BuchungService())->bucheVorgang(BuchungService::neueVorgangId(), $ids[$i], $ids[$i], null, 'web', [['artikel_id' => $artikel, 'menge' => 1]]);
        }

        $this->personAnlegen(['anzeigename' => 'Ohne Buchung']);

        $kacheln = (new PersonModel())->tabletKacheln();

        $this->assertSame(['Couleur', 'Bund'], array_column($kacheln['fest'], 'anzeigename'));
        $this->assertCount(12, $kacheln['zuletzt']);
        $this->assertSame('Person 14', $kacheln['zuletzt'][0]['anzeigename']);
        $this->assertSame('Person 03', $kacheln['zuletzt'][11]['anzeigename']);
        $this->assertNotContains('Person 01', array_column($kacheln['zuletzt'], 'anzeigename'));
        $this->assertNotContains('Ohne Buchung', array_column($kacheln['zuletzt'], 'anzeigename'));
        $this->assertContains('Ohne Buchung', array_column($kacheln['alle'], 'anzeigename'));
        $this->assertArrayHasKey('hat_pin', $kacheln['alle'][0]);

        $this->uhrStellen(self::JETZT);
        $seite = $this->get('tablet');
        $seite->assertOK();
        $seite->assertSee('Couleur');
        $seite->assertSee('Bund');
        $seite->assertSee('Person 14');
        $seite->assertSee('data-reload-s="600"');
        $seite->assertSee('js/tablet.js?v=');
        $this->assertStringNotContainsString('<style', $seite->getBody());
        $this->assertStringNotContainsString('style="', $seite->getBody());
        $this->assertStringNotContainsString(' onclick', $seite->getBody());
    }

    public function test_sammelkonten_sind_waehlbar_und_mitglied_ohne_pin_nicht(): void
    {
        $couleur = (new PersonModel())->sammelkontoId('Couleur');
        $bund    = (new PersonModel())->sammelkontoId('Bund');
        $ohnePin = $this->personAnlegen(['anzeigename' => 'Ohne Pin', 'pin' => null]);
        $mitPin  = $this->personAnlegen(['anzeigename' => 'Mit Pin']);

        $body = $this->get('tablet')->getBody();

        $this->assertStringContainsString("tablet/waehlen/{$couleur}", $body);
        $this->assertStringContainsString("tablet/waehlen/{$bund}", $body);
        $this->assertStringContainsString("tablet/waehlen/{$mitPin}", $body);
        $this->assertStringNotContainsString("tablet/waehlen/{$ohnePin}", $body);
    }

    public function test_sonstige_fehlen_in_zuletzt(): void
    {
        $artikel   = $this->artikelAnlegen();
        $sonstiger = $this->personAnlegen(['anzeigename' => 'Sonstiger', 'gruppe' => 'sonstige']);
        (new BuchungService())->bucheVorgang(BuchungService::neueVorgangId(), $sonstiger, $sonstiger, null, 'web', [['artikel_id' => $artikel, 'menge' => 1]]);

        $kacheln = (new PersonModel())->tabletKacheln();

        $this->assertSame([], $kacheln['zuletzt']);
        $this->assertContains('Sonstiger', array_column($kacheln['alle'], 'anzeigename'));
    }

    public function test_namensauswahl_leert_tablet_sitzung(): void
    {
        $person = $this->personAnlegen();

        $this->withSession($this->sitzung($person, self::JETZT, 'x'))->get('tablet')->assertOK();

        $this->assertNull(session('tablet_konto_id'));
        $this->assertNull(session('tablet_letzter_vorgang'));
    }

    public function test_person_ohne_pin_nicht_waehlbar(): void
    {
        $id = $this->personAnlegen(['anzeigename' => 'Ohne Pin', 'pin' => null]);

        $antwort = $this->formular("tablet/waehlen/{$id}");
        $antwort->assertRedirectTo(site_url('tablet'));
        $this->assertSame('Bitte zuerst am eigenen Gerät eine PIN setzen.', session()->getFlashdata('error'));
        $this->assertNull(session('tablet_konto_id'));

        $this->withSession(['csrf_test_name' => 'test-token'])->get("tablet/pin/{$id}")->assertRedirectTo(site_url('tablet'));
        $this->formular("tablet/pin/{$id}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet'));
        $this->assertNull(session('tablet_konto_id'));

        $seite = $this->get('tablet');
        $seite->assertSee('Bitte zuerst am eigenen Gerät eine PIN setzen');
        $this->assertStringNotContainsString("tablet/waehlen/{$id}", $seite->getBody());
    }

    public function test_archivierte_person_nicht_waehlbar(): void
    {
        $id = $this->personAnlegen(['anzeigename' => 'Archiviert', 'archiviert_at' => '2026-01-01 00:00:00']);

        $this->get('tablet')->assertDontSee('Archiviert');
        $this->formular("tablet/waehlen/{$id}")->assertRedirectTo(site_url('tablet'));
        $this->assertNull(session('tablet_konto_id'));

        $this->formular("tablet/pin/{$id}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet'));
        $this->assertNull(session('tablet_konto_id'));
    }

    public function test_waehlen_fuehrt_zur_pin_und_pin_seite_hat_ein_primaerbutton(): void
    {
        $id = $this->personAnlegen(['anzeigename' => 'Anna Muster']);

        $this->formular("tablet/waehlen/{$id}")->assertRedirectTo(site_url("tablet/pin/{$id}"));

        $seite = $this->get("tablet/pin/{$id}");
        $seite->assertOK();
        $seite->assertSee('Anna Muster');
        $this->assertSame(1, preg_match_all('/class="[^"]*\bbtn-vdst\b[^"]*"/', $seite->getBody()));
        $this->assertStringNotContainsString('style="', $seite->getBody());
    }

    public function test_pin_richtig_fuehrt_zum_buchen(): void
    {
        $id = $this->personAnlegen(['pin' => '123456', 'pin_fehlversuche' => 3]);

        $antwort = $this->formular("tablet/pin/{$id}", ['pin' => '123456']);

        $antwort->assertRedirectTo(site_url('tablet/buchen'));
        $this->assertSame($id, (int) session('tablet_konto_id'));
        $this->assertSame(self::JETZT, session('tablet_seit'));
        $this->assertNull(session('person_id'));
        $this->assertSame(0, (int) (new PersonModel())->find($id)['pin_fehlversuche']);
    }

    public function test_pin_falsch_zaehlt_und_zeigt_meldung(): void
    {
        $id = $this->personAnlegen();

        $antwort = $this->formular("tablet/pin/{$id}", ['pin' => '0000']);

        $antwort->assertRedirectTo(site_url("tablet/pin/{$id}"));
        $this->assertSame('PIN falsch.', session()->getFlashdata('error'));
        $this->assertSame(1, (int) (new PersonModel())->find($id)['pin_fehlversuche']);
        $this->assertNull(session('tablet_konto_id'));
    }

    public function test_pin_fuenfmal_falsch_sperrt(): void
    {
        $id = $this->personAnlegen();

        for ($i = 1; $i <= 5; $i++) {
            $this->formular("tablet/pin/{$id}", ['pin' => '0000']);
        }

        $person = (new PersonModel())->find($id);
        $this->assertSame('2026-10-05 12:05:00', $person['pin_gesperrt_bis']);
        $this->assertSame('Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.', session()->getFlashdata('error'));

        // Gesperrt: auch die richtige PIN wird abgelehnt, der Zähler bleibt.
        $this->formular("tablet/pin/{$id}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet'));
        $this->assertSame('Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.', session()->getFlashdata('error'));
        $this->assertNull(session('tablet_konto_id'));
        $this->assertSame(0, (int) (new PersonModel())->find($id)['pin_fehlversuche']);

        // Nach Ablauf der Sperre geht die richtige PIN.
        $this->uhrStellen('2026-10-05 12:05:00');
        $this->formular("tablet/pin/{$id}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet/buchen'));
        $this->assertSame($id, (int) session('tablet_konto_id'));
    }

    public function test_pin_zaehler_atomar_aus_der_db(): void
    {
        $id = $this->personAnlegen();
        db_connect()->table('personen')->where('id', $id)->update(['pin_fehlversuche' => 4]);

        $this->formular("tablet/pin/{$id}", ['pin' => '0000'])->assertRedirectTo(site_url('tablet'));

        $person = (new PersonModel())->find($id);
        $this->assertSame(0, (int) $person['pin_fehlversuche']);
        $this->assertSame('2026-10-05 12:05:00', $person['pin_gesperrt_bis']);
        $this->assertSame('Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.', session()->getFlashdata('error'));
    }

    public function test_pin_waehrend_sperre_laesst_zaehler_unveraendert(): void
    {
        $id = $this->personAnlegen(['pin_fehlversuche' => 2, 'pin_gesperrt_bis' => '2026-10-05 12:03:00']);

        $this->formular("tablet/pin/{$id}", ['pin' => '0000'])->assertRedirectTo(site_url('tablet'));

        $person = (new PersonModel())->find($id);
        $this->assertSame(2, (int) $person['pin_fehlversuche']);
        $this->assertSame('2026-10-05 12:03:00', $person['pin_gesperrt_bis']);
    }

    public function test_pin_format_wird_geprueft(): void
    {
        $id = $this->personAnlegen();

        $this->formular("tablet/pin/{$id}", ['pin' => '12'])->assertRedirectTo(site_url("tablet/pin/{$id}"));
        $this->assertNull(session('tablet_konto_id'));
    }

    public function test_couleur_ohne_pin_bebuchbar_gebucht_von_null(): void
    {
        $couleur = (new PersonModel())->sammelkontoId('Couleur');
        $artikel = $this->artikelAnlegen();

        $this->formular("tablet/waehlen/{$couleur}")->assertRedirectTo(site_url('tablet/buchen'));
        $this->assertSame($couleur, (int) session('tablet_konto_id'));

        $antwort = $this->json('tablet/buchen', $this->warenkorb($artikel, 2), $this->sitzung($couleur));

        $antwort->assertOK();
        $zeile = (new BuchungModel())->first();
        $this->assertSame($couleur, (int) $zeile['konto_id']);
        $this->assertNull($zeile['gebucht_von_id']);
        $this->assertSame('tablet', $zeile['quelle']);
        $this->assertSame($this->geraetId, (int) $zeile['geraet_id']);
    }

    public function test_tablet_buchung_speichert_geraet_und_quelle(): void
    {
        $person  = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        $daten   = $this->warenkorb($artikel);

        $antwort = $this->json('tablet/buchen', $daten, $this->sitzung($person));

        $antwort->assertOK();
        $j = $this->antwort($antwort);
        $this->assertTrue($j['ok']);
        $this->assertSame($daten['vorgang_id'], $j['vorgang_id']);
        $this->assertArrayHasKey('naechste_vorgang_id', $j);
        $this->assertArrayHasKey('csrf_hash', $j);
        $this->assertSame($daten['vorgang_id'], session('tablet_letzter_vorgang'));

        $zeile = (new BuchungModel())->first();
        $this->assertSame($person, (int) $zeile['konto_id']);
        $this->assertSame($person, (int) $zeile['gebucht_von_id']);
        $this->assertSame('tablet', $zeile['quelle']);
        $this->assertSame($this->geraetId, (int) $zeile['geraet_id']);
    }

    public function test_tablet_buchung_ignoriert_konto_aus_dem_body(): void
    {
        $person  = $this->personAnlegen();
        $fremd   = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();

        $this->json('tablet/buchen', [...$this->warenkorb($artikel), 'konto' => 'couleur', 'konto_id' => $fremd], $this->sitzung($person))->assertOK();

        $this->assertSame($person, (int) (new BuchungModel())->first()['konto_id']);
    }

    public function test_doppeltes_absenden_bucht_nur_einmal(): void
    {
        $person = $this->personAnlegen();
        $daten  = $this->warenkorb($this->artikelAnlegen());

        $this->json('tablet/buchen', $daten, $this->sitzung($person))->assertOK();
        $this->json('tablet/buchen', $daten, $this->sitzung($person))->assertOK();

        $this->assertSame(1, (new BuchungModel())->countAllResults());
    }

    public function test_rueckgaengig_nur_letzter_vorgang(): void
    {
        $person  = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        $erster  = $this->warenkorb($artikel);
        $zweiter = $this->warenkorb($artikel);
        $this->json('tablet/buchen', $erster, $this->sitzung($person))->assertOK();
        $this->json('tablet/buchen', $zweiter, $this->sitzung($person, self::JETZT, $erster['vorgang_id']))->assertOK();
        $this->assertSame($zweiter['vorgang_id'], session('tablet_letzter_vorgang'));

        // Der erste Vorgang ist nicht (mehr) der letzte.
        $abgelehnt = $this->json('tablet/rueckgaengig', ['vorgang_id' => $erster['vorgang_id']], $this->sitzung($person, self::JETZT, $zweiter['vorgang_id']));
        $abgelehnt->assertStatus(403);
        $this->assertFalse($this->antwort($abgelehnt)['ok']);
        $this->assertSame(0, (new BuchungModel())->where('storniert_at IS NOT NULL')->countAllResults());

        // Ein fremder, nicht gemerkter Vorgang ebenso.
        $fremd        = $this->personAnlegen();
        $fremdVorgang = BuchungService::neueVorgangId();
        (new BuchungService())->bucheVorgang($fremdVorgang, $fremd, $fremd, null, 'web', [['artikel_id' => $artikel, 'menge' => 1]]);
        $this->json('tablet/rueckgaengig', ['vorgang_id' => $fremdVorgang], $this->sitzung($person, self::JETZT, $zweiter['vorgang_id']))->assertStatus(403);

        $ok = $this->json('tablet/rueckgaengig', ['vorgang_id' => $zweiter['vorgang_id']], $this->sitzung($person, self::JETZT, $zweiter['vorgang_id']));
        $ok->assertOK();
        $this->assertTrue($this->antwort($ok)['ok']);
        $this->assertSame(1, (new BuchungModel())->where('storniert_at IS NOT NULL')->countAllResults());
    }

    public function test_tablet_sitzung_laeuft_nach_300s_ab(): void
    {
        $person  = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();

        $this->uhrStellen('2026-10-05 12:05:00');
        $this->json('tablet/buchen', $this->warenkorb($artikel), $this->sitzung($person, self::JETZT))->assertOK();

        $this->uhrStellen('2026-10-05 12:05:01');
        $abgelaufen = $this->json('tablet/buchen', $this->warenkorb($artikel), $this->sitzung($person, self::JETZT));

        $abgelaufen->assertStatus(401);
        $j = $this->antwort($abgelaufen);
        $this->assertFalse($j['ok']);
        $this->assertSame('Sitzung abgelaufen – bitte Namen erneut wählen.', $j['meldung']);
        $this->assertSame(1, (new BuchungModel())->countAllResults());

        $this->withSession($this->sitzung($person, self::JETZT))->get('tablet/buchen')->assertRedirectTo(site_url('tablet'));
        $this->json('tablet/rueckgaengig', ['vorgang_id' => BuchungService::neueVorgangId()], $this->sitzung($person, self::JETZT))->assertStatus(401);
    }

    public function test_buchen_ohne_tablet_sitzung_abgewiesen(): void
    {
        $this->json('tablet/buchen', $this->warenkorb($this->artikelAnlegen()), [])->assertStatus(401);
        $this->withSession(['csrf_test_name' => 'test-token'])->get('tablet/buchen')->assertRedirectTo(site_url('tablet'));
        $this->assertSame(0, (new BuchungModel())->countAllResults());
    }

    public function test_buchenseite_im_tablet_modus(): void
    {
        $person = $this->personAnlegen(['anzeigename' => 'Anna Muster']);
        $this->artikelAnlegen(['name' => 'Helles']);

        $seite = $this->withSession($this->sitzung($person))->get('tablet/buchen');

        $seite->assertOK();
        $seite->assertSee('Anna Muster');
        $seite->assertSee('Helles');
        $seite->assertSee('Abbrechen');
        $seite->assertSee('data-buchen-url');
        $seite->assertSee('data-rueckgaengig-url');
        $seite->assertSee('data-fertig-url');
        $seite->assertSee('data-timeout-s="30"');
        $seite->assertSee('js/buchen.js?v=');
        $seite->assertDontSee('value="couleur"');
        $this->assertSame(1, preg_match_all('/class="[^"]*\bbtn-vdst\b[^"]*"/', $seite->getBody()));
        $this->assertStringNotContainsString('style="', $seite->getBody());
    }

    public function test_fertig_leert_tablet_sitzung(): void
    {
        $person = $this->personAnlegen();

        $this->formular('tablet/fertig', [], $this->sitzung($person, self::JETZT, 'abc'))->assertRedirectTo(site_url('tablet'));

        $this->assertNull(session('tablet_konto_id'));
        $this->assertNull(session('tablet_letzter_vorgang'));
    }

    public function test_pin_post_ohne_session_leitet_mit_hinweis_um(): void
    {
        $id = $this->personAnlegen();

        // Leere Session (Tablet über Nacht): kein gültiger CSRF-Token.
        $antwort = $this->post("tablet/pin/{$id}", ['pin' => '1234']);

        $antwort->assertRedirectTo(site_url('tablet'));
        $this->assertSame('Die Sitzung war abgelaufen. Bitte den Namen erneut wählen.', session()->getFlashdata('error'));
        $this->assertNull(session('tablet_konto_id'));
        $this->assertSame(0, (int) (new PersonModel())->find($id)['pin_fehlversuche']);
        $this->assertNotNull($antwort->response()->getCookie('gl_geraet'));

        $this->withSession(['csrf_test_name' => 'test-token'])->post("tablet/waehlen/{$id}")->assertRedirectTo(site_url('tablet'));
        $this->withSession(['csrf_test_name' => 'test-token'])->post('tablet/fertig')->assertRedirectTo(site_url('tablet'));
    }

    public function test_tablet_json_ohne_csrf_wird_mit_403_abgewiesen(): void
    {
        $person = $this->personAnlegen();

        try {
            $this->withSession(['csrf_test_name' => 'test-token', ...$this->sitzung($person)])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->withBodyFormat('json')
                ->post('tablet/buchen', $this->warenkorb($this->artikelAnlegen()));
            $this->fail('CSRF-Prüfung hat nicht abgewiesen.');
        } catch (SecurityException $e) {
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame(0, (new BuchungModel())->countAllResults());
    }

    public function test_ohne_geraet_kein_zugriff(): void
    {
        service('superglobals')->unsetCookie('gl_geraet');
        $person  = $this->personAnlegen();
        $sitzung = $this->sitzung($person);

        $this->get('tablet')->assertRedirectTo(site_url('tablet/freischalten'));
        $this->withSession($sitzung)->get('tablet/buchen')->assertRedirectTo(site_url('tablet/freischalten'));
        $this->formular("tablet/waehlen/{$person}")->assertRedirectTo(site_url('tablet/freischalten'));
        $this->formular("tablet/pin/{$person}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet/freischalten'));
        $this->json('tablet/buchen', $this->warenkorb($this->artikelAnlegen()), $sitzung)->assertRedirectTo(site_url('tablet/freischalten'));
        $this->json('tablet/rueckgaengig', ['vorgang_id' => BuchungService::neueVorgangId()], $sitzung)->assertRedirectTo(site_url('tablet/freischalten'));
        $this->formular('tablet/fertig')->assertRedirectTo(site_url('tablet/freischalten'));
        $this->assertSame(0, (new BuchungModel())->countAllResults());
    }

    public function test_gesperrtes_geraet_darf_nicht_buchen(): void
    {
        (new GeraetModel())->update($this->geraetId, ['gesperrt_at' => '2026-10-05 11:00:00']);
        $person = $this->personAnlegen();

        $this->json('tablet/buchen', $this->warenkorb($this->artikelAnlegen()), $this->sitzung($person))->assertStatus(403);
        $this->assertSame(0, (new BuchungModel())->countAllResults());
    }

    public function test_tablet_sitzung_ist_getrennt_vom_persoenlichen_login(): void
    {
        $person = $this->personAnlegen();

        $this->formular("tablet/pin/{$person}", ['pin' => '1234'])->assertRedirectTo(site_url('tablet/buchen'));

        $this->assertNull(session('person_id'));
        // Mit Tablet-Sitzung ist die eigene Buchungsseite trotzdem nicht erreichbar.
        $this->withSession($this->sitzung($person))->get('buchen')->assertRedirectTo(site_url('tablet'));
    }
}
