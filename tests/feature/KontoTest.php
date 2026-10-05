<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class KontoTest extends DbTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function person(int $id): array
    {
        return (new PersonModel())->find($id);
    }

    private function tokenAnlegen(int $personId): void
    {
        (new AnmeldeTokenModel())->insert([
            'person_id' => $personId, 'selector' => 'abc', 'token_hash' => 'x', 'gueltig_bis' => '2030-01-01 00:00:00',
        ]);
    }

    private function tokenAnzahl(int $personId): int
    {
        return count((new AnmeldeTokenModel())->where('person_id', $personId)->findAll());
    }

    public function test_einmalpasswort_erzwingt_passwort_und_pin_vor_allem_anderen(): void
    {
        $id = $this->personAnlegen(['passwort_wechsel_erzwingen' => 1, 'pin' => null]);

        $this->alsAngemeldet($id)->get('buchen')->assertRedirectTo(site_url('konto/einrichten'));
        $this->alsAngemeldet($id)->get('konto')->assertRedirectTo(site_url('konto/einrichten'));

        $seite = $this->alsAngemeldet($id)->get('konto/einrichten');
        $seite->assertOK();
        $seite->assertSee('passwort_neu');
        $seite->assertSee('pin_wiederholen');
    }

    public function test_nach_pin_reset_wird_nur_pin_verlangt(): void
    {
        $id = $this->personAnlegen(['pin' => null]);

        $this->alsAngemeldet($id)->get('buchen')->assertRedirectTo(site_url('konto/einrichten'));

        $seite = $this->alsAngemeldet($id)->get('konto/einrichten');
        $seite->assertSee('pin_wiederholen');
        $seite->assertDontSee('passwort_neu');
    }

    public function test_einrichten_mit_ungleichen_pins_scheitert(): void
    {
        $id = $this->personAnlegen(['pin' => null]);

        $antwort = $this->alsAngemeldet($id)->post('konto/einrichten', [
            ...$this->csrf(), 'pin' => '1234', 'pin_wiederholen' => '4321',
        ]);

        $antwort->assertRedirectTo(site_url('konto/einrichten'));
        $this->assertSame('Die PINs stimmen nicht überein.', session()->getFlashdata('error'));
        $this->assertNull($this->person($id)['pin_hash']);
    }

    public function test_nach_einrichtung_ist_buchen_erreichbar(): void
    {
        $id = $this->personAnlegen(['passwort_wechsel_erzwingen' => 1, 'pin' => null]);
        $this->tokenAnlegen($id);

        $antwort = $this->alsAngemeldet($id)->post('konto/einrichten', [
            ...$this->csrf(),
            'passwort_neu' => 'neuesPasswort1', 'passwort_wiederholen' => 'neuesPasswort1',
            'pin' => '4711', 'pin_wiederholen' => '4711',
        ]);

        $antwort->assertRedirectTo(site_url('buchen'));
        $this->assertSame('Alles eingerichtet.', session()->getFlashdata('success'));

        $person = $this->person($id);
        $this->assertSame(0, (int) $person['passwort_wechsel_erzwingen']);
        $this->assertTrue(password_verify('neuesPasswort1', $person['passwort_hash']));
        $this->assertTrue(password_verify('4711', $person['pin_hash']));
        $this->assertSame(0, $this->tokenAnzahl($id));
        $this->assertTrue(session()->didRegenerate);

        $this->alsAngemeldet($id)->get('buchen')->assertOK();
    }

    public function test_einrichten_nur_pin_laesst_passwort_und_tokens_unberuehrt(): void
    {
        $id  = $this->personAnlegen(['pin' => null]);
        $alt = $this->person($id)['passwort_hash'];
        $this->tokenAnlegen($id);

        $this->alsAngemeldet($id)->post('konto/einrichten', [
            ...$this->csrf(), 'pin' => '4711', 'pin_wiederholen' => '4711',
        ])->assertRedirectTo(site_url('buchen'));

        $this->assertSame($alt, $this->person($id)['passwort_hash']);
        $this->assertSame(1, $this->tokenAnzahl($id));
    }

    public function test_logout_bleibt_waehrend_pflichtseite_moeglich(): void
    {
        $id = $this->personAnlegen(['pin' => null]);

        $this->alsAngemeldet($id)->post('logout', $this->csrf())->assertRedirectTo(site_url('login'));
    }

    public function test_passwort_aendern_braucht_aktuelles_passwort(): void
    {
        $id  = $this->personAnlegen();
        $alt = $this->person($id)['passwort_hash'];

        $antwort = $this->alsAngemeldet($id)->post('konto/passwort', [
            ...$this->csrf(), 'passwort_aktuell' => 'falsch',
            'passwort_neu' => 'neuesPasswort1', 'passwort_wiederholen' => 'neuesPasswort1',
        ]);

        $antwort->assertRedirectTo(site_url('konto'));
        $this->assertSame('Das aktuelle Passwort ist falsch.', session()->getFlashdata('error'));
        $this->assertSame($alt, $this->person($id)['passwort_hash']);

        $this->tokenAnlegen($id);
        $this->alsAngemeldet($id)->post('konto/passwort', [
            ...$this->csrf(), 'passwort_aktuell' => 'geheim123',
            'passwort_neu' => 'neuesPasswort1', 'passwort_wiederholen' => 'neuesPasswort1',
        ])->assertRedirectTo(site_url('konto'));

        $this->assertTrue(password_verify('neuesPasswort1', $this->person($id)['passwort_hash']));
        $this->assertSame(0, $this->tokenAnzahl($id));
    }

    public function test_passwort_aendern_mit_ungleichen_passwoertern_scheitert(): void
    {
        $id = $this->personAnlegen();

        $this->alsAngemeldet($id)->post('konto/passwort', [
            ...$this->csrf(), 'passwort_aktuell' => 'geheim123',
            'passwort_neu' => 'neuesPasswort1', 'passwort_wiederholen' => 'anderes12345',
        ]);

        $this->assertSame('Die Passwörter stimmen nicht überein.', session()->getFlashdata('error'));
        $this->assertTrue(password_verify('geheim123', $this->person($id)['passwort_hash']));
    }

    public function test_pin_aendern_speichert_nur_hash(): void
    {
        $id = $this->personAnlegen();
        (new PersonModel())->update($id, ['pin_fehlversuche' => 3, 'pin_gesperrt_bis' => '2030-01-01 00:00:00']);

        $this->alsAngemeldet($id)->post('konto/pin', [
            ...$this->csrf(), 'passwort_aktuell' => 'geheim123', 'pin' => '987654', 'pin_wiederholen' => '987654',
        ])->assertRedirectTo(site_url('konto'));

        $person = $this->person($id);
        $this->assertNotSame('987654', $person['pin_hash']);
        $this->assertTrue(password_verify('987654', $person['pin_hash']));
        $this->assertSame(0, (int) $person['pin_fehlversuche']);
        $this->assertNull($person['pin_gesperrt_bis']);
    }

    public function test_pin_aendern_mit_falschem_passwort_oder_ungueltiger_pin_scheitert(): void
    {
        $id  = $this->personAnlegen();
        $alt = $this->person($id)['pin_hash'];

        $this->alsAngemeldet($id)->post('konto/pin', [
            ...$this->csrf(), 'passwort_aktuell' => 'falsch', 'pin' => '987654', 'pin_wiederholen' => '987654',
        ]);
        $this->assertSame('Das aktuelle Passwort ist falsch.', session()->getFlashdata('error'));

        $this->alsAngemeldet($id)->post('konto/pin', [
            ...$this->csrf(), 'passwort_aktuell' => 'geheim123', 'pin' => '12', 'pin_wiederholen' => '12',
        ]);
        $this->assertSame('Die PIN muss aus 4 bis 6 Ziffern bestehen.', session()->getFlashdata('error'));
        $this->assertSame($alt, $this->person($id)['pin_hash']);
    }

    public function test_konto_seite_wird_angezeigt(): void
    {
        $id = $this->personAnlegen(['anzeigename' => 'Anna Muster']);

        $seite = $this->alsAngemeldet($id)->get('konto');

        $seite->assertOK();
        $seite->assertSee('Anna Muster');
        $seite->assertSee('Passwort ändern');
    }
}
