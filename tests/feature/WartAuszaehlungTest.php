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
        $antwort->assertSessionHas('error', 'Bitte für jeden Artikel eintragen, wie viel du gezählt hast.');
        $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Bitte eintragen.']);
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
        $this->assertMatchesRegularExpression('/class="btn btn-vdst"[^>]*data-confirm="Auszählung jetzt abschließen\? Alle gezählten Artikel stimmen\. Danach sind alle Buchungen bis zum Stichtag abgerechnet und können nicht mehr geändert werden\."[^>]*>Abschließen</', html_entity_decode($antwort->getBody()));
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
            $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Bitte eine Zahl ab 0 eintragen.']);
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

    private function istGespeichert(int $artikel): ?string
    {
        $zeile = db_connect()->table('auszaehlung_positionen')->where('artikel_id', $artikel)->get()->getRowArray();

        return $zeile['ist'] === null ? null : (string) $zeile['ist'];
    }

    public function test_kisten_und_einzeln_werden_zu_stueck_umgerechnet(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1, 'gebinde_groesse' => 20]);

        $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => '2'], 'einzeln' => [$a => '3']])
            ->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));

        $this->assertSame('43', $this->istGespeichert($a));

        // Nur Kisten oder nur einzeln reicht; beide leer = noch nicht gezählt.
        $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => '1'], 'einzeln' => [$a => '']]);
        $this->assertSame('20', $this->istGespeichert($a));
        $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => ''], 'einzeln' => [$a => '7']]);
        $this->assertSame('7', $this->istGespeichert($a));
        $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => ''], 'einzeln' => [$a => ' ']]);
        $this->assertNull($this->istGespeichert($a));
    }

    public function test_ungueltiges_kistenfeld_wird_mit_feldfehler_abgelehnt(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1, 'gebinde_groesse' => 20]);

        // Zu groß: 50 000 Kisten × 20 = 1 000 000 > 999 999; einzeln mit 7 Stellen.
        foreach ([['x', '3'], ['-1', ''], ['2', '1,5'], ['1.5', ''], ['50000', ''], ['', '1000000']] as [$kisten, $einzeln]) {
            $antwort = $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => $kisten], 'einzeln' => [$a => $einzeln]]);

            $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
            $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Bitte eine Zahl ab 0 eintragen.']);
        }

        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_kisten_fuer_artikel_ohne_gebinde_werden_abgelehnt(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);

        $antwort = $this->sende(['stichtag' => '2026-10-09T08:00', 'kisten' => [$a => '2'], 'einzeln' => [$a => '']]);

        $antwort->assertSessionHas('fehler', ["ist.{$a}" => 'Bitte eine Zahl ab 0 eintragen.']);
        $this->assertSame(0, db_connect()->table('auszaehlungen')->countAllResults());
    }

    public function test_nach_einem_fehler_erscheinen_die_getippten_kisten_und_einzeln_wieder(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1, 'gebinde_groesse' => 20]);
        $alt = ['get' => [], 'post' => ['aktion' => 'entwurf', 'stichtag' => '2026-10-09T08:00', 'kisten' => [$a => 'x'], 'einzeln' => [$a => '4']]];

        $body = $this->withSession([
            ...$this->angemeldeteSitzung($this->wart), '_ci_old_input' => $alt, 'fehler' => ["ist.{$a}" => 'Bitte eine Zahl ab 0 eintragen.'],
            '__ci_vars' => ['_ci_old_input' => 'new', 'fehler' => 'new'],
        ])->get('wart/getraenke/auszaehlung')->getBody();

        $this->assertMatchesRegularExpression('/name="kisten\[' . $a . '\]"[^>]*value="x"/', $body);
        $this->assertMatchesRegularExpression('/name="einzeln\[' . $a . '\]"[^>]*value="4"/', $body);
        $this->assertStringContainsString('is-invalid', $body);
        $this->assertStringContainsString('data-ungespeichert="1"', $body);
    }

    public function test_entwurf_zeigt_stueckwert_als_kisten_und_einzeln(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1, 'gebinde_groesse' => 20]);
        $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => '43']]);

        $body = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung')->getBody();

        $this->assertMatchesRegularExpression('/name="kisten\[' . $a . '\]"[^>]*value="2"/', $body);
        $this->assertMatchesRegularExpression('/name="einzeln\[' . $a . '\]"[^>]*value="3"/', $body);
        $this->assertStringContainsString('data-gebinde="20"', $body);
        $this->assertStringContainsString('= 43 Stück', html_entity_decode($body));
        $this->assertStringNotContainsString('name="ist[' . $a . ']"', $body);
        $this->assertStringContainsString('aria-label="Kisten: Helles"', $body);
        $this->assertStringContainsString('aria-label="einzeln: Helles"', $body);
    }

    public function test_formular_fuehrt_durch_stichtag_und_zaehlen(): void
    {
        $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1, 'gebinde_groesse' => 20]);

        $antwort = $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung');

        $antwort->assertOK();
        $body = $antwort->getBody();
        $text = html_entity_decode($body);
        $this->assertStringContainsString('1. Stichtag', $text);
        $this->assertStringContainsString('2. Zählen', $text);
        $this->assertStringContainsString('Laut System', $text);
        $this->assertMatchesRegularExpression('/>Bier <span[^>]*>\(2 Artikel\)</', $text);
        $this->assertStringContainsString('data-preis="150"', $body);
        $this->assertStringContainsString('0 von 2 gezählt', $text);
        $this->assertStringContainsString('Zum Abschließen bitte alle Artikel zählen.', $text);
        $this->assertSame(1, substr_count($body, 'btn-vdst"'));
        $this->assertStringNotContainsString('style=', $body);
        $this->assertStringNotContainsString('form-control-sm', $body);
        $this->assertStringContainsString('Neu: Für diesen Artikel gab es noch keine Zählung. Die Abweichung zählt diesmal nicht als Schwund.', $text);
    }

    public function test_neu_hinweis_fehlt_wenn_alle_artikel_schon_gezaehlt_wurden(): void
    {
        $a   = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $alt = $this->auszaehlungAnlegen('2026-10-03 00:00:00', 'abgeschlossen', 'getraenke', ['art' => 'start', 'zeitraum_von' => '2026-10-01 00:00:00']);
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $alt, 'artikel_id' => $a, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
            'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => 0, 'differenz' => 0, 'start' => 1, 'preis_cent' => 150,
        ]);

        $text = html_entity_decode($this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung')->getBody());

        $this->assertStringNotContainsString('Neu: Für diesen Artikel', $text);
        $this->assertStringNotContainsString('>neu<', $text);
    }

    /**
     * Abgeschlossene Auszählung mit Ist 0 für die Artikel → sie sind danach nicht mehr „neu“.
     *
     * @param list<int> $artikel
     */
    private function schonGezaehlt(array $artikel): void
    {
        $alt = $this->auszaehlungAnlegen('2026-10-01 06:00:00', 'abgeschlossen', 'getraenke', ['art' => 'start', 'zeitraum_von' => '2026-10-01 00:00:00']);

        foreach ($artikel as $id) {
            db_connect()->table('auszaehlung_positionen')->insert([
                'auszaehlung_id' => $alt, 'artikel_id' => $id, 'anfangsbestand' => 0, 'lieferungen' => 0, 'schwund_erfasst' => 0,
                'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => 0, 'differenz' => 0, 'start' => 1, 'preis_cent' => 150,
            ]);
        }
    }

    public function test_abweichung_wird_vorgerendert_und_neue_artikel_zaehlen_nicht_in_die_summe(): void
    {
        $a   = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b   = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);
        $neu = $this->artikelAnlegen(['name' => 'Radler', 'bestand_fuehren' => 1]);
        $this->schonGezaehlt([$a, $b]);
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $a, 'art' => 'lieferung', 'menge' => 24, 'person_id' => $this->wart, 'erfolgt_at' => '2026-10-02 10:00:00',
        ]);
        $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => '20', $b => '0', $neu => '5']]);

        $text = html_entity_decode($this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung')->getBody());

        $this->assertMatchesRegularExpression('/badge-status-rot"><i class="bi bi-dash-circle[^"]*" aria-hidden="true"><\/i>4 fehlen</', $text);
        $this->assertMatchesRegularExpression('/badge-status-gruen"><i class="bi bi-check-circle[^"]*" aria-hidden="true"><\/i>stimmt</', $text);
        $this->assertMatchesRegularExpression('/badge-status-amber"><i class="bi bi-plus-circle[^"]*" aria-hidden="true"><\/i>5 zu viel</', $text, 'neuer Artikel zeigt seine Abweichung');
        $this->assertMatchesRegularExpression('/data-neu="1"/', $text);
        $this->assertStringContainsString('3 von 3 gezählt', $text);
        $this->assertStringContainsString('Abweichung: −6,00 €', $text);
        $this->assertStringContainsString('data-confirm="Auszählung jetzt abschließen? 1 Artikel weicht ab (zusammen −6,00 €). Danach sind alle Buchungen bis zum Stichtag abgerechnet und können nicht mehr geändert werden."', $text);
    }

    public function test_bestaetigung_ohne_vollstaendige_zaehlung_spricht_von_gezaehlten_artikeln(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1]);
        $b = $this->artikelAnlegen(['name' => 'Dunkles', 'bestand_fuehren' => 1]);
        $this->schonGezaehlt([$a, $b]);
        $this->sende(['stichtag' => '2026-10-09T08:00', 'ist' => [$a => '0', $b => '']]);

        $text = html_entity_decode($this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung')->getBody());

        $this->assertStringContainsString('data-confirm="Auszählung jetzt abschließen? Alle gezählten Artikel stimmen. Danach', $text);
        $this->assertStringContainsString('keine Abweichung', $text);
        $this->assertStringContainsString('aria-label="Gezählt: Helles"', $text);
        $this->assertStringNotContainsString('data-ungespeichert="1"', $text);
    }

    public function test_array_werte_in_kisten_und_einzeln_fuehren_nicht_zu_einem_serverfehler(): void
    {
        $a = $this->artikelAnlegen(['bestand_fuehren' => 1, 'gebinde_groesse' => 20]);

        foreach ([['kisten' => [$a => ['1']], 'einzeln' => [$a => ['2']]], ['kisten' => 'x', 'einzeln' => ['y' => '1']]] as $daten) {
            $antwort = $this->sende(['stichtag' => '2026-10-09T08:00'] + $daten);

            $antwort->assertRedirectTo(site_url('wart/getraenke/auszaehlung'));
            $this->alsAngemeldet($this->wart)->get('wart/getraenke/auszaehlung')->assertOK();
        }
    }
}
