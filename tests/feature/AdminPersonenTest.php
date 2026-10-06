<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnmeldeTokenModel;
use App\Models\PersonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AdminPersonenTest extends DbTestCase
{
    private int $admin;

    /** @var list<string> */
    private array $tmpDateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->personAnlegen(['benutzername' => 'chef']);
        $this->rolleGeben($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);

        foreach ($this->tmpDateien as $datei) {
            @unlink($datei);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function senden(string $pfad, array $daten = [], ?int $als = null): TestResponse
    {
        return $this->alsAngemeldet($als ?? $this->admin)->post($pfad, $daten + $this->csrf());
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $id): array
    {
        return (new PersonModel())->find($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function protokoll(string $aktion): array
    {
        return db_connect()->table('protokoll')->where('aktion', $aktion)->orderBy('id')->get()->getResultArray();
    }

    /**
     * Wie der Browser: Flash der letzten Antwort geht in die nächste Sitzung.
     */
    private function einmalpasswoerterSeite(): TestResponse
    {
        $flash = $_SESSION['einmalpasswoerter'] ?? null;
        $this->assertNotNull($flash, 'Kein Einmal-Passwort im Flash.');

        return $this->withSession([
            ...$this->angemeldeteSitzung($this->admin),
            'einmalpasswoerter'    => $flash,
            '__ci_vars'            => ['einmalpasswoerter' => 'new'],
        ])->get('admin/personen/einmalpasswoerter');
    }

    private function neuePersonDaten(): array
    {
        return ['vorname' => 'Anna', 'nachname' => 'Beispiel', 'anzeigename' => '', 'gruppe' => 'aktiv', 'benutzername' => ' Anna.B '];
    }

    public function test_mitglied_ohne_adminrolle_403_auf_allen_adminrouten(): void
    {
        $mitglied = $this->personAnlegen();
        $id       = $this->personAnlegen();

        foreach (['admin/personen', 'admin/personen/neu', "admin/personen/{$id}", 'admin/personen/import', 'admin/personen/einmalpasswoerter'] as $pfad) {
            $this->alsAngemeldet($mitglied)->get($pfad)->assertStatus(403);
        }

        foreach (['admin/personen', "admin/personen/{$id}", "admin/personen/{$id}/passwort-reset", "admin/personen/{$id}/pin-reset",
            "admin/personen/{$id}/archivieren", 'admin/personen/import/vorschau', 'admin/personen/import/ausfuehren'] as $pfad) {
            $this->senden($pfad, ['vorname' => 'X'], $mitglied)->assertStatus(403);
        }

        $this->assertNull((new PersonModel())->where('vorname', 'X')->first());
        $this->assertSame([], $this->protokoll('angelegt'));
    }

    public function test_liste_filtert_und_zeigt_keine_sammelkonten(): void
    {
        $this->personAnlegen(['vorname' => 'Aktiver', 'nachname' => 'Fux', 'anzeigename' => 'Aktiver Fux']);
        $this->personAnlegen(['vorname' => 'Alter', 'nachname' => 'Herr', 'anzeigename' => 'Alter Herr', 'gruppe' => 'ah']);
        $this->personAnlegen(['vorname' => 'Weg', 'nachname' => 'Gegangen', 'anzeigename' => 'Weg Gegangen', 'archiviert_at' => '2026-01-01 00:00:00']);

        $alle = $this->alsAngemeldet($this->admin)->get('admin/personen');
        $alle->assertOK();
        $alle->assertSee('Aktiver Fux');
        $alle->assertSee('Alter Herr');
        $alle->assertDontSee('Weg Gegangen');
        $alle->assertDontSee('Couleur');
        $alle->assertDontSee('Bund');

        $ah = $this->alsAngemeldet($this->admin)->get('admin/personen?gruppe=ah');
        $ah->assertSee('Alter Herr');
        $ah->assertDontSee('Aktiver Fux');

        $archiv = $this->alsAngemeldet($this->admin)->get('admin/personen?archiviert=ja');
        $archiv->assertSee('Weg Gegangen');
        $archiv->assertDontSee('Aktiver Fux');
    }

    public function test_sammelkonto_ist_nicht_bearbeitbar(): void
    {
        $couleur = (new PersonModel())->sammelkontoId('Couleur');

        foreach ([['get', "admin/personen/{$couleur}"], ['post', "admin/personen/{$couleur}/archivieren"]] as [$methode, $pfad]) {
            try {
                $this->alsAngemeldet($this->admin)->{$methode}($pfad, $this->csrf());
                $this->fail('404 erwartet: ' . $pfad);
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($this->person($couleur)['archiviert_at']);
    }

    public function test_person_anlegen_zeigt_einmalpasswort_und_erzwingt_wechsel(): void
    {
        $antwort = $this->senden('admin/personen', $this->neuePersonDaten());
        $antwort->assertRedirectTo(site_url('admin/personen/einmalpasswoerter'));

        $person = (new PersonModel())->where('benutzername', 'anna.b')->first();
        $this->assertNotNull($person);
        $this->assertSame('Anna Beispiel', $person['anzeigename']);
        $this->assertSame('mitglied', $person['typ']);
        $this->assertSame(1, (int) $person['passwort_wechsel_erzwingen']);
        $this->assertNull($person['pin_hash']);

        $seite = $this->einmalpasswoerterSeite();
        $seite->assertOK();
        $this->assertSame(1, preg_match('/class="einmalpasswort">([^<]+)</', $seite->getBody(), $m));
        $this->assertTrue(password_verify($m[1], $person['passwort_hash']));
        $seite->assertSee('anna.b');

        $protokoll = $this->protokoll('angelegt');
        $this->assertCount(1, $protokoll);
        $this->assertSame((int) $person['id'], (int) $protokoll[0]['datensatz_id']);
        $this->assertStringNotContainsString($m[1], json_encode($protokoll));
    }

    public function test_anlegen_prueft_benutzername_und_eindeutigkeit(): void
    {
        $this->personAnlegen(['benutzername' => 'anna.b']);

        $this->senden('admin/personen', $this->neuePersonDaten());
        $this->assertSame(1, (new PersonModel())->where('benutzername', 'anna.b')->countAllResults());
        $this->assertSame('Benutzername existiert bereits.', $_SESSION['error']);

        $this->senden('admin/personen', ['benutzername' => 'a b'] + $this->neuePersonDaten());
        $this->assertNull((new PersonModel())->where('vorname', 'Anna')->first());
        $this->assertStringContainsString('ungültig', $_SESSION['error']);

        $this->senden('admin/personen', ['vorname' => str_repeat('x', 101), 'benutzername' => 'langer'] + $this->neuePersonDaten());
        $this->assertNull((new PersonModel())->where('benutzername', 'langer')->first());
        $this->assertStringContainsString('100 Zeichen', $_SESSION['error']);
    }

    public function test_bearbeiten_protokolliert_nur_geaenderte_felder(): void
    {
        $id = $this->personAnlegen(['vorname' => 'Alt', 'nachname' => 'Name', 'anzeigename' => 'Alt Name', 'benutzername' => 'altname']);

        $this->senden("admin/personen/{$id}", [
            'vorname' => 'Neu', 'nachname' => 'Name', 'anzeigename' => 'Alt Name', 'gruppe' => 'aktiv', 'benutzername' => 'altname',
        ])->assertRedirectTo(site_url("admin/personen/{$id}"));

        $this->assertSame('Neu', $this->person($id)['vorname']);
        $protokoll = $this->protokoll('geaendert');
        $this->assertCount(1, $protokoll);
        $this->assertSame(['vorname' => 'Alt'], json_decode($protokoll[0]['alt'], true));
        $this->assertSame(['vorname' => 'Neu'], json_decode($protokoll[0]['neu'], true));
    }

    public function test_bearbeiten_lehnt_vergebenen_benutzernamen_ab(): void
    {
        $id = $this->personAnlegen(['benutzername' => 'eins']);
        $this->personAnlegen(['benutzername' => 'zwei']);

        $this->senden("admin/personen/{$id}", [
            'vorname' => 'A', 'nachname' => 'B', 'anzeigename' => 'A B', 'gruppe' => 'aktiv', 'benutzername' => 'ZWEI',
        ]);

        $this->assertSame('eins', $this->person($id)['benutzername']);
        $this->assertSame([], $this->protokoll('geaendert'));
    }

    public function test_rollen_vergeben_wird_protokolliert(): void
    {
        $id = $this->personAnlegen();

        $this->senden("admin/personen/{$id}", [
            'vorname' => $this->person($id)['vorname'], 'nachname' => $this->person($id)['nachname'], 'anzeigename' => $this->person($id)['anzeigename'],
            'gruppe' => 'aktiv', 'benutzername' => $this->person($id)['benutzername'], 'rollen' => ['kassenwart', 'getraenkewart', 'unsinn'],
        ]);

        $this->assertSame(['mitglied', 'getraenkewart', 'kassenwart'], (new PersonModel())->rollen($id));
        $protokoll = $this->protokoll('rollen_geaendert');
        $this->assertCount(1, $protokoll);
        $this->assertSame((int) $id, (int) $protokoll[0]['datensatz_id']);
        $this->assertSame(['rollen' => []], json_decode($protokoll[0]['alt'], true));
        $this->assertSame(['rollen' => ['getraenkewart', 'kassenwart']], json_decode($protokoll[0]['neu'], true));
        $this->assertSame([], $this->protokoll('geaendert'));
    }

    public function test_pin_reset_loescht_pin(): void
    {
        $id = $this->personAnlegen(['pin' => '1234', 'pin_fehlversuche' => 3, 'pin_gesperrt_bis' => '2030-01-01 00:00:00']);

        $this->senden("admin/personen/{$id}/pin-reset")->assertRedirectTo(site_url("admin/personen/{$id}"));

        $person = $this->person($id);
        $this->assertNull($person['pin_hash']);
        $this->assertSame(0, (int) $person['pin_fehlversuche']);
        $this->assertNull($person['pin_gesperrt_bis']);
        $this->assertCount(1, $this->protokoll('pin_reset'));
    }

    public function test_passwort_reset_loescht_tokens(): void
    {
        $id = $this->personAnlegen(['login_fehlversuche' => 4, 'login_gesperrt_bis' => '2030-01-01 00:00:00']);
        $alt = $this->person($id)['passwort_hash'];
        (new AnmeldeTokenModel())->insert(['person_id' => $id, 'selector' => 'abc', 'token_hash' => 'x', 'gueltig_bis' => '2030-01-01 00:00:00']);

        $this->senden("admin/personen/{$id}/passwort-reset")->assertRedirectTo(site_url('admin/personen/einmalpasswoerter'));

        $person = $this->person($id);
        $this->assertNotSame($alt, $person['passwort_hash']);
        $this->assertSame(1, (int) $person['passwort_wechsel_erzwingen']);
        $this->assertSame(0, (int) $person['login_fehlversuche']);
        $this->assertNull($person['login_gesperrt_bis']);
        $this->assertSame(0, (new AnmeldeTokenModel())->where('person_id', $id)->countAllResults());

        $seite = $this->einmalpasswoerterSeite();
        $this->assertSame(1, preg_match('/class="einmalpasswort">([^<]+)</', $seite->getBody(), $m));
        $this->assertTrue(password_verify($m[1], $person['passwort_hash']));
        $this->assertStringNotContainsString($m[1], json_encode($this->protokoll('passwort_reset')));
        $this->assertCount(1, $this->protokoll('passwort_reset'));

        // Nur einmal sichtbar
        $this->alsAngemeldet($this->admin)->get('admin/personen/einmalpasswoerter')->assertRedirectTo(site_url('admin/personen'));
    }

    public function test_archivieren(): void
    {
        $this->uhrStellen('2026-10-05 12:00:00');
        $id = $this->personAnlegen();
        (new AnmeldeTokenModel())->insert(['person_id' => $id, 'selector' => 'abc', 'token_hash' => 'x', 'gueltig_bis' => '2030-01-01 00:00:00']);

        $this->senden("admin/personen/{$id}/archivieren")->assertRedirectTo(site_url('admin/personen'));

        $this->assertSame('2026-10-05 12:00:00', $this->person($id)['archiviert_at']);
        $this->assertSame(0, (new AnmeldeTokenModel())->where('person_id', $id)->countAllResults());
        $this->assertCount(1, $this->protokoll('archiviert'));
    }

    public function test_admin_kann_sich_nicht_selbst_entmachten(): void
    {
        $this->senden("admin/personen/{$this->admin}/archivieren");
        $this->assertNull($this->person($this->admin)['archiviert_at']);

        $this->senden("admin/personen/{$this->admin}", [
            'vorname' => 'Test', 'nachname' => 'Person', 'anzeigename' => 'Test Person', 'gruppe' => 'aktiv',
            'benutzername' => 'chef', 'rollen' => ['kassenwart'],
        ]);

        $this->assertSame(['mitglied', 'admin'], (new PersonModel())->rollen($this->admin));
        $this->assertSame([], $this->protokoll('rollen_geaendert'));
        $this->assertSame([], $this->protokoll('archiviert'));
    }

    public function test_import_legt_nur_fehlerfreie_zeilen_an(): void
    {
        $this->personAnlegen(['benutzername' => 'vergeben']);
        $csv = "vorname;nachname;gruppe;benutzername\r\nAnna;Eins;aktiv;anna\r\nBen;Zwei;xx;ben\r\nCarl;Drei;ah;vergeben\r\nJ\xFCrgen;M\xFCller;AH;jmueller\r\n";
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $this->tmpDateien[] = $tmp;
        file_put_contents($tmp, $csv);
        service('superglobals')->setFilesArray(['datei' => ['name' => 'leute.csv', 'type' => 'text/csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]]);

        $vorschau = $this->senden('admin/personen/import/vorschau');
        $vorschau->assertOK();
        $vorschau->assertSee('Gruppe unbekannt');
        $vorschau->assertSee('Benutzername existiert bereits');
        $vorschau->assertSee('Jürgen');
        $this->assertSame(0, (new PersonModel())->where('benutzername', 'anna')->countAllResults());
        service('superglobals')->setFilesArray([]);

        foreach ($this->tmpDateien as $datei) {
            @unlink($datei);
        }

        $zeilen = $_SESSION['import_zeilen'] ?? null;
        $this->assertCount(4, $zeilen);

        $this->alsAngemeldet($this->admin)->withSession([
            ...$this->angemeldeteSitzung($this->admin), 'csrf_test_name' => 'test-token', 'import_zeilen' => $zeilen,
        ])->post('admin/personen/import/ausfuehren', $this->csrf())->assertRedirectTo(site_url('admin/personen/einmalpasswoerter'));

        $personen = new PersonModel();
        $this->assertNotNull($personen->where('benutzername', 'anna')->first());
        $this->assertNull($personen->where('benutzername', 'ben')->first());
        $juergen = $personen->where('benutzername', 'jmueller')->first();
        $this->assertSame('Jürgen Müller', $juergen['anzeigename']);
        $this->assertSame('ah', $juergen['gruppe']);
        $this->assertSame(1, (int) $juergen['passwort_wechsel_erzwingen']);
        $this->assertCount(2, $this->protokoll('importiert'));

        $seite = $this->einmalpasswoerterSeite();
        $seite->assertSee('jmueller');
        $this->assertSame(2, preg_match_all('/class="einmalpasswort">([^<]+)</', $seite->getBody(), $m));
        $this->assertTrue(password_verify($m[1][1], $juergen['passwort_hash']));
    }

    public function test_import_prueft_benutzername_beim_ausfuehren_erneut(): void
    {
        $zeilen = [
            ['zeile' => 2, 'vorname' => 'Anna', 'nachname' => 'Eins', 'gruppe' => 'aktiv', 'benutzername' => 'anna', 'fehler' => null],
            ['zeile' => 3, 'vorname' => 'Ben', 'nachname' => 'Zwei', 'gruppe' => 'aktiv', 'benutzername' => 'ben', 'fehler' => null],
        ];
        $this->personAnlegen(['benutzername' => 'anna']); // inzwischen vergeben

        $this->alsAngemeldet($this->admin)->withSession([
            ...$this->angemeldeteSitzung($this->admin), 'csrf_test_name' => 'test-token', 'import_zeilen' => $zeilen,
        ])->post('admin/personen/import/ausfuehren', $this->csrf());

        $this->assertSame(1, (new PersonModel())->where('benutzername', 'anna')->countAllResults());
        $this->assertNotNull((new PersonModel())->where('benutzername', 'ben')->first());
        $this->assertCount(1, $this->protokoll('importiert'));
    }

    public function test_import_ohne_vorschau_oder_mit_falschem_dateityp(): void
    {
        $this->senden('admin/personen/import/ausfuehren')->assertRedirectTo(site_url('admin/personen/import'));

        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $this->tmpDateien[] = $tmp;
        file_put_contents($tmp, 'x');
        service('superglobals')->setFilesArray(['datei' => ['name' => 'leute.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 1]]);
        $this->senden('admin/personen/import/vorschau')->assertRedirectTo(site_url('admin/personen/import'));
    }

    public function test_protokoll_enthaelt_keine_hashes(): void
    {
        $this->senden('admin/personen', $this->neuePersonDaten());
        $id = (int) (new PersonModel())->where('benutzername', 'anna.b')->first()['id'];
        $this->senden("admin/personen/{$id}/passwort-reset");
        $this->senden("admin/personen/{$id}/pin-reset");
        $this->senden("admin/personen/{$id}/archivieren");

        $zeilen = db_connect()->table('protokoll')->get()->getResultArray();
        $this->assertNotEmpty($zeilen);

        foreach ($zeilen as $zeile) {
            $text = json_encode($zeile);
            $this->assertStringNotContainsString('$2y$', $text);
            $this->assertStringNotContainsString('_hash', $text);
        }
    }
}
