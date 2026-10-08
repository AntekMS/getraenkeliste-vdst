<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnmeldeTokenModel;
use App\Models\FreischaltcodeModel;
use App\Models\GeraetModel;
use App\Models\ProtokollModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class TabletFreischaltungTest extends DbTestCase
{
    private const JETZT = '2026-10-05 12:00:00';

    protected function tearDown(): void
    {
        service('superglobals')->unsetCookie('gl_geraet');
        parent::tearDown();
    }

    private function adminAnlegen(): int
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, 'admin');

        return $id;
    }

    private function codeErzeugen(?int $adminId = null): string
    {
        $this->uhrStellen(self::JETZT);

        return (new FreischaltcodeModel())->erzeuge($adminId ?? $this->adminAnlegen(), service('uhr')->jetzt());
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} Token und Geraet
     */
    private function geraetAnlegen(string $name = 'Kühlschrank'): array
    {
        $token = service('geraete')->freischalten($this->codeErzeugen(), $name);
        $this->assertNotNull($token);

        return [$token, (new GeraetModel())->findeNachToken($token)];
    }

    private function cookieSetzen(string $token): void
    {
        service('superglobals')->setCookie('gl_geraet', $token);
    }

    public function test_code_hat_acht_ziffern_und_wird_nur_als_hash_gespeichert(): void
    {
        $code = $this->codeErzeugen();

        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);

        $zeile = (new FreischaltcodeModel())->first();
        $this->assertSame(hash('sha256', $code), $zeile['code_hash']);
        $this->assertSame('2026-10-05 12:15:00', $zeile['gueltig_bis']);
        $this->assertStringNotContainsString($code, json_encode($zeile));
    }

    public function test_code_wird_nur_als_hash_gespeichert(): void
    {
        $admin = $this->adminAnlegen();
        $this->uhrStellen(self::JETZT);

        $antwort = $this->alsAngemeldet($admin)->post('admin/tablets/code', $this->csrf());

        $antwort->assertRedirectTo(site_url('admin/tablets'));
        $code = session()->getFlashdata('freischaltcode')['code'];
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);

        $zeile = (new FreischaltcodeModel())->first();
        $this->assertSame(hash('sha256', $code), $zeile['code_hash']);

        $protokoll = json_encode((new ProtokollModel())->findAll());
        $this->assertStringContainsString('freischaltcode_erzeugt', $protokoll);
        $this->assertStringNotContainsString($code, $protokoll);
    }

    public function test_code_einmalig(): void
    {
        $code = $this->codeErzeugen();

        $erst = service('geraete')->freischalten($code, 'Eins');
        $zweit = service('geraete')->freischalten($code, 'Zwei');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $erst);
        $this->assertNull($zweit);
        $this->assertSame(1, (new GeraetModel())->countAllResults());
    }

    public function test_code_mit_leerzeichen_wird_akzeptiert(): void
    {
        $code = $this->codeErzeugen();

        $this->assertNotNull(service('geraete')->freischalten(substr($code, 0, 4) . ' ' . substr($code, 4), 'Tablet'));
    }

    public function test_code_nach_15_minuten_ungueltig(): void
    {
        $code = $this->codeErzeugen();

        $this->uhrStellen('2026-10-05 12:15:01');
        $this->assertNull(service('geraete')->freischalten($code, 'Spät'));

        $this->uhrStellen('2026-10-05 12:15:00');
        $this->assertNotNull(service('geraete')->freischalten($code, 'Gerade noch'));
    }

    public function test_falscher_code_zeigt_einheitliche_meldung(): void
    {
        $this->codeErzeugen();

        $antwort = $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('tablet/freischalten', [...$this->csrf(), 'code' => '00000000', 'name' => 'Kühlschrank']);

        $antwort->assertRedirectTo(site_url('tablet/freischalten'));
        $this->assertSame('Code ungültig oder abgelaufen.', session()->getFlashdata('error'));
        $antwort->assertCookieMissing('gl_geraet');
    }

    public function test_freischaltung_ohne_name_verbraucht_den_code_nicht(): void
    {
        $code = $this->codeErzeugen();

        $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('tablet/freischalten', [...$this->csrf(), 'code' => $code, 'name' => '  '])
            ->assertRedirectTo(site_url('tablet/freischalten'));

        $this->assertNull((new FreischaltcodeModel())->first()['eingeloest_at']);
    }

    public function test_freischaltung_setzt_geraete_cookie(): void
    {
        $code = $this->codeErzeugen();

        $antwort = $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('tablet/freischalten', [...$this->csrf(), 'code' => $code, 'name' => 'Kühlschrank']);

        $antwort->assertRedirectTo(site_url('tablet'));
        $antwort->assertCookie('gl_geraet');

        $cookie = $antwort->response()->getCookie('gl_geraet');
        $this->assertTrue($cookie->isHTTPOnly());
        $this->assertSame('Lax', $cookie->getSameSite());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cookie->getValue());
        $this->assertGreaterThan(time() + 399 * 86400, $cookie->getExpiresTimestamp());

        $geraet = (new GeraetModel())->first();
        $this->assertSame('Kühlschrank', $geraet['name']);
        $this->assertSame(hash('sha256', $cookie->getValue()), $geraet['token_hash']);
        $this->assertNotNull((new FreischaltcodeModel())->first()['eingeloest_at']);
    }

    public function test_geraete_cookie_wird_bei_jedem_request_erneuert(): void
    {
        [$token] = $this->geraetAnlegen();
        $this->cookieSetzen($token);

        foreach ([1, 2] as $_) {
            $antwort = $this->get('tablet');

            $antwort->assertOK();
            $antwort->assertSee('Wer bist du?');
            $cookie = $antwort->response()->getCookie('gl_geraet');
            $this->assertSame($token, $cookie->getValue());
            $this->assertGreaterThan(time() + 399 * 86400, $cookie->getExpiresTimestamp());
        }
    }

    public function test_geraete_cookie_ueberlebt_den_redirect(): void
    {
        [$token] = $this->geraetAnlegen();
        $this->cookieSetzen($token);

        $antwort = $this->get('tablet/freischalten');

        $antwort->assertRedirectTo(site_url('tablet'));
        $this->assertSame($token, $antwort->response()->getCookie('gl_geraet')->getValue());
    }

    public function test_zuletzt_gesehen_wird_aktualisiert(): void
    {
        [$token, $geraet] = $this->geraetAnlegen();
        $this->cookieSetzen($token);
        $this->uhrStellen('2026-10-06 08:30:00');

        $this->get('tablet')->assertOK();

        $this->assertSame('2026-10-06 08:30:00', (new GeraetModel())->find($geraet['id'])['zuletzt_gesehen_at']);
    }

    public function test_tablet_ohne_cookie_geht_zur_freischaltung(): void
    {
        $this->get('tablet')->assertRedirectTo(site_url('tablet/freischalten'));
        $this->get('tablet/freischalten')->assertOK();
    }

    public function test_unbekanntes_geraete_cookie_geht_zur_freischaltung(): void
    {
        $this->cookieSetzen(str_repeat('a', 64));

        $this->get('tablet')->assertRedirectTo(site_url('tablet/freischalten'));
    }

    public function test_gesperrtes_tablet_abgewiesen(): void
    {
        [$token, $geraet] = $this->geraetAnlegen();
        (new GeraetModel())->update($geraet['id'], ['gesperrt_at' => '2026-10-05 12:05:00']);
        $this->cookieSetzen($token);

        $antwort = $this->get('tablet');

        $antwort->assertStatus(403);
        $antwort->assertSee('Dieses Tablet wurde gesperrt.');
    }

    public function test_gesperrtes_geraet_sieht_auf_allen_routen_die_sperrseite(): void
    {
        [$token, $geraet] = $this->geraetAnlegen();
        (new GeraetModel())->update($geraet['id'], ['gesperrt_at' => '2026-10-05 12:05:00']);
        $this->cookieSetzen($token);

        foreach (['login', 'buchen', 'meine-buchungen', 'konto', 'admin/personen'] as $pfad) {
            $antwort = $this->get($pfad);
            $antwort->assertStatus(403);
            $antwort->assertSee('Dieses Tablet wurde gesperrt.');
        }
    }

    public function test_gesperrtes_geraet_erreicht_logout_nicht(): void
    {
        [$token, $geraet] = $this->geraetAnlegen();
        (new GeraetModel())->update($geraet['id'], ['gesperrt_at' => '2026-10-05 12:05:00']);
        $this->cookieSetzen($token);

        $this->withSession(['csrf_test_name' => 'test-token'])->post('logout', $this->csrf())->assertStatus(403);
    }

    public function test_unbekanntes_geraete_cookie_zaehlt_als_kein_geraet(): void
    {
        $this->cookieSetzen(str_repeat('b', 64));

        $this->get('login')->assertOK();
        $this->get('buchen')->assertRedirectTo(site_url('login'));
    }

    public function test_freischalten_beendet_persoenlichen_login_und_remember_token(): void
    {
        $person = $this->personAnlegen();
        $this->rolleGeben($person, 'admin');
        $this->uhrStellen(self::JETZT);
        $merk = (new AnmeldeTokenModel())->erzeuge($person, service('uhr')->jetzt());
        service('superglobals')->setCookie('gl_merken', $merk);
        $code = $this->codeErzeugen();

        $antwort = $this->alsAngemeldet($person)
            ->post('tablet/freischalten', [...$this->csrf(), 'code' => $code, 'name' => 'Kühlschrank']);

        $antwort->assertRedirectTo(site_url('tablet'));
        $this->assertSame('', $antwort->response()->getCookie('gl_merken')->getValue());
        $this->assertNull(session('person_id'));
        $this->assertSame(0, (new AnmeldeTokenModel())->where('person_id', $person)->countAllResults());

        $token = $antwort->response()->getCookie('gl_geraet')->getValue();
        (new GeraetModel())->where('token_hash', hash('sha256', $token))->set(['gesperrt_at' => '2026-10-05 12:30:00'])->update();
        $this->cookieSetzen($token);

        // Das Browser-Cookie gl_merken ist ohne DB-Token wertlos; es wird hier bewusst weiter mitgesendet.
        // withSession ersetzt die Test-Session (sonst bliebe die Person der Test-Hilfe angemeldet).
        $this->withSession(['csrf_test_name' => 'test-token'])->get('admin/personen')->assertStatus(403);
        $this->get('buchen')->assertStatus(403);

        service('superglobals')->unsetCookie('gl_merken');
    }

    public function test_tablet_erreicht_keine_anderen_routen(): void
    {
        $admin = $this->adminAnlegen();
        [$token] = $this->geraetAnlegen();
        $this->cookieSetzen($token);

        $this->get('login')->assertRedirectTo(site_url('tablet'));
        $this->get('meine-buchungen')->assertRedirectTo(site_url('tablet'));
        $this->get('admin/personen')->assertRedirectTo(site_url('tablet'));
        $this->alsAngemeldet($admin)->get('admin/personen')->assertRedirectTo(site_url('tablet'));
        $this->get('tablet/freischalten')->assertRedirectTo(site_url('tablet'));
        $this->alsAngemeldet($admin)->get('konto')->assertRedirectTo(site_url('tablet'));
        $this->alsAngemeldet($admin)->get('konto/einrichten')->assertRedirectTo(site_url('tablet'));
        $this->alsAngemeldet($admin)->get('buchen')->assertRedirectTo(site_url('tablet'));
    }

    public function test_tablet_erreicht_logout_nicht(): void
    {
        $admin = $this->adminAnlegen();
        [$token] = $this->geraetAnlegen();
        $this->cookieSetzen($token);

        $this->alsAngemeldet($admin)->post('logout', $this->csrf())->assertRedirectTo(site_url('tablet'));
    }

    public function test_tablet_erreicht_admin_post_nicht(): void
    {
        $admin = $this->adminAnlegen();
        [$token] = $this->geraetAnlegen();
        $this->cookieSetzen($token);

        $this->alsAngemeldet($admin)->post('admin/tablets/code', $this->csrf())->assertRedirectTo(site_url('tablet'));
        $this->assertSame(1, (new FreischaltcodeModel())->countAllResults());
    }

    public function test_nur_admin_erzeugt_codes(): void
    {
        $mitglied = $this->personAnlegen();

        $this->alsAngemeldet($mitglied)->get('admin/tablets')->assertStatus(403);
        $this->alsAngemeldet($mitglied)->post('admin/tablets/code', $this->csrf())->assertStatus(403);
        $this->assertSame(0, (new FreischaltcodeModel())->countAllResults());
    }

    public function test_admin_liste_zeigt_geraete_und_code(): void
    {
        $admin = $this->adminAnlegen();
        $this->geraetAnlegen('Kühlschrank');
        $liste = $this->alsAngemeldet($admin)->get('admin/tablets');

        $liste->assertOK();
        $liste->assertSee('Kühlschrank');
        $liste->assertSee('aktiv');
        $liste->assertSee('Freischaltcode erzeugen');
    }

    public function test_umbenennen_und_sperren_werden_protokolliert(): void
    {
        $admin = $this->adminAnlegen();
        [, $geraet] = $this->geraetAnlegen('Alt');
        $id = (int) $geraet['id'];

        $this->alsAngemeldet($admin)->post("admin/tablets/{$id}/umbenennen", [...$this->csrf(), 'name' => 'Neu'])
            ->assertRedirectTo(site_url('admin/tablets'));
        $this->assertSame('Neu', (new GeraetModel())->find($id)['name']);

        $this->uhrStellen('2026-10-05 13:00:00');
        $this->alsAngemeldet($admin)->post("admin/tablets/{$id}/sperren", $this->csrf())
            ->assertRedirectTo(site_url('admin/tablets'));
        $this->assertSame('2026-10-05 13:00:00', (new GeraetModel())->find($id)['gesperrt_at']);

        $aktionen = array_column((new ProtokollModel())->where('tabelle', 'geraete')->findAll(), 'aktion');
        $this->assertSame(['umbenannt', 'gesperrt'], $aktionen);
    }

    public function test_umbenennen_verlangt_namen(): void
    {
        $admin = $this->adminAnlegen();
        [, $geraet] = $this->geraetAnlegen('Alt');

        $this->alsAngemeldet($admin)->post("admin/tablets/{$geraet['id']}/umbenennen", [...$this->csrf(), 'name' => ' '])
            ->assertRedirectTo(site_url('admin/tablets'));

        $this->assertSame('Alt', (new GeraetModel())->find($geraet['id'])['name']);
    }
}
