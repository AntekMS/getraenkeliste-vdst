<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class MerkTokenTest extends DbTestCase
{
    protected function tearDown(): void
    {
        service('superglobals')->unsetCookie('gl_merken');
        parent::tearDown();
    }

    private function cookieSetzen(string $wert): void
    {
        service('superglobals')->setCookie('gl_merken', $wert);
    }

    private function cookieErzeugen(int $personId, string $jetzt = '2026-10-05 12:00:00'): string
    {
        $this->uhrStellen($jetzt);

        return (new AnmeldeTokenModel())->erzeuge($personId, service('uhr')->jetzt());
    }

    private function tokenAnzahl(int $personId): int
    {
        return count((new AnmeldeTokenModel())->where('person_id', $personId)->findAll());
    }

    public function test_login_mit_merken_setzt_cookie(): void
    {
        $id = $this->personAnlegen(['benutzername' => 'anna']);

        $antwort = $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('login', [...$this->csrf(), 'benutzername' => 'anna', 'passwort' => 'geheim123', 'merken' => '1']);

        $antwort->assertRedirectTo(site_url('buchen'));
        $antwort->assertCookie('gl_merken');
        $this->assertSame(1, $this->tokenAnzahl($id));

        $cookie = $antwort->response()->getCookie('gl_merken');
        $this->assertTrue($cookie->isHTTPOnly());
        $this->assertSame('Lax', $cookie->getSameSite());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $cookie->getValue());

        $token = (new AnmeldeTokenModel())->where('person_id', $id)->first();
        $this->assertStringNotContainsString($token['token_hash'], $cookie->getValue());
    }

    public function test_login_ohne_merken_setzt_kein_cookie(): void
    {
        $id = $this->personAnlegen(['benutzername' => 'anna']);

        $antwort = $this->withSession(['csrf_test_name' => 'test-token'])
            ->post('login', [...$this->csrf(), 'benutzername' => 'anna', 'passwort' => 'geheim123']);

        $antwort->assertRedirectTo(site_url('buchen'));
        $antwort->assertCookieMissing('gl_merken');
        $this->assertSame(0, $this->tokenAnzahl($id));
    }

    public function test_cookie_meldet_ohne_session_an_und_rotiert(): void
    {
        $id     = $this->personAnlegen();
        $alt    = $this->cookieErzeugen($id);
        $this->cookieSetzen($alt);

        $antwort = $this->get('buchen');

        $antwort->assertOK();
        $this->assertSame($id, (int) session('person_id'));
        $antwort->assertCookie('gl_merken');
        $neu = $antwort->response()->getCookie('gl_merken')->getValue();
        $this->assertNotSame($alt, $neu);
        $this->assertSame(1, $this->tokenAnzahl($id));

        // alter Cookie ist verbraucht
        $this->resetServices();
        $this->uhrStellen('2026-10-05 12:00:00');
        session()->remove('person_id');
        $this->cookieSetzen($alt);
        $this->get('buchen')->assertRedirectTo(site_url('login'));

        // der neue funktioniert (wurde durch den Diebstahl-Zweig aber nicht berührt: anderer Selector)
        $this->assertSame(1, $this->tokenAnzahl($id));
    }

    public function test_gestohlener_validator_loescht_alle_tokens(): void
    {
        $id   = $this->personAnlegen();
        $echt = $this->cookieErzeugen($id);
        $this->cookieErzeugen($id);
        $this->assertSame(2, $this->tokenAnzahl($id));

        [$selector] = explode(':', $echt);
        $this->cookieSetzen($selector . ':' . str_repeat('a', 64));

        $antwort = $this->get('buchen');

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
        $this->assertSame(0, $this->tokenAnzahl($id));
    }

    public function test_archivierte_person_wird_trotz_cookie_nicht_angemeldet(): void
    {
        $id     = $this->personAnlegen();
        $cookie = $this->cookieErzeugen($id);
        (new PersonModel())->update($id, ['archiviert_at' => '2026-10-05 13:00:00']);
        $this->cookieSetzen($cookie);

        $antwort = $this->get('buchen');

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
        $this->assertSame(0, $this->tokenAnzahl($id));
    }

    public function test_passwortwechsel_macht_cookie_ungueltig(): void
    {
        $id     = $this->personAnlegen();
        $cookie = $this->cookieErzeugen($id);

        $antwort = $this->alsAngemeldet($id)->post('konto/passwort', [
            ...$this->csrf(),
            'passwort_aktuell'     => 'geheim123',
            'passwort_neu'         => 'ganzNeu12345',
            'passwort_wiederholen' => 'ganzNeu12345',
        ]);

        $antwort->assertRedirectTo(site_url('konto'));
        $this->assertSame(0, $this->tokenAnzahl($id));
        $this->assertSame('', $antwort->response()->getCookie('gl_merken')->getValue());

        $this->withSession([]);
        session()->remove('person_id');
        $this->cookieSetzen($cookie);
        $this->get('buchen')->assertRedirectTo(site_url('login'));
    }

    public function test_abgelaufenes_token_wird_ignoriert(): void
    {
        $id     = $this->personAnlegen();
        $cookie = $this->cookieErzeugen($id, '2026-10-05 12:00:00');
        $this->uhrStellen('2027-01-04 12:00:01'); // 91 Tage später
        $this->cookieSetzen($cookie);

        $antwort = $this->get('buchen');

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertNull(session('person_id'));
        $this->assertSame(0, $this->tokenAnzahl($id));
    }

    public function test_logout_loescht_nur_das_eigene_token(): void
    {
        $id     = $this->personAnlegen();
        $cookie = $this->cookieErzeugen($id);
        $this->cookieErzeugen($id);
        $this->cookieSetzen($cookie);

        $antwort = $this->alsAngemeldet($id)->post('logout', $this->csrf());

        $antwort->assertRedirectTo(site_url('login'));
        $this->assertSame(1, $this->tokenAnzahl($id));
        $this->assertSame('', $antwort->response()->getCookie('gl_merken')->getValue());
    }
}
