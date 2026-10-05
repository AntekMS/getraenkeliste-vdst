<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class LoginTest extends DbTestCase
{
    /**
     * @param array<string, string> $felder
     */
    private function loginPost(array $felder): \CodeIgniter\Test\TestResponse
    {
        return $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('login', [...$this->csrf(), ...$felder]);
    }

    public function test_login_erfolgreich_leitet_weiter_und_setzt_session(): void
    {
        $id = $this->personAnlegen(['benutzername' => 'anna', 'passwort' => 'geheim123']);

        $antwort = $this->loginPost(['benutzername' => 'Anna ', 'passwort' => 'geheim123']);

        $antwort->assertRedirectTo(site_url('buchen'));
        $this->assertSame($id, (int) session('person_id'));
    }

    public function test_falsches_passwort_zeigt_meldung(): void
    {
        $this->personAnlegen(['benutzername' => 'anna']);

        $antwort = $this->loginPost(['benutzername' => 'anna', 'passwort' => 'falsch']);

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertSame('Benutzername oder Passwort falsch.', session()->getFlashdata('error'));
        $this->assertNull(session('person_id'));
    }

    public function test_unbekannter_benutzer_zeigt_dieselbe_meldung(): void
    {
        $antwort = $this->loginPost(['benutzername' => 'niemand', 'passwort' => 'egal12345']);

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertSame('Benutzername oder Passwort falsch.', session()->getFlashdata('error'));
    }

    public function test_fuenf_fehlversuche_sperren_auch_richtiges_passwort(): void
    {
        $this->uhrStellen('2026-10-05 12:00:00');
        $this->personAnlegen(['benutzername' => 'anna', 'passwort' => 'geheim123']);

        for ($i = 0; $i < 5; $i++) {
            $this->loginPost(['benutzername' => 'anna', 'passwort' => 'falsch']);
        }

        $this->loginPost(['benutzername' => 'anna', 'passwort' => 'geheim123']);
        $this->assertNull(session('person_id'));
        $this->assertSame('Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.', session()->getFlashdata('error'));

        $this->uhrStellen('2026-10-05 12:05:01');
        $antwort = $this->loginPost(['benutzername' => 'anna', 'passwort' => 'geheim123']);

        $antwort->assertRedirectTo(site_url('buchen'));
        $this->assertNotNull(session('person_id'));
    }

    public function test_archivierte_person_kann_sich_nicht_anmelden(): void
    {
        $this->personAnlegen(['benutzername' => 'anna', 'archiviert_at' => '2026-01-01 00:00:00']);

        $antwort = $this->loginPost(['benutzername' => 'anna', 'passwort' => 'geheim123']);

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
    }

    public function test_archivierte_person_mit_offener_session_wird_abgemeldet(): void
    {
        $id = $this->personAnlegen();
        (new PersonModel())->update($id, ['archiviert_at' => '2026-10-05 10:00:00']);

        $antwort = $this->alsAngemeldet($id)->get('buchen');

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
    }

    public function test_session_id_wechselt_beim_login(): void
    {
        $this->personAnlegen(['benutzername' => 'anna']);
        $this->loginPost(['benutzername' => 'anna', 'passwort' => 'geheim123']);

        // MockSession tauscht die ID nicht wirklich, merkt sich aber den regenerate()-Aufruf
        $this->assertTrue(session()->didRegenerate);
    }

    public function test_logout_nur_per_post(): void
    {
        $id = $this->personAnlegen();

        try {
            $this->alsAngemeldet($id)->get('logout');
            $this->fail('GET logout hätte 404 liefern müssen.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $antwort = $this->alsAngemeldet($id)->post('logout', $this->csrf());
        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
    }

    public function test_post_ohne_csrf_token_wird_abgewiesen(): void
    {
        $this->personAnlegen(['benutzername' => 'anna']);

        $antwort = $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('login', ['benutzername' => 'anna', 'passwort' => 'geheim123']);

        $this->assertTrue($antwort->isRedirect());
        $this->assertNull(session('person_id'));
    }

    public function test_geschuetzte_route_ohne_login_leitet_zu_login(): void
    {
        $this->get('buchen')->assertRedirectTo(site_url('login'));
    }

    public function test_angemeldete_person_sieht_buchen_mit_layout(): void
    {
        $id = $this->personAnlegen(['anzeigename' => 'Anna Muster']);

        $antwort = $this->alsAngemeldet($id)->get('buchen');

        $antwort->assertOK();
        $antwort->assertSee('Buchen');
        $antwort->assertSee('Anna Muster');
        $antwort->assertDontSee('Verwaltung');
    }

    public function test_admin_sieht_verwaltung_in_der_navigation(): void
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, 'admin');

        $this->alsAngemeldet($id)->get('buchen')->assertSee('Verwaltung');
    }

    public function test_wurzel_leitet_zu_buchen(): void
    {
        $this->get('/')->assertRedirectTo(site_url('buchen'));
    }

    public function test_login_seite_ist_ohne_anmeldung_erreichbar(): void
    {
        $antwort = $this->get('login');

        $antwort->assertOK();
        $antwort->assertSee('Anmelden');
    }
}
