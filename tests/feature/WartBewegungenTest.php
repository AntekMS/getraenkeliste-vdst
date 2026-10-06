<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartBewegungenTest extends DbTestCase
{
    private int $wart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->wart = $this->personAnlegen();
        $this->rolleGeben($this->wart, 'getraenkewart');
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function sende(string $pfad, array $daten): \CodeIgniter\Test\TestResponse
    {
        return $this->alsAngemeldet($this->wart)->post($pfad, $daten + $this->csrf());
    }

    private function zeilen(): array
    {
        return db_connect()->table('bestandsbewegungen')->orderBy('id')->get()->getResultArray();
    }

    public function test_lieferung_rechnet_kisten_und_stueck_um_und_speichert_preis(): void
    {
        $a = $this->artikelAnlegen(['gebinde_groesse' => 20]);

        $antwort = $this->sende('wart/getraenke/lieferung', [
            'zeilen' => [['artikel_id' => $a, 'kisten' => '2', 'stueck' => '3', 'einkaufspreis' => '0,85']],
            'bemerkung' => 'Getränkemarkt',
        ]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/bestand'));
        $bew = $this->zeilen();
        $this->assertCount(1, $bew);
        $this->assertSame('lieferung', $bew[0]['art']);
        $this->assertSame(43, (int) $bew[0]['menge']);
        $this->assertSame(85, (int) $bew[0]['einkaufspreis_cent']);
        $this->assertSame('Getränkemarkt', $bew[0]['bemerkung']);
        $this->assertSame('2026-10-10 12:00:00', $bew[0]['erfolgt_at']);
        $this->assertSame(43, service('bestand')->einzeln($a));
    }

    public function test_lieferung_schreibt_protokoll_je_bewegung(): void
    {
        $a = $this->artikelAnlegen();
        $b = $this->artikelAnlegen(['name' => 'Dunkles']);

        $this->sende('wart/getraenke/lieferung', ['zeilen' => [
            ['artikel_id' => $a, 'kisten' => '0', 'stueck' => '5', 'einkaufspreis' => ''],
            ['artikel_id' => $b, 'kisten' => '0', 'stueck' => '7', 'einkaufspreis' => ''],
        ]]);

        $eintraege = db_connect()->table('protokoll')->where('aktion', 'lieferung')->get()->getResultArray();
        $this->assertCount(2, $eintraege);
        $this->assertSame('bestandsbewegungen', $eintraege[0]['tabelle']);
        $this->assertSame((int) $this->zeilen()[0]['id'], (int) $eintraege[0]['datensatz_id']);
        $this->assertStringContainsString('"menge": 5', $eintraege[0]['neu']);
    }

    public function test_kisten_ohne_gebinde_ist_feldfehler_und_alles_oder_nichts(): void
    {
        $ohne = $this->artikelAnlegen();
        $gut  = $this->artikelAnlegen(['name' => 'Dunkles', 'gebinde_groesse' => 10]);

        $antwort = $this->sende('wart/getraenke/lieferung', ['zeilen' => [
            ['artikel_id' => $gut, 'kisten' => '1', 'stueck' => '0', 'einkaufspreis' => ''],
            ['artikel_id' => $ohne, 'kisten' => '2', 'stueck' => '0', 'einkaufspreis' => ''],
        ]]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/lieferung'));
        $this->assertSame([], $this->zeilen());
        $fehler = session()->getFlashdata('fehler');
        $this->assertArrayHasKey('zeilen.1.kisten', $fehler);
        $this->assertArrayNotHasKey('zeilen.0.kisten', $fehler);
    }

    public function test_leere_zeilen_werden_uebersprungen_aber_mindestens_eine_noetig(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/lieferung', ['zeilen' => [['artikel_id' => $a, 'kisten' => '0', 'stueck' => '0', 'einkaufspreis' => '']]])
            ->assertRedirectTo(site_url('wart/getraenke/lieferung'));
        $this->assertSame([], $this->zeilen());

        $this->sende('wart/getraenke/lieferung', ['zeilen' => [
            ['artikel_id' => '', 'kisten' => '', 'stueck' => '', 'einkaufspreis' => ''],
            ['artikel_id' => $a, 'kisten' => '', 'stueck' => '4', 'einkaufspreis' => ''],
        ]])->assertRedirectTo(site_url('wart/getraenke/bestand'));
        $this->assertCount(1, $this->zeilen());
    }

    public function test_artikel_aus_anderem_bereich_oder_archiviert_wird_abgelehnt(): void
    {
        $kioskKat = (int) (new \App\Models\KategorieModel())->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks'], true);
        $fremd    = $this->artikelAnlegen(['kategorie_id' => $kioskKat, 'name' => 'Riegel']);
        $archiv   = $this->artikelAnlegen(['name' => 'Alt', 'archiviert_at' => '2026-10-01 00:00:00']);
        $ohneBest = $this->artikelAnlegen(['name' => 'Ohne', 'bestand_fuehren' => 0]);

        foreach ([$fremd, $archiv, $ohneBest] as $id) {
            $this->sende('wart/getraenke/lieferung', ['zeilen' => [['artikel_id' => $id, 'kisten' => '0', 'stueck' => '1', 'einkaufspreis' => '']]])
                ->assertRedirectTo(site_url('wart/getraenke/lieferung'));
            $this->assertArrayHasKey('zeilen.0.artikel_id', session()->getFlashdata('fehler'));
        }

        $this->assertSame([], $this->zeilen());
    }

    public function test_ungueltiger_preis_ist_feldfehler(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/lieferung', ['zeilen' => [['artikel_id' => $a, 'kisten' => '0', 'stueck' => '1', 'einkaufspreis' => 'abc']]]);

        $this->assertArrayHasKey('zeilen.0.einkaufspreis', session()->getFlashdata('fehler'));
        $this->assertSame([], $this->zeilen());
    }

    public function test_schwund_wird_negativ_gespeichert(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/bewegung', ['art' => 'schwund', 'artikel_id' => $a, 'menge' => '3', 'bemerkung' => 'Flasche zerbrochen'])
            ->assertRedirectTo(site_url('wart/getraenke/bestand'));

        $bew = $this->zeilen();
        $this->assertSame('schwund', $bew[0]['art']);
        $this->assertSame(-3, (int) $bew[0]['menge']);
        $this->assertSame('Flasche zerbrochen', $bew[0]['bemerkung']);
        $this->assertSame(1, db_connect()->table('protokoll')->where('aktion', 'schwund')->countAllResults());
    }

    public function test_ohne_bemerkung_abgelehnt(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/bewegung', ['art' => 'schwund', 'artikel_id' => $a, 'menge' => '3', 'bemerkung' => '  '])
            ->assertRedirectTo(site_url('wart/getraenke/bewegung'));

        $this->assertSame('Bitte eine Bemerkung angeben.', session()->getFlashdata('fehler')['bemerkung']);
        $this->assertSame([], $this->zeilen());
    }

    public function test_korrektur_plus_und_minus_aber_nicht_null(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/bewegung', ['art' => 'korrektur', 'artikel_id' => $a, 'menge' => '-2', 'bemerkung' => 'Zählfehler']);
        $this->sende('wart/getraenke/bewegung', ['art' => 'korrektur', 'artikel_id' => $a, 'menge' => '5', 'bemerkung' => 'Zählfehler']);
        $this->sende('wart/getraenke/bewegung', ['art' => 'korrektur', 'artikel_id' => $a, 'menge' => '-0', 'bemerkung' => 'Zählfehler']);
        $this->assertArrayHasKey('menge', session()->getFlashdata('fehler'));

        $this->assertSame([-2, 5], array_map(static fn (array $z): int => (int) $z['menge'], $this->zeilen()));
        $this->assertSame(3, service('bestand')->einzeln($a));
    }

    public function test_schwund_braucht_positive_menge_und_gueltigen_artikel(): void
    {
        $a = $this->artikelAnlegen();

        $this->sende('wart/getraenke/bewegung', ['art' => 'schwund', 'artikel_id' => $a, 'menge' => '-3', 'bemerkung' => 'x']);
        $this->assertArrayHasKey('menge', session()->getFlashdata('fehler'));

        $this->sende('wart/getraenke/bewegung', ['art' => 'schwund', 'artikel_id' => '999999', 'menge' => '3', 'bemerkung' => 'x']);
        $this->assertArrayHasKey('artikel_id', session()->getFlashdata('fehler'));

        $this->sende('wart/getraenke/bewegung', ['art' => 'lieferung', 'artikel_id' => $a, 'menge' => '3', 'bemerkung' => 'x']);
        $this->assertSame([], $this->zeilen());
    }

    public function test_eingefrorener_zeitraum_wird_abgelehnt(): void
    {
        $a = $this->artikelAnlegen();
        $this->auszaehlungAnlegen('2026-10-10 12:00:00');

        $this->sende('wart/getraenke/bewegung', ['art' => 'schwund', 'artikel_id' => $a, 'menge' => '1', 'bemerkung' => 'x'])
            ->assertRedirectTo(site_url('wart/getraenke/bewegung'));

        $this->assertSame('Dieser Zeitraum ist abgeschlossen.', session()->getFlashdata('error'));
        $this->assertSame([], $this->zeilen());
    }

    public function test_formulare_sind_fuer_wart_sichtbar_und_fuer_mitglied_verboten(): void
    {
        $this->artikelAnlegen(['name' => 'Helles']);

        $lieferung = $this->alsAngemeldet($this->wart)->get('wart/getraenke/lieferung');
        $lieferung->assertStatus(200);
        $lieferung->assertSee('Helles');
        $lieferung->assertSee('Zeile hinzufügen');
        $bewegung = $this->alsAngemeldet($this->wart)->get('wart/getraenke/bewegung');
        $bewegung->assertStatus(200);
        $bewegung->assertSee('Helles');

        $mitglied = $this->personAnlegen();
        $this->alsAngemeldet($mitglied)->get('wart/getraenke/lieferung')->assertStatus(403);
        $this->alsAngemeldet($mitglied)->get('wart/getraenke/bewegung')->assertStatus(403);
        $this->alsAngemeldet($mitglied)->post('wart/getraenke/lieferung', $this->csrf())->assertStatus(403);
        $this->alsAngemeldet($mitglied)->post('wart/getraenke/bewegung', $this->csrf())->assertStatus(403);
    }
}
