<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KategorieModel;
use App\Models\PersonModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * Seite „Einkauf“ (Statistik-Spec 2): Bestellliste, Bestand mit Reichweite, Anteile, Verlauf, Lieferhistorie.
 * Feste Uhr 2026-10-10 12:00, Inbetriebnahme 2026-09-01 → Grundlage 28 Tage, Fenster (2026-09-12 12:00, jetzt].
 *
 * @internal
 */
final class WartEinkaufTest extends DbTestCase
{
    private int $wart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbetriebnahmeSetzen('2026-09-01 00:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->wart = $this->mitRolle('getraenkewart', 'Wart Willi');
    }

    private function mitRolle(string $rolle, string $anzeigename = 'Person'): int
    {
        $id = $this->personAnlegen(['anzeigename' => $anzeigename]);
        $this->rolleGeben($id, $rolle);

        return $id;
    }

    private function lieferung(int $artikel, int $menge, string $zeit = '2026-09-02 10:00:00', ?int $preis = null): void
    {
        db_connect()->table('bestandsbewegungen')->insert([
            'artikel_id' => $artikel, 'art' => 'lieferung', 'menge' => $menge, 'einkaufspreis_cent' => $preis,
            'person_id' => $this->wart, 'erfolgt_at' => $zeit,
        ]);
    }

    private function buchung(int $artikel, int $menge, ?int $konto = null, string $zeit = '2026-10-05 10:00:00'): void
    {
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => bin2hex(random_bytes(18)), 'konto_id' => $konto ?? $this->wart, 'artikel_id' => $artikel,
            'menge' => $menge, 'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_at' => $zeit, 'bestandswirksam' => 1,
        ]);
    }

    private function seite(string $query = ''): TestResponse
    {
        return $this->alsAngemeldet($this->wart)->get('wart/getraenke/einkauf' . $query);
    }

    /** Roher Inhalt des Abschnitts mit der CSS-Klasse (bis zum schließenden </section>). */
    private function roh(string $body, string $klasse): string
    {
        $this->assertSame(1, preg_match('/<section[^>]*class="[^"]*\b' . $klasse . '\b[^"]*"[^>]*>(.*?)<\/section>/s', $body, $treffer), "Abschnitt {$klasse} fehlt");

        return $treffer[1];
    }

    /** Abschnitt als Text (die Testantwort ist DOM-serialisiert: Umlaute als Entities). */
    private function abschnitt(string $body, string $klasse): string
    {
        return html_entity_decode($this->roh($body, $klasse), ENT_QUOTES | ENT_HTML5);
    }

    public function test_bestellliste_mit_vorschlag_in_kisten(): void
    {
        // 56 verkauft in 28 Tagen → 2/Tag; Bestand 70 − 56 = 14; Bedarf 2 × 30 − 14 = 46 → 3 Kisten à 20.
        $a = $this->artikelAnlegen(['name' => 'Helles', 'gebinde_groesse' => 20]);
        $this->lieferung($a, 70, preis: 85);
        $this->buchung($a, 28);
        $this->buchung($a, 28);

        $seite = $this->seite();
        $seite->assertStatus(200);
        $liste = $this->abschnitt($seite->getBody(), 'bestellliste');

        $this->assertStringContainsString('Helles', $liste);
        $this->assertStringContainsString('3 Kisten (= 60 Stück)', $liste);
        $this->assertStringContainsString('0,85 €', $liste);
        $this->assertStringContainsString('data-print', $liste);
        $seite->assertSee('Grundlage: Verbrauch der letzten 28 Tage, Reichweite 30 Tage (änderbar in den Einstellungen).');
        $seite->assertDontSee('Grundlage erst');
    }

    public function test_artikel_ohne_bedarf_nur_in_bestandstabelle(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Mineralwasser']);
        $this->lieferung($a, 100);

        $body = $this->seite()->getBody();

        $this->assertStringNotContainsString('Mineralwasser', $this->abschnitt($body, 'bestellliste'));
        $bestand = $this->abschnitt($body, 'bestand-tabelle');
        $this->assertStringContainsString('Mineralwasser', $bestand);
        $this->assertMatchesRegularExpression('/data-label="reicht noch ca\."[^>]*>\s*—\s*</', $bestand);
        $this->assertMatchesRegularExpression('/data-label="Ø Verbrauch\/Tag"[^>]*>\s*0,0\s*</', $bestand);
    }

    public function test_reichweite_und_tagesverbrauch(): void
    {
        // 28 verkauft → 1,0/Tag; Bestand 42 − 28 = 14 → reicht noch ca. 14 Tage.
        $a = $this->artikelAnlegen(['name' => 'Radler']);
        $this->lieferung($a, 42);
        $this->buchung($a, 28);

        $bestand = $this->abschnitt($this->seite()->getBody(), 'bestand-tabelle');

        $this->assertStringContainsString('reicht noch ca.', $bestand);
        $this->assertMatchesRegularExpression('/data-label="reicht noch ca\."[^>]*>\s*14 Tage\s*</', $bestand);
        $this->assertMatchesRegularExpression('/data-label="Ø Verbrauch\/Tag"[^>]*>\s*1,0\s*</', $bestand);
    }

    public function test_leerer_bestand_zeigt_leer(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Cola']);
        $this->buchung($a, 3);

        $body = $this->seite()->getBody();

        $this->assertMatchesRegularExpression('/data-label="reicht noch ca\."[^>]*>\s*leer\s*</', $this->abschnitt($body, 'bestand-tabelle'));
        $this->assertStringContainsString('Der Bestand ist negativ', $body);
    }

    public function test_anteile_couleur_und_bund(): void
    {
        $personen = new PersonModel();
        $a        = $this->artikelAnlegen();
        $this->buchung($a, 2);
        $this->buchung($a, 1, $personen->sammelkontoId('Couleur'));
        $this->buchung($a, 1, $personen->sammelkontoId('Bund'));
        // 28 Tage davor: nur Mitglieder.
        $this->buchung($a, 4, null, '2026-09-01 10:00:00');

        $anteile = $this->abschnitt($this->seite()->getBody(), 'anteile');

        $this->assertStringContainsString('Letzte 28 Tage', $anteile);
        $this->assertStringContainsString('Couleur', $anteile);
        $this->assertStringContainsString('Bund', $anteile);
        $this->assertStringContainsString('50,0 %', $anteile);
        $this->assertStringContainsString('25,0 %', $anteile);
        $this->assertStringContainsString('100,0 %', $anteile);
        $this->assertStringNotContainsString('Letzter abgeschlossener Zeitraum', $anteile);
    }

    public function test_anteile_letzter_abgeschlossener_zeitraum(): void
    {
        $a = $this->artikelAnlegen();
        $this->auszaehlungAnlegen('2026-09-20 18:00:00', werte: ['zeitraum_von' => '2026-09-01 00:00:00']);
        $this->auszaehlungAnlegen('2026-10-01 18:00:00', werte: ['zeitraum_von' => '2026-09-20 18:00:00']);
        $this->buchung($a, 3, (new PersonModel())->sammelkontoId('Bund'), '2026-09-25 10:00:00');

        $anteile = $this->abschnitt($this->seite()->getBody(), 'anteile');

        $this->assertStringContainsString('Letzter abgeschlossener Zeitraum', $anteile);
        $this->assertStringContainsString('20.09.2026 – 01.10.2026', $anteile);
        $this->assertStringContainsString('100,0 %', $anteile);
    }

    public function test_frische_installation_ohne_daten(): void
    {
        $this->inbetriebnahmeSetzen('2026-10-05 08:00:00');

        $seite = $this->seite();
        $seite->assertStatus(200);
        $body = $seite->getBody();

        $this->assertStringContainsString('Grundlage erst 5 Tage', $body);
        $this->assertStringContainsString('Gerade muss nichts bestellt werden.', $body);
        $this->assertStringContainsString('—', $this->abschnitt($body, 'anteile'));
        $this->assertStringContainsString('Noch keine Lieferungen erfasst.', $body);
        $this->assertStringNotContainsString('data-print', $this->abschnitt($body, 'bestellliste'));
    }

    public function test_verlauf_tabelle_und_diagramm_container(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Helles']);
        $this->buchung($a, 7);

        $body    = $this->seite()->getBody();
        $verlauf = $this->abschnitt($body, 'verlauf');

        $this->assertStringContainsString('KW 41 (bis heute)', $verlauf);
        $this->assertStringContainsString('KW 30', $verlauf);
        $this->assertStringContainsString('<canvas class="js-diagramm"', $verlauf);
        // Attributwert im rohen Abschnitt (die Testantwort setzt ihn je nach Inhalt in '…' oder "…").
        $this->assertSame(1, preg_match('/data-diagramm=(["\'])(.*?)\1/s', $this->roh($body, 'verlauf'), $treffer));
        $daten = json_decode(html_entity_decode($treffer[2], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(12, $daten['labels']);
        $this->assertSame('Bier', $daten['reihen'][0]['name']);
        $this->assertSame(7, $daten['reihen'][0]['werte'][11]);
        $this->assertStringContainsString('<option value="alle" selected', $verlauf);
    }

    public function test_ansicht_artikel(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Helles']);

        $verlauf = $this->abschnitt($this->seite('?ansicht=artikel:' . $a)->getBody(), 'verlauf');

        $this->assertStringContainsString('<option value="artikel:' . $a . '" selected', $verlauf);
    }

    public function test_manipulierte_ansicht_faellt_auf_standard(): void
    {
        $this->artikelAnlegen(['name' => 'Helles']);
        $kategorie = (int) (new KategorieModel())->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks'], true);
        $kiosk     = $this->artikelAnlegen(['name' => 'Kartoffelchips', 'kategorie_id' => $kategorie]);

        foreach (['?ansicht[]=x', '?ansicht=artikel:' . $kiosk, '?ansicht=kategorie:abc', '?ansicht=kategorie:' . $kategorie] as $query) {
            $seite = $this->seite($query);
            $seite->assertStatus(200);
            $body = $seite->getBody();
            $this->assertStringContainsString('<option value="alle" selected', $body, $query);
            $this->assertStringNotContainsString('Kartoffelchips', $body, $query);
            $this->assertStringNotContainsString('Snacks', $body, $query);
        }
    }

    public function test_lieferhistorie(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Helles']);
        $this->lieferung($a, 24, '2026-10-03 17:30:00', 90);

        $historie = $this->abschnitt($this->seite()->getBody(), 'lieferhistorie');

        $this->assertStringContainsString('03.10.2026 17:30', $historie);
        $this->assertStringContainsString('24 × Helles', $historie);
        $this->assertStringContainsString('0,90 €', $historie);
        $this->assertStringContainsString('Wart Willi', $historie);
    }

    public function test_genau_ein_primaerknopf_und_keine_inline_styles(): void
    {
        $a = $this->artikelAnlegen(['name' => 'Helles', 'gebinde_groesse' => 20]);
        $this->buchung($a, 28);

        $body = $this->seite()->getBody();

        $this->assertSame(1, preg_match_all('/class="[^"]*\bbtn-vdst\b[^"]*"/', $body));
        $this->assertStringContainsString('Lieferung erfassen', $body);
        $this->assertStringContainsString('Schwund/Korrektur', $body);
        $this->assertStringContainsString('Bestellliste drucken', $body);
        $this->assertStringNotContainsString('style="', $body);
        $this->assertStringNotContainsString('onclick', $body);
    }

    public function test_bestand_leitet_dauerhaft_auf_einkauf_um(): void
    {
        $antwort = $this->alsAngemeldet($this->wart)->get('wart/getraenke/bestand');

        $antwort->assertRedirectTo(site_url('wart/getraenke/einkauf'));
        $this->assertSame(301, $antwort->response()->getStatusCode());
    }

    public function test_navigation_zeigt_einkauf(): void
    {
        $body = $this->seite()->getBody();

        $this->assertMatchesRegularExpression('#class="app-nav-link app-nav-sub active" href="[^"]*wart/getraenke/einkauf"#', $body);
        $this->assertStringNotContainsString('wart/getraenke/bestand"', $body);
    }

    public function test_mitglied_bekommt_403(): void
    {
        $this->alsAngemeldet($this->personAnlegen())->get('wart/getraenke/einkauf')->assertStatus(403);
    }

    public function test_getraenkewart_auf_kiosk_403(): void
    {
        $this->alsAngemeldet($this->wart)->get('wart/kiosk/einkauf')->assertStatus(403);
    }

    public function test_kiosk_ist_404_auch_fuer_admin(): void
    {
        $admin = $this->mitRolle('admin');

        foreach (['wart/kiosk/einkauf', 'wart/kiosk/bestand'] as $pfad) {
            try {
                $status = $this->alsAngemeldet($admin)->get($pfad)->getStatusCode();
            } catch (PageNotFoundException) {
                $status = 404;
            }

            $this->assertSame(404, $status, $pfad);
        }
    }
}
