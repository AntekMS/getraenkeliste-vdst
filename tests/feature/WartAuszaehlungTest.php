<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\AuszaehlungExport;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Test\TestResponse;
use RuntimeException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartAuszaehlungTest extends DbTestCase
{
    private int $wart;
    private string $basis;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        \Config\Services::resetSingle('einstellungen');
        $this->uhrStellen('2026-10-10 12:00:30');
        $this->wart = $this->personAnlegen();
        $this->rolleGeben($this->wart, 'getraenkewart');
        $this->basis = sys_get_temp_dir() . '/gl-wart-export-' . bin2hex(random_bytes(6)) . '/';
        Services::injectMock('auszaehlungExport', new AuszaehlungExport($this->basis));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basis . '{exporte/,}{,.}*', GLOB_BRACE) ?: [] as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }

        @rmdir($this->basis . 'exporte');
        @rmdir($this->basis);
        parent::tearDown();
    }

    private function exportScheitert(): void
    {
        Services::injectMock('auszaehlungExport', new class ($this->basis) extends AuszaehlungExport {
            public function erzeuge(int $auszaehlungId): string
            {
                throw new RuntimeException('Platte voll');
            }
        });
    }

    private function abschliessen(int $artikel, int $ist = 3): TestResponse
    {
        return $this->sende(['aktion' => 'abschliessen', 'stichtag' => '2026-10-09T08:00', 'ist' => [$artikel => (string) $ist]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function abgeschlossen(): array
    {
        return db_connect()->table('auszaehlungen')->where('status', 'abgeschlossen')->get()->getRowArray();
    }

    public function test_abschliessen_schliesst_ab_und_leitet_zur_liste(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $antwort = $this->abschliessen($a);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlungen'));
        $antwort->assertSessionHas('success', 'Auszählung abgeschlossen.');
        $antwort->assertSessionMissing('error');
        $kopf = $this->abgeschlossen();
        $this->assertSame('2026-10-09 08:00:00', $kopf['stichtag']);
        $this->assertFileExists($this->basis . $kopf['datei_pfad']);
    }

    public function test_abschliessen_ohne_ist_wert_zeigt_feldfehler(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $antwort = $this->sende(['aktion' => 'abschliessen', 'stichtag' => '2026-10-09T08:00', 'ist' => [$a => '']]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
        $antwort->assertSessionHas('error', 'Bitte für jeden Artikel einen Ist-Wert eintragen.');
        $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Bitte einen Ist-Wert eintragen.']);
        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_excel_fehler_zeigt_hinweis_und_datei_neu_erzeugen_hilft(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->exportScheitert();

        $antwort = $this->abschliessen($a);

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlungen'));
        $antwort->assertSessionHas('success', 'Auszählung abgeschlossen.');
        $antwort->assertSessionHas('error', 'Die Excel-Datei konnte nicht erzeugt werden. Bitte „Datei neu erzeugen“ verwenden.');
        $kopf = $this->abgeschlossen();
        $this->assertNull($kopf['datei_pfad']);

        $liste = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlungen');
        $liste->assertOK();
        $liste->assertSee('Datei neu erzeugen');
        $this->assertStringNotContainsString('/download', $liste->getBody());

        // Neu erzeugen scheitert erneut → Fehler, nichts gespeichert.
        $this->alsAngemeldet($this->wart)->post("wart/getraenke/auszaehlungen/{$kopf['id']}/neu-erzeugen", $this->csrf())
            ->assertSessionHas('error', 'Die Excel-Datei konnte nicht erzeugt werden.');

        Services::injectMock('auszaehlungExport', new AuszaehlungExport($this->basis));
        $antwort = $this->alsAngemeldet($this->wart)->post("wart/getraenke/auszaehlungen/{$kopf['id']}/neu-erzeugen", $this->csrf());

        $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlungen'));
        $antwort->assertSessionHas('success', 'Datei neu erzeugt.');
        $pfad = $this->abgeschlossen()['datei_pfad'];
        $this->assertSame('exporte/Auszaehlung_getraenke_2026-10-01_bis_2026-10-09.xlsx', $pfad);
        $this->assertFileExists($this->basis . $pfad);
        $this->seeInDatabase('protokoll', ['person_id' => $this->wart, 'aktion' => 'datei_erzeugt', 'datensatz_id' => $kopf['id']]);

        $liste = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlungen');
        $this->assertStringContainsString("auszaehlungen/{$kopf['id']}/download", $liste->getBody());
    }

    public function test_liste_zeigt_abgeschlossene_neueste_zuerst_mit_genau_einem_primaerknopf(): void
    {
        $alt = $this->auszaehlungAnlegen('2026-10-03 00:00:00', 'abgeschlossen', 'getraenke', [
            'art' => 'start', 'zeitraum_von' => '2026-10-01 00:00:00', 'erstellt_von_id' => $this->wart, 'abgeschlossen_at' => '2026-10-03 00:10:00',
        ]);
        $neu = $this->auszaehlungAnlegen('2026-10-08 10:00:00', 'abgeschlossen', 'getraenke', [
            'zeitraum_von' => '2026-10-03 00:00:00', 'erstellt_von_id' => $this->wart, 'abgeschlossen_at' => '2026-10-08 10:05:00',
        ]);
        $this->auszaehlungAnlegen('2026-10-09 10:00:00', 'entwurf');

        $liste = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlungen');

        $liste->assertOK();
        $body = html_entity_decode($liste->getBody());
        $this->assertSame(1, substr_count($body, 'btn-vdst"'));
        $this->assertStringContainsString('Neue Auszählung', $body);
        $this->assertStringContainsString('03.10.2026 00:00 – 08.10.2026 10:00', $body);
        $this->assertStringContainsString('01.10.2026 00:00 – 03.10.2026 00:00', $body);
        $this->assertLessThan(strpos($body, '01.10.2026 00:00 – 03.10.2026 00:00'), strpos($body, '03.10.2026 00:00 – 08.10.2026 10:00'));
        $this->assertStringContainsString('Start', $body);
        $this->assertStringContainsString('Regulär', $body);
        $this->assertStringContainsString('08.10.2026 10:05', $body);
        $this->assertStringNotContainsString('09.10.2026 10:00', $body, 'Entwürfe erscheinen nicht');
        $this->assertSame(2, substr_count($body, 'Datei neu erzeugen'));
        $this->assertNotSame($alt, $neu);
    }

    public function test_download_liefert_xlsx_mit_dateiname(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->abschliessen($a);
        $kopf = $this->abgeschlossen();

        $antwort = $this->alsAngemeldet($this->wart)->get("wart/getraenke/auszaehlungen/{$kopf['id']}/download");

        $download = $antwort->response();
        $this->assertInstanceOf(DownloadResponse::class, $download);
        $this->assertSame(200, $download->getStatusCode());
        $download->buildHeaders();
        $this->assertStringContainsString('attachment; filename="Auszaehlung_getraenke_2026-10-01_bis_2026-10-09.xlsx"', $download->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $download->getHeaderLine('Content-Type'));
    }

    public function test_download_ohne_datei_oder_ausserhalb_von_exporte_leitet_mit_fehler_zur_liste(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->abschliessen($a);
        $kopf = $this->abgeschlossen();
        file_put_contents($this->basis . 'geheim.txt', 'geheim');

        foreach ([null, 'exporte/fehlt.xlsx', 'exporte/../geheim.txt', '../../../../etc/passwd'] as $pfad) {
            db_connect()->table('auszaehlungen')->where('id', $kopf['id'])->update(['datei_pfad' => $pfad]);

            $antwort = $this->alsAngemeldet($this->wart)->get("wart/getraenke/auszaehlungen/{$kopf['id']}/download");

            $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlungen'));
            $antwort->assertSessionHas('error', 'Die Datei ist nicht vorhanden. Bitte „Datei neu erzeugen“ verwenden.');
        }
    }

    public function test_entwurf_unbekannte_und_fremde_auszaehlungen_sind_404(): void
    {
        $entwurf = $this->auszaehlungAnlegen('2026-10-09 10:00:00', 'entwurf');
        $kiosk   = $this->auszaehlungAnlegen('2026-10-09 10:00:00', 'abgeschlossen', 'kiosk', ['datei_pfad' => 'exporte/x.xlsx']);

        foreach ([$entwurf, $kiosk, 999999] as $id) {
            foreach (['get' => 'download', 'post' => 'neu-erzeugen'] as $methode => $aktion) {
                try {
                    $antwort = $this->alsAngemeldet($this->wart)->{$methode}("wart/getraenke/auszaehlungen/{$id}/{$aktion}", $this->csrf());
                    $this->assertSame(404, $antwort->getStatusCode(), "{$aktion} {$id}");
                } catch (PageNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_kioskwart_und_mitglied_bekommen_403(): void
    {
        $mitglied = $this->personAnlegen();
        $kiosk    = $this->personAnlegen();
        $this->rolleGeben($kiosk, 'kioskwart');

        foreach ([$mitglied, $kiosk] as $person) {
            $this->alsAngemeldet($person)->get('wart/getraenke/auszaehlungen')->assertStatus(403);
            $this->alsAngemeldet($person)->get('wart/getraenke/auszaehlungen/1/download')->assertStatus(403);
            $this->alsAngemeldet($person)->post('wart/getraenke/auszaehlungen/1/neu-erzeugen', $this->csrf())->assertStatus(403);
            $this->alsAngemeldet($person)->post('wart/getraenke/auszaehlung', ['aktion' => 'abschliessen'] + $this->csrf())->assertStatus(403);
        }
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
        $this->assertMatchesRegularExpression('/class="btn btn-vdst"[^>]*data-confirm="Auszählung wirklich abschließen\? Danach sind alle Buchungen bis zum Stichtag eingefroren\."[^>]*>Abschließen</', html_entity_decode($antwort->getBody()));
        $this->assertStringContainsString('class="btn btn-outline-vdst" name="aktion" value="entwurf"', $antwort->getBody());
        $this->assertStringNotContainsString('onclick', $antwort->getBody());
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
        $this->sende(['aktion' => 'loeschen', 'stichtag' => '2026-10-09T08:00', 'ist' => []]);

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
        $meldung = 'Die Bemerkung ist zu lang (höchstens 1000 Zeichen).';
        $antwort->assertSessionHas('fehler', ['bemerkung' => $meldung]);
        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());

        $seite = $this->withSession([...$this->angemeldeteSitzung($this->wart), 'fehler' => ['bemerkung' => $meldung], '__ci_vars' => ['fehler' => 'new']])->get('wart/getraenke/auszaehlung');
        $this->assertStringContainsString('Die Bemerkung ist zu lang (h', $seite->getBody());
        $this->assertStringContainsString('is-invalid', $seite->getBody());
    }
}
