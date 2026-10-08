<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Artikelbild;
use App\Models\ArtikelModel;
use App\Models\FreischaltcodeModel;
use App\Models\GeraetModel;
use App\Models\PersonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use RuntimeException;
use Tests\Support\DbTestCase;

/**
 * Artikelbilder: Upload im Admin-Formular (prüfen, neu codieren, verkleinern), Abruf über `artikelbild/<id>`
 * (Anmeldung oder Tablet), Anzeige auf der Buchungskachel. Bilder landen in einem Temp-Verzeichnis.
 *
 * @internal
 */
final class ArtikelbildTest extends DbTestCase
{
    private const JETZT = '2026-10-05 12:00:00';

    private int $admin;
    private string $basis;

    /** @var list<string> */
    private array $tmpDateien = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen(self::JETZT);
        $this->basis = sys_get_temp_dir() . '/artikelbild_' . bin2hex(random_bytes(6));
        mkdir($this->basis);
        Services::injectMock('artikelbild', new Artikelbild($this->basis));
        $this->admin = $this->personAnlegen(['benutzername' => 'chef']);
        $this->rolleGeben($this->admin, 'admin');
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        service('superglobals')->unsetCookie('gl_geraet');

        foreach ($this->tmpDateien as $datei) {
            @unlink($datei);
        }

        foreach (glob($this->basis . '/artikelbilder/{,.}*', GLOB_BRACE) ?: [] as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }

        @rmdir($this->basis . '/artikelbilder');
        @rmdir($this->basis);
        parent::tearDown();
    }

    // ---- Hilfen -------------------------------------------------------------

    /**
     * Erzeugt ein Testbild mit GD (deckend oder mit transparentem Bereich).
     */
    private function bild(int $breite, int $hoehe, string $format = 'png', bool $transparent = false): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefill($bild, 0, 0, (int) imagecolorallocate($bild, 200, 30, 30));

        if ($transparent) {
            imagealphablending($bild, false);
            imagesavealpha($bild, true);
            imagefilledrectangle($bild, 0, 0, intdiv($breite, 2), $hoehe - 1, (int) imagecolorallocatealpha($bild, 0, 0, 0, 127));
        }

        $pfad = (string) tempnam(sys_get_temp_dir(), 'bild');
        $this->tmpDateien[] = $pfad;

        match ($format) {
            'png'  => imagepng($bild, $pfad),
            'jpeg' => imagejpeg($bild, $pfad),
            'webp' => imagewebp($bild, $pfad),
        };

        return $pfad;
    }

    private function hochladen(string $pfad, string $name = 'foto.png', ?int $groesse = null): void
    {
        service('superglobals')->setFilesArray(['bild' => [
            'name' => $name, 'type' => 'image/png', 'tmp_name' => $pfad, 'error' => UPLOAD_ERR_OK, 'size' => $groesse ?? (int) filesize($pfad),
        ]]);
    }

    /**
     * @param array<string, string> $zusatz
     */
    private function speichern(int $artikelId, array $zusatz = []): TestResponse
    {
        $artikel = (new ArtikelModel())->find($artikelId);

        return $this->alsAngemeldet($this->admin)->post("admin/artikel/{$artikelId}", $zusatz + [
            'kategorie_id' => (string) $artikel['kategorie_id'], 'name' => $artikel['name'], 'preis' => '1,50',
            'einheit' => $artikel['einheit'], 'gebinde_groesse' => '', 'mindestbestand' => '0', 'bestand_fuehren' => '1',
        ] + $this->csrf());
    }

    /**
     * @return array<string, mixed>
     */
    private function artikel(int $id): array
    {
        return (new ArtikelModel())->find($id);
    }

    /**
     * @return list<string> gespeicherte Bilddateien (ohne Temp-Dateien)
     */
    private function dateien(): array
    {
        return array_map('basename', glob($this->basis . '/artikelbilder/*') ?: []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function protokoll(string $aktion): array
    {
        return db_connect()->table('protokoll')->where('aktion', $aktion)->orderBy('id')->get()->getResultArray();
    }

    private function tabletCookie(): string
    {
        $code  = (new FreischaltcodeModel())->erzeuge($this->admin, service('uhr')->jetzt());
        $token = service('geraete')->freischalten($code, 'Kühlschrank');
        $this->assertNotNull($token);
        service('superglobals')->setCookie('gl_geraet', $token);

        return $token;
    }

    private function artikelMitBild(array $werte = []): int
    {
        $id = $this->artikelAnlegen($werte);
        $pfad = $this->bild(40, 30);
        service('artikelbild')->speichere($id, $pfad, (int) filesize($pfad), $this->admin);

        return $id;
    }

    // ---- Upload -------------------------------------------------------------

    public function test_png_hochladen_verkleinert_codiert_neu_und_protokolliert(): void
    {
        $id = $this->artikelAnlegen();
        $this->hochladen($this->bild(1200, 800));

        $this->speichern($id)->assertRedirectTo(site_url("admin/artikel/{$id}"));

        $artikel = $this->artikel($id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\.jpg\z/', $artikel['bild_datei'], 'deckendes PNG wird als JPEG gespeichert');
        $this->assertSame(1, (int) $artikel['bild_version']);
        $this->assertSame([$artikel['bild_datei']], $this->dateien());

        $info = getimagesize($this->basis . '/artikelbilder/' . $artikel['bild_datei']);
        $this->assertSame([600, 400, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);

        $eintraege = $this->protokoll('bild_geaendert');
        $this->assertCount(1, $eintraege);
        $this->assertSame('artikel', $eintraege[0]['tabelle']);
        $this->assertSame($id, (int) $eintraege[0]['datensatz_id']);
        $this->assertSame(['bild_version' => 1], json_decode((string) $eintraege[0]['neu'], true));
        $this->assertSame([], $this->protokoll('geaendert'), 'unveränderte Felder werden nicht protokolliert');
    }

    public function test_kleines_bild_wird_nicht_vergroessert_und_transparenz_bleibt_png(): void
    {
        $id = $this->artikelAnlegen();
        $this->hochladen($this->bild(300, 200, 'png', true));

        $this->speichern($id);

        $datei = $this->artikel($id)['bild_datei'];
        $this->assertStringEndsWith('.png', $datei);
        $info = getimagesize($this->basis . '/artikelbilder/' . $datei);
        $this->assertSame([300, 200], [$info[0], $info[1]]);
    }

    public function test_jpeg_und_webp_werden_angenommen(): void
    {
        $this->assertTrue((bool) (gd_info()['WebP Support'] ?? false), 'Docker-Image baut GD mit WebP');

        foreach (['jpeg', 'webp'] as $format) {
            $id = $this->artikelAnlegen(['name' => $format]);
            $this->hochladen($this->bild(100, 100, $format), 'foto.' . $format);
            $this->speichern($id, ['name' => $format]);
            $this->assertStringEndsWith('.jpg', (string) $this->artikel($id)['bild_datei'], $format);
        }
    }

    public function test_jpeg_mit_exif_drehung_wird_aufgerichtet(): void
    {
        $id   = $this->artikelAnlegen();
        $pfad = $this->bild(200, 100, 'jpeg');
        // Minimales EXIF (APP1) mit Orientation = 6 (90° im Uhrzeigersinn) direkt hinter SOI einfügen.
        $tiff  = "II\x2A\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
        $app1  = "Exif\x00\x00" . $tiff;
        $daten = (string) file_get_contents($pfad);
        file_put_contents($pfad, substr($daten, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($daten, 2));
        $this->hochladen($pfad, 'handy.jpg');

        $this->speichern($id);

        $info = getimagesize($this->basis . '/artikelbilder/' . $this->artikel($id)['bild_datei']);
        $this->assertSame([100, 200], [$info[0], $info[1]]);
        $this->assertStringNotContainsString("Exif\x00\x00", (string) file_get_contents($this->basis . '/artikelbilder/' . $this->artikel($id)['bild_datei']), 'EXIF entfernt');
    }

    public function test_textdatei_mit_jpg_endung_wird_abgelehnt(): void
    {
        $id   = $this->artikelAnlegen();
        $pfad = (string) tempnam(sys_get_temp_dir(), 'txt');
        $this->tmpDateien[] = $pfad;
        file_put_contents($pfad, "Kein Bild\n");
        $this->hochladen($pfad, 'bild.jpg');

        $this->speichern($id)->assertRedirectTo(site_url("admin/artikel/{$id}"));

        $this->assertSame(['bild' => Artikelbild::MELDUNG_TYP], session()->getFlashdata('fehler'));
        $this->assertNull($this->artikel($id)['bild_datei']);
        $this->assertSame([], $this->dateien());
        $this->assertSame([], $this->protokoll('bild_geaendert'));
    }

    public function test_svg_und_html_polyglot_werden_abgelehnt(): void
    {
        $png = (string) file_get_contents($this->bild(10, 10));

        foreach ([
            'bild.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
            'bild.png' => '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>' . $png,
        ] as $name => $inhalt) {
            $id   = $this->artikelAnlegen(['name' => $name]);
            $pfad = (string) tempnam(sys_get_temp_dir(), 'poly');
            $this->tmpDateien[] = $pfad;
            file_put_contents($pfad, $inhalt);
            $this->hochladen($pfad, $name);

            $this->speichern($id, ['name' => $name])->assertRedirectTo(site_url("admin/artikel/{$id}"));

            $this->assertSame(['bild' => Artikelbild::MELDUNG_TYP], session()->getFlashdata('fehler'), $name);
            $this->assertNull($this->artikel($id)['bild_datei'], $name);
        }

        $this->assertSame([], $this->dateien());
    }

    public function test_zu_grosses_bild_wird_abgelehnt(): void
    {
        $id = $this->artikelAnlegen();
        $this->hochladen($this->bild(50, 50), 'foto.png', Artikelbild::MAX_BYTES + 1);

        $this->speichern($id);

        $this->assertSame(['bild' => 'Das Bild ist zu groß (max. 5 MB).'], session()->getFlashdata('fehler'));
        $this->assertNull($this->artikel($id)['bild_datei']);
        $this->assertSame([], $this->dateien());
    }

    public function test_zu_viele_pixel_werden_vor_dem_laden_abgelehnt(): void
    {
        $id = $this->artikelAnlegen();
        $this->hochladen($this->bild(Artikelbild::MAX_PIXEL + 1, 1));

        $this->speichern($id);

        $this->assertSame(['bild' => Artikelbild::MELDUNG_TYP], session()->getFlashdata('fehler'));
        $this->assertSame([], $this->dateien());
    }

    public function test_upload_fehler_von_php_ist_feldfehler(): void
    {
        $id = $this->artikelAnlegen();
        service('superglobals')->setFilesArray(['bild' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]);

        $this->speichern($id);

        $this->assertSame(['bild' => Artikelbild::MELDUNG_GROESSE], session()->getFlashdata('fehler'));
    }

    public function test_feldfehler_im_formular_schreibt_kein_bild(): void
    {
        $id = $this->artikelAnlegen();
        $this->hochladen($this->bild(100, 100));

        $this->speichern($id, ['name' => '']);

        $this->assertSame([], $this->dateien());
        $this->assertNull($this->artikel($id)['bild_datei']);
    }

    public function test_neues_bild_ersetzt_altes_und_loescht_die_datei(): void
    {
        $id  = $this->artikelMitBild();
        $alt = $this->artikel($id)['bild_datei'];
        $this->hochladen($this->bild(80, 60));

        $this->speichern($id, ['preis' => '2,00']);

        $artikel = $this->artikel($id);
        $this->assertNotSame($alt, $artikel['bild_datei']);
        $this->assertSame(2, (int) $artikel['bild_version']);
        $this->assertSame(200, (int) $artikel['preis_cent'], 'Felder und Bild in einem Speichern');
        $this->assertSame([$artikel['bild_datei']], $this->dateien());
    }

    public function test_bild_entfernen_loescht_datei_und_protokolliert(): void
    {
        $id = $this->artikelMitBild();

        $this->speichern($id, ['bild_entfernen' => '1'])->assertSessionHas('success', 'Gespeichert.');

        $artikel = $this->artikel($id);
        $this->assertNull($artikel['bild_datei']);
        $this->assertSame(2, (int) $artikel['bild_version']);
        $this->assertSame([], $this->dateien());
        $eintraege = $this->protokoll('bild_entfernt');
        $this->assertCount(1, $eintraege);
        $this->assertSame(['bild_version' => 2], json_decode((string) $eintraege[0]['neu'], true));
    }

    public function test_bild_entfernen_ohne_bild_aendert_nichts(): void
    {
        $id = $this->artikelAnlegen();

        $this->speichern($id, ['bild_entfernen' => '1'])->assertSessionHas('success', 'Keine Änderungen.');
        $this->assertSame([], $this->protokoll('bild_entfernt'));
    }

    public function test_artikel_mit_bild_anlegen(): void
    {
        $kategorie = (int) $this->artikel($this->artikelAnlegen())['kategorie_id'];
        $this->hochladen($this->bild(100, 100));

        $this->alsAngemeldet($this->admin)->post('admin/artikel', [
            'kategorie_id' => (string) $kategorie, 'name' => 'Radler', 'preis' => '1,50', 'einheit' => '0,5 l', 'mindestbestand' => '0',
        ] + $this->csrf())->assertRedirectTo(site_url('admin/stammdaten'));

        $artikel = (new ArtikelModel())->where('name', 'Radler')->first();
        $this->assertNotNull($artikel['bild_datei']);
        $this->assertSame([$artikel['bild_datei']], $this->dateien());
        $this->assertCount(1, $this->protokoll('bild_geaendert'));
    }

    public function test_fehlgeschlagene_transaktion_loescht_das_neue_bild_und_behaelt_das_alte(): void
    {
        $id     = $this->artikelMitBild();
        $alt    = $this->artikel($id)['bild_datei'];
        $bilder = service('artikelbild');
        $pfad   = $this->bild(20, 20);
        $neu    = $bilder->schreibe($pfad, (int) filesize($pfad));

        try {
            $bilder->wechsle($neu, static function () use ($bilder, $id, $neu): ?string {
                $bilder->uebernehme($id, $neu, 1);

                throw new RuntimeException('DB weg');
            });
            $this->fail('Exception erwartet');
        } catch (RuntimeException $e) {
            $this->assertSame('DB weg', $e->getMessage());
        }

        $this->assertSame($alt, $this->artikel($id)['bild_datei']);
        $this->assertSame([$alt], $this->dateien());
    }

    public function test_formular_hat_multipart_vorschau_und_entfernen(): void
    {
        $id = $this->artikelMitBild();

        $antwort = $this->alsAngemeldet($this->admin)->get("admin/artikel/{$id}");

        $antwort->assertSee('enctype="multipart/form-data"', null);
        $antwort->assertSee('accept="image/jpeg,image/png,image/webp"', null);
        $antwort->assertSee("artikelbild/{$id}?v=1-" . substr((string) $this->artikel($id)['bild_datei'], 0, 8), null);
        $antwort->assertSee('name="bild_entfernen"', null);
    }

    // ---- Abruf ----------------------------------------------------------------

    public function test_mitglied_bekommt_das_bild_mit_cache_headern(): void
    {
        $id      = $this->artikelMitBild();
        $mitglied = $this->personAnlegen(['benutzername' => 'mitglied']);

        $antwort = $this->alsAngemeldet($mitglied)->get("artikelbild/{$id}");

        $antwort->assertStatus(200);
        $this->assertSame('image/jpeg', $antwort->response()->getHeaderLine('Content-Type'), 'ohne charset');
        $this->assertSame('private, max-age=31536000, immutable', $antwort->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $antwort->response()->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('inline', $antwort->response()->getHeaderLine('Content-Disposition'));
        $this->assertSame(file_get_contents($this->basis . '/artikelbilder/' . $this->artikel($id)['bild_datei']), $antwort->response()->getBody());
    }

    public function test_anonym_bekommt_403(): void
    {
        $id = $this->artikelMitBild();

        $this->get("artikelbild/{$id}")->assertStatus(403);
    }

    public function test_tablet_bekommt_das_bild(): void
    {
        $id = $this->artikelMitBild();
        $this->tabletCookie();

        $this->get("artikelbild/{$id}")->assertStatus(200);
    }

    public function test_gesperrtes_tablet_bekommt_403(): void
    {
        $id    = $this->artikelMitBild();
        $token = $this->tabletCookie();
        (new GeraetModel())->where('token_hash', hash('sha256', $token))->set(['gesperrt_at' => self::JETZT])->update();

        $this->get("artikelbild/{$id}")->assertStatus(403);
    }

    public function test_artikel_ohne_bild_oder_fehlende_datei_ist_404(): void
    {
        $ohne = $this->artikelAnlegen(['name' => 'Ohne']);
        $weg  = $this->artikelMitBild();
        unlink($this->basis . '/artikelbilder/' . $this->artikel($weg)['bild_datei']);

        foreach ([$ohne, $weg, 999999] as $id) {
            try {
                $this->alsAngemeldet($this->admin)->get("artikelbild/{$id}");
                $this->fail("404 erwartet für {$id}");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_archivierter_artikel_behaelt_sein_bild(): void
    {
        $id = $this->artikelMitBild();
        (new ArtikelModel())->update($id, ['archiviert_at' => self::JETZT]);

        $this->alsAngemeldet($this->admin)->get("artikelbild/{$id}")->assertStatus(200);
    }

    // ---- Kachel -------------------------------------------------------------

    public function test_buchungsseite_zeigt_bild_nur_bei_artikeln_mit_bild(): void
    {
        $mit  = $this->artikelMitBild(['name' => 'Mit Bild']);
        $ohne = $this->artikelAnlegen(['name' => 'Ohne Bild']);

        $antwort = $this->alsAngemeldet($this->admin)->get('buchen');

        $antwort->assertOK();
        $antwort->assertSee('<img class="artikel-bild" src="' . base_url("artikelbild/{$mit}?v=1-" . substr((string) $this->artikel($mit)['bild_datei'], 0, 8)) . '" alt="" loading="lazy">', null);
        $this->assertSame(1, substr_count($antwort->getBody(), 'class="artikel-bild"'));
        $antwort->assertDontSee("artikelbild/{$ohne}", null);
    }

    public function test_tablet_kachel_zeigt_bild(): void
    {
        $id = $this->artikelMitBild();
        $this->tabletCookie();
        $couleur = (new PersonModel())->sammelkontoId('Couleur');

        $antwort = $this->withSession(['csrf_test_name' => 'test-token', 'tablet_konto_id' => $couleur, 'tablet_seit' => self::JETZT])->get('tablet/buchen');

        $antwort->assertOK();
        $antwort->assertSee("artikelbild/{$id}?v=1-" . substr((string) $this->artikel($id)['bild_datei'], 0, 8), null);
    }
}
