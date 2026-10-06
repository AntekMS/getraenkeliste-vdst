<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\BuchungService;
use App\Models\ArtikelModel;
use App\Models\KategorieModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AdminStammdatenTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->personAnlegen(['benutzername' => 'chef']);
        $this->rolleGeben($this->admin, 'admin');
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function senden(string $pfad, array $daten = []): TestResponse
    {
        return $this->alsAngemeldet($this->admin)->post($pfad, $daten + $this->csrf());
    }

    private function kategorieAnlegen(string $name, string $bereich = 'getraenke'): int
    {
        $model = new KategorieModel();
        $id    = (int) $model->insert(['bereich_id' => $this->bereichId($bereich), 'name' => $name, 'sortierung' => $model->naechsteSortierung($this->bereichId($bereich))], true);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function artikel(int $id): array
    {
        return (new ArtikelModel())->find($id);
    }

    /**
     * @return array<string, string>
     */
    private function artikelDaten(int $kategorie, array $ueberschreibe = []): array
    {
        return $ueberschreibe + [
            'kategorie_id' => (string) $kategorie, 'name' => 'Pils', 'preis' => '1,80', 'einheit' => '0,5 l Flasche',
            'gebinde_groesse' => '20', 'mindestbestand' => '2', 'bestand_fuehren' => '1',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function protokoll(string $aktion): array
    {
        return db_connect()->table('protokoll')->where('aktion', $aktion)->orderBy('id')->get()->getResultArray();
    }

    /**
     * @return list<int> Artikel-IDs der Kategorie in Anzeigereihenfolge
     */
    private function reihenfolge(int $kategorie): array
    {
        $zeilen = (new ArtikelModel())->where('kategorie_id', $kategorie)->orderBy('sortierung')->orderBy('id')->findAll();

        return array_map('intval', array_column($zeilen, 'id'));
    }

    private function assertNotFound(string $methode, string $pfad, array $daten = []): void
    {
        try {
            $this->alsAngemeldet($this->admin)->{$methode}($pfad, $daten + $this->csrf());
            $this->fail('404 erwartet: ' . $pfad);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_artikel_anlegen_mit_kommapreis(): void
    {
        $kategorie = $this->kategorieAnlegen('Bier');

        $this->senden('admin/artikel', $this->artikelDaten($kategorie))->assertRedirectTo(site_url('admin/stammdaten'));

        $a = (new ArtikelModel())->first();
        $this->assertSame('Pils', $a['name']);
        $this->assertSame(180, (int) $a['preis_cent']);
        $this->assertSame(20, (int) $a['gebinde_groesse']);
        $this->assertSame(2, (int) $a['mindestbestand']);
        $this->assertSame(1, (int) $a['bestand_fuehren']);
        $this->assertCount(1, $this->protokoll('angelegt'));
    }

    public function test_ungueltiger_preis_zeigt_fehler(): void
    {
        $kategorie = $this->kategorieAnlegen('Bier');

        foreach (['abc', '', '1,234', str_repeat('9', 40)] as $preis) {
            $antwort = $this->senden('admin/artikel', $this->artikelDaten($kategorie, ['preis' => $preis]));
            $antwort->assertRedirectTo(site_url('admin/artikel/neu?kategorie=' . $kategorie));
            $this->assertSame('Bitte einen gültigen Preis eingeben, z. B. 1,50.', $_SESSION['error'] ?? null);
        }

        $this->assertSame([], (new ArtikelModel())->findAll());
    }

    public function test_weitere_feldpruefungen(): void
    {
        $kategorie = $this->kategorieAnlegen('Bier');

        foreach ([['name' => ''], ['name' => str_repeat('x', 101)], ['einheit' => ''], ['einheit' => str_repeat('x', 51)],
            ['gebinde_groesse' => '0'], ['gebinde_groesse' => '101'], ['gebinde_groesse' => 'x'], ['mindestbestand' => '-1'], ['mindestbestand' => 'x']] as $abweichung) {
            $this->senden('admin/artikel', $this->artikelDaten($kategorie, $abweichung))->assertRedirectTo(site_url('admin/artikel/neu?kategorie=' . $kategorie));
        }

        $this->assertSame([], (new ArtikelModel())->findAll());

        // Leeres Gebinde und leerer Mindestbestand sind erlaubt, Preis 0 ebenfalls (Gratis-Artikel).
        $this->senden('admin/artikel', $this->artikelDaten($kategorie, ['gebinde_groesse' => '', 'mindestbestand' => '', 'preis' => '0', 'bestand_fuehren' => '0']))
            ->assertRedirectTo(site_url('admin/stammdaten'));
        $a = (new ArtikelModel())->first();
        $this->assertNull($a['gebinde_groesse']);
        $this->assertSame(0, (int) $a['mindestbestand']);
        $this->assertSame(0, (int) $a['preis_cent']);
        $this->assertSame(0, (int) $a['bestand_fuehren']);
    }

    public function test_preisaenderung_aendert_alte_buchungen_nicht(): void
    {
        $artikel = $this->artikelAnlegen(['preis_cent' => 150]);
        $person  = $this->personAnlegen();
        (new BuchungService())->bucheVorgang(BuchungService::neueVorgangId(), $person, $person, null, 'web', [['artikel_id' => $artikel, 'menge' => 2]]);

        $kategorie = (int) $this->artikel($artikel)['kategorie_id'];
        $this->senden('admin/artikel/' . $artikel, $this->artikelDaten($kategorie, ['name' => 'Helles', 'preis' => '2,00']))
            ->assertRedirectTo(site_url('admin/artikel/' . $artikel));

        $this->assertSame(200, (int) $this->artikel($artikel)['preis_cent']);
        $this->assertSame(150, (int) db_connect()->table('buchungen')->get()->getRow()->einzelpreis_cent);
    }

    public function test_preisaenderung_wird_protokolliert(): void
    {
        $artikel   = $this->artikelAnlegen(['preis_cent' => 150, 'einheit' => '0,5 l Flasche']);
        $kategorie = (int) $this->artikel($artikel)['kategorie_id'];

        $this->senden('admin/artikel/' . $artikel, $this->artikelDaten($kategorie, ['name' => 'Helles', 'preis' => '2,00', 'gebinde_groesse' => '', 'mindestbestand' => '0']));

        $zeilen = $this->protokoll('preis_geaendert');
        $this->assertCount(1, $zeilen);
        $this->assertSame('artikel', $zeilen[0]['tabelle']);
        $this->assertSame($artikel, (int) $zeilen[0]['datensatz_id']);
        $this->assertSame(['preis_cent' => 150], json_decode($zeilen[0]['alt'], true));
        $this->assertSame(['preis_cent' => 200], json_decode($zeilen[0]['neu'], true));
        $this->assertSame([], $this->protokoll('geaendert'), 'Reine Preisänderung ist kein zusätzliches „geaendert“.');
    }

    public function test_artikel_in_andere_kategorie_verschieben_kommt_ans_ende(): void
    {
        $a     = $this->kategorieAnlegen('Bier');
        $b     = $this->kategorieAnlegen('Wein');
        $x     = $this->artikelAnlegen(['kategorie_id' => $a, 'sortierung' => 1]);
        $y     = $this->artikelAnlegen(['kategorie_id' => $b, 'name' => 'Riesling', 'sortierung' => 5]);

        $this->senden('admin/artikel/' . $x, $this->artikelDaten($b, ['name' => 'Helles', 'preis' => '1,50', 'gebinde_groesse' => '', 'mindestbestand' => '0', 'einheit' => '0,5 l']));

        $this->assertSame($b, (int) $this->artikel($x)['kategorie_id']);
        $this->assertSame(6, (int) $this->artikel($x)['sortierung']);
        $this->assertSame([$y, $x], $this->reihenfolge($b));

        // Archivierte Kategorie ist kein gültiges Ziel.
        $archiv = $this->kategorieAnlegen('Alt');
        (new KategorieModel())->update($archiv, ['archiviert_at' => '2026-10-01 00:00:00']);
        $this->senden('admin/artikel/' . $x, $this->artikelDaten($archiv))->assertRedirectTo(site_url('admin/artikel/' . $x));
        $this->assertSame($b, (int) $this->artikel($x)['kategorie_id']);
    }

    public function test_verschieben_hoch_runter(): void
    {
        $kategorie = $this->kategorieAnlegen('Bier');
        // Alle mit gleicher Sortierung 0 (Gleichstand) sowie ein archivierter Artikel dazwischen.
        $a = $this->artikelAnlegen(['kategorie_id' => $kategorie, 'name' => 'A']);
        $b = $this->artikelAnlegen(['kategorie_id' => $kategorie, 'name' => 'B']);
        $c = $this->artikelAnlegen(['kategorie_id' => $kategorie, 'name' => 'C']);

        $this->senden("admin/artikel/{$c}/verschieben/hoch")->assertRedirectTo(site_url('admin/stammdaten'));
        $this->assertSame([$a, $c, $b], $this->reihenfolge($kategorie));

        $this->senden("admin/artikel/{$a}/verschieben/runter");
        $this->assertSame([$c, $a, $b], $this->reihenfolge($kategorie));

        // Rand: erstes „hoch“ und letztes „runter“ ändern nichts und liefern keinen Fehler.
        $this->senden("admin/artikel/{$c}/verschieben/hoch")->assertRedirectTo(site_url('admin/stammdaten'));
        $this->senden("admin/artikel/{$b}/verschieben/runter")->assertRedirectTo(site_url('admin/stammdaten'));
        $this->assertSame([$c, $a, $b], $this->reihenfolge($kategorie));

        // Archivierter Artikel dazwischen: wird übersprungen und behält seine Sortierung.
        $k = $this->kategorieAnlegen('Cider');
        $p = $this->artikelAnlegen(['kategorie_id' => $k, 'name' => 'P', 'sortierung' => 1]);
        $q = $this->artikelAnlegen(['kategorie_id' => $k, 'name' => 'Q', 'sortierung' => 2, 'archiviert_at' => '2026-10-01 00:00:00']);
        $r = $this->artikelAnlegen(['kategorie_id' => $k, 'name' => 'R', 'sortierung' => 3]);
        $this->senden("admin/artikel/{$r}/verschieben/hoch");
        $this->assertSame(2, (int) $this->artikel($q)['sortierung']);
        $this->assertSame(1, (int) $this->artikel($r)['sortierung']);
        $this->assertSame(2, (int) $this->artikel($p)['sortierung']);

        // Kategorien
        $k1 = $this->kategorieAnlegen('Wein');
        $k2 = $this->kategorieAnlegen('Saft');
        $this->senden("admin/kategorien/{$k2}/verschieben/hoch");
        $namen = array_column((new KategorieModel())->where('bereich_id', $this->bereichId('getraenke'))->orderBy('sortierung')->orderBy('id')->findAll(), 'id');
        $this->assertSame([$kategorie, $k, $k2, $k1], array_map('intval', $namen));
        $this->assertSame([], $this->protokoll('verschoben'), 'Sortieren wird nicht protokolliert.');
    }

    public function test_archivierter_artikel_verschwindet_von_buchungsseite(): void
    {
        $artikel = $this->artikelAnlegen(['name' => 'Sonderbock']);
        $person  = $this->personAnlegen();
        $this->rolleGeben($person, 'admin');

        $this->assertStringContainsString('Sonderbock', $this->alsAngemeldet($person)->get('buchen')->getBody());

        $this->senden("admin/artikel/{$artikel}/archivieren")->assertRedirectTo(site_url('admin/stammdaten'));

        $this->assertNotNull($this->artikel($artikel)['archiviert_at']);
        $this->assertStringNotContainsString('Sonderbock', $this->alsAngemeldet($person)->get('buchen')->getBody());
        $this->assertCount(1, $this->protokoll('archiviert'));
    }

    public function test_kategorie_anlegen_umbenennen_archivieren(): void
    {
        $this->senden('admin/kategorien', ['bereich_id' => (string) $this->bereichId('getraenke'), 'name' => 'Bier'])->assertRedirectTo(site_url('admin/stammdaten'));
        $kategorie = (new KategorieModel())->first();
        $this->assertSame('Bier', $kategorie['name']);

        $this->senden('admin/kategorien/' . $kategorie['id'], ['name' => 'Biere']);
        $this->assertSame('Biere', (new KategorieModel())->find($kategorie['id'])['name']);

        $this->senden('admin/kategorien', ['bereich_id' => (string) $this->bereichId('getraenke'), 'name' => ''])->assertRedirectTo(site_url('admin/stammdaten'));
        $this->assertSame('Bitte einen Namen eingeben.', $_SESSION['error'] ?? null);
        $this->assertCount(1, (new KategorieModel())->findAll());

        // Archivieren: Artikel bleiben unarchiviert, sind aber nicht buchbar.
        $artikel = $this->artikelAnlegen(['kategorie_id' => (int) $kategorie['id']]);
        $this->senden('admin/kategorien/' . $kategorie['id'] . '/archivieren');
        $this->assertNotNull((new KategorieModel())->find($kategorie['id'])['archiviert_at']);
        $this->assertNull($this->artikel($artikel)['archiviert_at']);
        $this->assertNull((new ArtikelModel())->findeBuchbar($artikel));

        // In archivierter Kategorie kein neuer Artikel.
        $this->senden('admin/artikel', $this->artikelDaten((int) $kategorie['id']))->assertRedirectTo(site_url('admin/stammdaten'));
        $this->assertCount(1, (new ArtikelModel())->findAll());

        $this->assertCount(1, $this->protokoll('geaendert'));
        $this->assertCount(1, $this->protokoll('archiviert'));
    }

    public function test_liste_zeigt_archivierte_nur_mit_schalter(): void
    {
        $this->artikelAnlegen(['name' => 'Sichtbar']);
        $this->artikelAnlegen(['name' => 'Verborgen', 'archiviert_at' => '2026-10-01 00:00:00']);

        $normal = $this->alsAngemeldet($this->admin)->get('admin/stammdaten')->getBody();
        $this->assertStringContainsString('Sichtbar', $normal);
        $this->assertStringNotContainsString('Verborgen', $normal);

        $alle = $this->alsAngemeldet($this->admin)->get('admin/stammdaten?archiviert=ja')->getBody();
        $this->assertStringContainsString('Verborgen', $alle);
    }

    public function test_kiosk_nicht_sichtbar_und_nicht_anlegbar(): void
    {
        $kiosk    = $this->kategorieAnlegen('Snacks', 'kiosk');
        $artikel  = $this->artikelAnlegen(['kategorie_id' => $kiosk, 'name' => 'Brezel']);
        $getraenk = $this->kategorieAnlegen('Bier');

        $seite = $this->alsAngemeldet($this->admin)->get('admin/stammdaten')->getBody();
        $this->assertStringNotContainsString('Snacks', $seite);
        $this->assertStringNotContainsString('Brezel', $seite);
        $this->assertStringNotContainsString('Fuxenkiosk', $seite);

        $this->assertNotFound('post', 'admin/kategorien', ['bereich_id' => (string) $this->bereichId('kiosk'), 'name' => 'Neu']);
        $this->assertNotFound('post', 'admin/artikel', $this->artikelDaten($kiosk));
        $this->assertNotFound('get', "admin/artikel/neu?kategorie={$kiosk}");
        $this->assertNotFound('get', "admin/artikel/{$artikel}");
        $this->assertNotFound('post', "admin/artikel/{$artikel}", $this->artikelDaten($kiosk));
        $this->assertNotFound('post', "admin/artikel/{$artikel}/verschieben/hoch");
        $this->assertNotFound('post', "admin/artikel/{$artikel}/archivieren");
        $this->assertNotFound('post', "admin/kategorien/{$kiosk}", ['name' => 'X']);
        $this->assertNotFound('post', "admin/kategorien/{$kiosk}/verschieben/runter");
        $this->assertNotFound('post', "admin/kategorien/{$kiosk}/archivieren");

        // Verschieben eines Artikels in eine Kiosk-Kategorie ist ebenfalls abgelehnt.
        $eigener = $this->artikelAnlegen(['kategorie_id' => $getraenk]);
        $this->senden("admin/artikel/{$eigener}", $this->artikelDaten($kiosk))->assertRedirectTo(site_url("admin/artikel/{$eigener}"));
        $this->assertSame($getraenk, (int) $this->artikel($eigener)['kategorie_id']);

        $this->assertNull($this->artikel($artikel)['archiviert_at']);
        $this->assertSame('Snacks', (new KategorieModel())->find($kiosk)['name']);
    }

    public function test_nur_admin_hat_zugriff(): void
    {
        $person = $this->personAnlegen();

        $this->assertSame(403, $this->alsAngemeldet($person)->get('admin/stammdaten')->response()->getStatusCode());
    }
}
