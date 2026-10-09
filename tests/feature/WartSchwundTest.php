<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KategorieModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * Seite „Schwund“ (Statistik-Spec 3). Feste Uhr 2026-10-10 12:00, Inbetriebnahme 2026-09-01.
 *
 * @internal
 */
final class WartSchwundTest extends DbTestCase
{
    private int $wart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbetriebnahmeSetzen('2026-09-01 00:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->wart = $this->personAnlegen(['anzeigename' => 'Wart Willi']);
        $this->rolleGeben($this->wart, 'getraenkewart');
    }

    private function seite(string $query = '', string $bereich = 'getraenke'): TestResponse
    {
        return $this->alsAngemeldet($this->wart)->get('wart/' . $bereich . '/schwund' . $query);
    }

    private function position(int $auszaehlung, int $artikel, int $differenz, int $preis, int $verkauft, int $start = 0): void
    {
        db_connect()->table('auszaehlung_positionen')->insert([
            'auszaehlung_id' => $auszaehlung, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0,
            'schwund_erfasst' => 0, 'korrekturen' => 0, 'verkauft' => $verkauft, 'soll' => 0, 'ist' => $differenz,
            'differenz' => $differenz, 'start' => $start, 'preis_cent' => $preis,
        ]);
    }

    private function schwund(int $artikel, int $menge, string $zeit): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => 'schwund', 'menge' => $menge, 'person_id' => $this->wart, 'erfolgt_at' => $zeit,
        ]);
    }

    /** Text der Seite (die Testantwort ist DOM-serialisiert: Umlaute als Entities). */
    private function text(TestResponse $antwort): string
    {
        return html_entity_decode($antwort->getBody(), ENT_QUOTES | ENT_HTML5);
    }

    private function abschnitt(TestResponse $antwort, string $klasse): string
    {
        $this->assertSame(1, preg_match('/<section[^>]*class="[^"]*\b' . $klasse . '\b[^"]*"[^>]*>(.*?)<\/section>/s', $this->text($antwort), $treffer), "Abschnitt {$klasse} fehlt");

        return $treffer[1];
    }

    /**
     * Zwei reguläre Zeiträume: Quote 5,0 % (1 ÷ 20) → 17,5 % ((3 erfasst + 4 unerklärt) ÷ 40) = +12,5 Prozentpunkte.
     *
     * @return array{0: int, 1: int}
     */
    private function zweiZeitraeume(): array
    {
        $a = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $b = $this->artikelAnlegen(['name' => 'Pils', 'preis_cent' => 200]);

        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z0, $a, -1, 150, 20);
        $z1 = $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-10 12:00:00']);
        $this->position($z1, $a, -4, 150, 40);
        $this->position($z1, $b, 3, 200, 0);
        $this->schwund($a, -2, '2026-09-20 10:00:00');
        $this->schwund($b, -1, '2026-09-21 10:00:00');
        $this->schwund($a, -1, '2026-10-05 10:00:00'); // laufender Zeitraum

        return [$a, $b];
    }

    /** Text einer Kachel: von ihrem Label bis zum nächsten Label bzw. Ende. */
    private function kachel(string $kennzahlen, string $label): string
    {
        $this->assertSame(1, preg_match('/stat-tile-label">' . preg_quote($label, '/') . '<\/div>(.*?)(?=stat-tile-label|\z)/s', $kennzahlen, $treffer), "Kachel {$label} fehlt");

        return $treffer[1];
    }

    /** @return array<string, mixed> */
    private function diagrammDaten(TestResponse $antwort): array
    {
        $this->assertSame(1, preg_match('/data-diagramm=(["\'])(.*?)\1/s', $antwort->getBody(), $treffer));

        return json_decode(html_entity_decode($treffer[2], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_kacheln_zeigen_label_und_wert_zusammen(): void
    {
        $this->zweiZeitraeume();

        $kennzahlen = $this->abschnitt($this->seite(), 'kennzahlen');

        $erfasst = $this->kachel($kennzahlen, 'Erfasster Schwund');
        $this->assertStringContainsString('5,00 €', $erfasst);
        $this->assertStringContainsString('3 Stück', $erfasst);
        $unerklaert = $this->kachel($kennzahlen, 'Unerklärte Differenz');
        $this->assertStringContainsString('6,00 €', $unerklaert);
        $this->assertStringContainsString('Überschuss: 6,00 € (3 Stück)', $unerklaert);
        $quote = $this->kachel($kennzahlen, 'Schwundquote');
        $this->assertStringContainsString('17,5 %', $quote);
        $this->assertStringContainsString('schlechter: +12,5 Prozentpunkte', $quote);
    }

    public function test_verlauf_reihenfolge_tabelle_neueste_zuerst_diagramm_aelteste_zuerst(): void
    {
        $this->zweiZeitraeume();

        $seite   = $this->seite();
        $tabelle = $this->abschnitt($seite, 'verlauf');
        $tabelle = substr($tabelle, (int) strpos($tabelle, '<table')); // ohne das Diagramm-JSON davor

        $this->assertLessThan(strpos($tabelle, '01.09.2026 – 10.09.2026'), strpos($tabelle, '10.09.2026 – 01.10.2026'));

        $daten = $this->diagrammDaten($seite);
        $this->assertSame(['01.09.2026 – 10.09.2026', '10.09.2026 – 01.10.2026'], $daten['labels']);
    }

    public function test_start_zeitraum_im_diagramm_mit_zusatz(): void
    {
        $a  = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['art' => 'start', 'zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z0, $a, -3, 150, 10);

        $daten = $this->diagrammDaten($this->seite());

        $this->assertSame(['01.09.2026 – 10.09.2026 (Start)'], $daten['labels']);
    }

    public function test_unveraenderte_quote_zeigt_unveraendert(): void
    {
        $a  = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z0, $a, -2, 150, 40);
        $z1 = $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-10 12:00:00']);
        $this->position($z1, $a, -2, 150, 40);

        $quote = $this->kachel($this->abschnitt($this->seite(), 'kennzahlen'), 'Schwundquote');

        $this->assertStringContainsString('unverändert zum Zeitraum davor', $quote);
        $this->assertStringNotContainsString('bi-arrow', $quote);
    }

    public function test_top_artikel_auf_zehn_begrenzt(): void
    {
        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);

        for ($i = 1; $i <= 12; $i++) {
            $this->position($z0, $this->artikelAnlegen(['name' => 'Sorte ' . $i, 'preis_cent' => 100 + $i]), -1, 100 + $i, 10);
        }

        $top = $this->abschnitt($this->seite(), 'top-artikel');

        $this->assertSame(10, substr_count($top, 'schwund?artikel='));
    }

    public function test_leerer_zustand_mit_hinweis_und_laufender_zeile(): void
    {
        $seite = $this->seite();

        $seite->assertStatus(200);
        $text = $this->text($seite);
        $this->assertStringContainsString('Noch keine abgeschlossene Auszählung – Schwund wird nach der ersten Auszählung ausgewertet.', $text);
        $this->assertStringContainsString('Seit dem letzten Abschluss bereits erfasst: 0 Stück / 0,00 €', $text);
    }

    public function test_kennzahlen_mit_vergleich_pfeil_und_verlauf(): void
    {
        $this->zweiZeitraeume();

        $seite = $this->seite();
        $seite->assertStatus(200);
        $kennzahlen = $this->abschnitt($seite, 'kennzahlen');

        $this->assertStringContainsString('10.09.2026 – 01.10.2026', $kennzahlen);
        $this->assertStringContainsString('5,00 €', $kennzahlen);  // erfasst: 2 × 1,50 + 1 × 2,00
        $this->assertStringContainsString('6,00 €', $kennzahlen);  // unerklärt: 4 × 1,50; Überschuss 3 × 2,00
        $this->assertStringContainsString('Überschuss: 6,00 € (3 Stück)', $kennzahlen);
        $this->assertStringContainsString('17,5 %', $kennzahlen);
        $this->assertStringContainsString('bi-arrow-up', $kennzahlen);
        $this->assertStringContainsString('schlechter: +12,5 Prozentpunkte', $kennzahlen);

        $verlauf = $this->abschnitt($seite, 'verlauf');
        $this->assertStringContainsString('10.09.2026 – 01.10.2026', $verlauf);
        $this->assertStringContainsString('01.09.2026 – 10.09.2026', $verlauf);
        $this->assertStringContainsString('saeulen-gestapelt', $verlauf);
        $this->assertStringContainsString('Seit dem letzten Abschluss bereits erfasst: 1 Stück / 1,50 €', $this->text($seite));
        $this->assertSame(0, substr_count($this->text($seite), 'btn-vdst'));
    }

    public function test_besserer_wert_zeigt_pfeil_nach_unten(): void
    {
        $a  = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z0, $a, -4, 150, 40);
        $z1 = $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-09-10 12:00:00']);
        $this->position($z1, $a, -1, 150, 40);

        $kennzahlen = $this->abschnitt($this->seite(), 'kennzahlen');

        $this->assertStringContainsString('bi-arrow-down', $kennzahlen);
        $this->assertStringContainsString('besser: −7,5 Prozentpunkte', $kennzahlen);
    }

    public function test_start_auszaehlung_zeigt_nullwerte_und_hinweis(): void
    {
        $a  = $this->artikelAnlegen(['name' => 'Helles', 'preis_cent' => 150]);
        $z0 = $this->auszaehlungAnlegen('2026-09-10 12:00:00', 'abgeschlossen', 'getraenke', ['art' => 'start', 'zeitraum_von' => '2026-09-01 00:00:00']);
        $this->position($z0, $a, -3, 150, 10);
        $this->schwund($a, -1, '2026-09-05 10:00:00');

        $seite      = $this->seite();
        $kennzahlen = $this->abschnitt($seite, 'kennzahlen');

        $this->assertStringContainsString('Start – noch kein Schwund auswertbar', $kennzahlen);
        $this->assertSame(3, substr_count($kennzahlen, '0,00 €')); // erfasst, unerklärt, Überschuss
        $this->assertStringNotContainsString('bi-arrow', $kennzahlen);
        $this->assertStringContainsString('Start', $this->abschnitt($seite, 'verlauf'));
        $this->assertStringContainsString('—', $this->abschnitt($seite, 'verlauf'));
    }

    public function test_top_artikel_sortiert_mit_links(): void
    {
        [$a, $b] = $this->zweiZeitraeume();

        $top = $this->abschnitt($this->seite(), 'top-artikel');

        // Helles: 2 erfasst + 5 unerklärt (4 + 1) = 3,00 + 7,50 = 10,50 €; Pils: 1 erfasst = 2,00 €
        $this->assertLessThan(strpos($top, 'Pils'), strpos($top, 'Helles'));
        $this->assertStringContainsString('schwund?artikel=' . $a, $top);
        $this->assertStringContainsString('schwund?artikel=' . $b, $top);
        $this->assertStringContainsString('10,50 €', $top);
        $this->assertStringContainsString('2,00 €', $top);
    }

    public function test_artikel_verlauf_wird_angezeigt(): void
    {
        [$a] = $this->zweiZeitraeume();

        $top = $this->abschnitt($this->seite('?artikel=' . $a), 'top-artikel');

        $this->assertStringContainsString('Verlauf: Helles', $top);
        $this->assertStringContainsString('10.09.2026 – 01.10.2026', $top);
    }

    public function test_ungueltige_und_fremde_artikel_werden_ignoriert(): void
    {
        $this->zweiZeitraeume();
        $kategorie = (int) (new KategorieModel())->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks'], true);
        $kiosk     = $this->artikelAnlegen(['kategorie_id' => $kategorie, 'name' => 'Riegel']);

        foreach (['?artikel=' . $kiosk, '?artikel[]=1', '?artikel=abc', '?artikel=999999', '?artikel=-1', '?artikel='] as $query) {
            $seite = $this->seite($query);
            $seite->assertStatus(200);
            $this->assertStringNotContainsString('Verlauf: ', $this->text($seite), $query);
            $this->assertStringNotContainsString('Riegel', $this->text($seite), $query);
        }
    }

    public function test_mitglied_403_und_kiosk_404(): void
    {
        $this->alsAngemeldet($this->personAnlegen())->get('wart/getraenke/schwund')->assertStatus(403);

        $admin = $this->personAnlegen();
        $this->rolleGeben($admin, 'admin');

        try {
            $status = $this->alsAngemeldet($admin)->get('wart/kiosk/schwund')->getStatusCode();
        } catch (PageNotFoundException) {
            $status = 404;
        }

        $this->assertSame(404, $status);
    }

    public function test_chartjs_und_diagrammtyp_auf_der_schwundseite(): void
    {
        $this->zweiZeitraeume();

        $body = $this->seite()->getBody();

        $this->assertStringContainsString('chart.js@4.5.1/dist/chart.umd.min.js', $body);
        $this->assertStringContainsString('js/statistik.js?v=2', $body);
        $this->assertSame(1, preg_match('/data-diagramm=(["\'])(.*?)\1/s', $body, $treffer));
        $daten = json_decode(html_entity_decode($treffer[2], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('saeulen-gestapelt', $daten['typ']);
    }
}
