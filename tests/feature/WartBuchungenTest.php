<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class WartBuchungenTest extends DbTestCase
{
    private int $wart;
    private int $konto;
    private int $artikel;

    protected function setUp(): void
    {
        parent::setUp();
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-10-01 00:00:00']);
        $this->uhrStellen('2026-10-10 12:00:00');
        $this->wart = $this->personAnlegen();
        $this->rolleGeben($this->wart, 'getraenkewart');
        $this->konto   = $this->personAnlegen(['anzeigename' => 'Konto Eins']);
        $this->artikel = $this->artikelAnlegen();
    }

    private function buchung(int $konto, int $artikel, string $zeit, int $menge = 1): int
    {
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => bin2hex(random_bytes(18)), 'konto_id' => $konto, 'artikel_id' => $artikel,
            'menge' => $menge, 'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_von_id' => $konto, 'gebucht_at' => $zeit,
        ]);

        return (int) db_connect()->insertID();
    }

    /**
     * @param array<string, mixed> $daten
     */
    private function sende(string $pfad, array $daten = [])
    {
        return $this->alsAngemeldet($this->wart)->post($pfad, $daten + $this->csrf());
    }

    public function test_liste_filtert_nach_person_artikel_und_tag(): void
    {
        $anderes = $this->personAnlegen(['anzeigename' => 'Konto Zwei']);
        $spezi   = $this->artikelAnlegen(['name' => 'Spezi']);
        $this->buchung($this->konto, $this->artikel, '2026-10-05 10:00:00');
        $this->buchung($anderes, $spezi, '2026-10-06 10:00:00');

        $alle = $this->alsAngemeldet($this->wart)->get('wart/getraenke/buchungen');
        $alle->assertOK();
        $alle->assertSee('Konto Eins');
        $alle->assertSee('Konto Zwei');

        $person = $this->alsAngemeldet($this->wart)->get('wart/getraenke/buchungen?person=' . $anderes);
        $person->assertSee('Spezi', 'td');
        $this->assertStringNotContainsString('<td data-label="Konto">Konto Eins</td>', $person->getBody());

        $artikel = $this->alsAngemeldet($this->wart)->get('wart/getraenke/buchungen?artikel=' . $this->artikel);
        $this->assertStringContainsString('<td data-label="Konto">Konto Eins</td>', $artikel->getBody());
        $this->assertStringNotContainsString('<td data-label="Konto">Konto Zwei</td>', $artikel->getBody());

        $tag = $this->alsAngemeldet($this->wart)->get('wart/getraenke/buchungen?tag=2026-10-06');
        $this->assertStringContainsString('<td data-label="Konto">Konto Zwei</td>', $tag->getBody());
        $this->assertStringNotContainsString('<td data-label="Konto">Konto Eins</td>', $tag->getBody());

        // Nicht-skalare Parameter ignorieren statt 500.
        $this->alsAngemeldet($this->wart)->get('wart/getraenke/buchungen?person[]=1&tag[]=x&page[]=2')->assertOK();
    }

    public function test_wart_storno_nach_ablauf_der_frist_mit_grund(): void
    {
        $id = $this->buchung($this->konto, $this->artikel, '2026-10-05 10:00:00');

        $this->sende("wart/getraenke/buchungen/{$id}/storno", ['grund' => 'Falsches Konto'])
            ->assertRedirectTo(site_url('wart/getraenke/buchungen'));

        $this->seeInDatabase('buchungen', ['id' => $id, 'storniert_von_id' => $this->wart, 'storno_grund' => 'Falsches Konto']);
        $this->seeInDatabase('protokoll', ['person_id' => $this->wart, 'aktion' => 'storniert', 'datensatz_id' => $id]);
    }

    public function test_wart_storno_ohne_grund_abgelehnt(): void
    {
        $id = $this->buchung($this->konto, $this->artikel, '2026-10-05 10:00:00');

        $this->sende("wart/getraenke/buchungen/{$id}/storno", ['grund' => ' '])->assertRedirectTo(site_url('wart/getraenke/buchungen'));

        $this->assertSame('Bitte einen Grund angeben.', session()->getFlashdata('error'));
        $this->seeInDatabase('buchungen', ['id' => $id, 'storniert_at' => null]);
    }

    public function test_wart_storno_im_eingefrorenen_zeitraum_abgelehnt(): void
    {
        $id = $this->buchung($this->konto, $this->artikel, '2026-10-05 10:00:00');
        $this->auszaehlungAnlegen('2026-10-08 00:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');

        $this->sende("wart/getraenke/buchungen/{$id}/storno", ['grund' => 'Irrtum'])->assertRedirectTo(site_url('wart/getraenke/buchungen'));

        $this->assertSame('Dieser Zeitraum ist abgeschlossen.', session()->getFlashdata('error'));
        $this->seeInDatabase('buchungen', ['id' => $id, 'storniert_at' => null]);
    }

    public function test_wart_storno_einer_buchung_aus_anderem_bereich_abgelehnt(): void
    {
        $id = $this->buchung($this->konto, $this->artikel, '2026-10-05 10:00:00');

        // Das Recht gilt je Bereich: der Getränkewart darf über die Kiosk-URL nichts (403 vom Filter).
        $this->sende("wart/kiosk/buchungen/{$id}/storno", ['grund' => 'x'])->assertStatus(403);
        $this->seeInDatabase('buchungen', ['id' => $id, 'storniert_at' => null]);
    }

    public function test_korrektur_minus_zwei_auf_couleur(): void
    {
        $couleur = (new PersonModel())->sammelkontoId('Couleur');
        $this->buchung($couleur, $this->artikel, '2026-10-05 10:00:00', 5);

        $antwort = $this->sende('wart/getraenke/korrektur', [
            'konto_id' => (string) $couleur, 'artikel_id' => (string) $this->artikel, 'menge' => '-2', 'bemerkung' => 'Zu viel gebucht',
        ]);

        $antwort->assertRedirectTo(site_url('wart/getraenke/buchungen'));
        $zeile = db_connect()->table('buchungen')->where('quelle', 'korrektur')->get()->getRowArray();
        $this->assertNotNull($zeile);
        $this->assertSame(-2, (int) $zeile['menge']);
        $this->assertSame(150, (int) $zeile['einzelpreis_cent']);
        $this->assertSame('Zu viel gebucht', $zeile['bemerkung']);
        $this->assertSame($this->wart, (int) $zeile['gebucht_von_id']);
        $this->assertSame(36, strlen($zeile['vorgang_id']));
        $this->assertSame(450, (new \App\Models\BuchungModel())->offenerBetrag($couleur, 'getraenke', service('zeitraeume')->beginn($this->bereichId('getraenke')), service('zeitraeume')->beginnInklusiv($this->bereichId('getraenke'))));
        $this->assertStringContainsString('Korrektur gebucht', (string) session()->getFlashdata('success'));
        $this->seeInDatabase('protokoll', ['person_id' => $this->wart, 'aktion' => 'korrektur', 'tabelle' => 'buchungen', 'datensatz_id' => (int) $zeile['id']]);
    }

    public function test_korrektur_menge_null_ohne_bemerkung_und_fremder_artikel_abgelehnt(): void
    {
        $kioskKat = (int) (new \App\Models\KategorieModel())->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks'], true);
        $fremd    = $this->artikelAnlegen(['kategorie_id' => $kioskKat, 'name' => 'Riegel']);
        $basis    = ['konto_id' => (string) $this->konto, 'artikel_id' => (string) $this->artikel, 'menge' => '1', 'bemerkung' => 'Grund'];

        foreach ([['menge' => '0'], ['menge' => '100'], ['menge' => 'abc'], ['bemerkung' => ' '], ['artikel_id' => (string) $fremd]] as $abweichung) {
            $this->sende('wart/getraenke/korrektur', $abweichung + $basis)->assertRedirectTo(site_url('wart/getraenke/korrektur'));
        }

        $this->seeNumRecords(0, 'buchungen', []);
    }

    public function test_korrektur_auf_archivierten_artikel_erlaubt(): void
    {
        $alt = $this->artikelAnlegen(['name' => 'Alt', 'archiviert_at' => '2026-10-02 00:00:00']);

        $this->sende('wart/getraenke/korrektur', ['konto_id' => (string) $this->konto, 'artikel_id' => (string) $alt, 'menge' => '3', 'bemerkung' => 'Altfehler'])
            ->assertRedirectTo(site_url('wart/getraenke/buchungen'));

        $this->seeInDatabase('buchungen', ['artikel_id' => $alt, 'menge' => 3, 'quelle' => 'korrektur']);
    }

    public function test_korrektur_im_eingefrorenen_zeitraum_abgelehnt(): void
    {
        // Stichtag = jetzt: alles bis jetzt ist eingefroren.
        $this->auszaehlungAnlegen('2026-10-10 12:00:00');
        $this->uhrStellen('2026-10-10 12:00:00');

        $this->sende('wart/getraenke/korrektur', ['konto_id' => (string) $this->konto, 'artikel_id' => (string) $this->artikel, 'menge' => '1', 'bemerkung' => 'x'])
            ->assertRedirectTo(site_url('wart/getraenke/korrektur'));

        $this->assertStringContainsString('abgeschlossen', (string) session()->getFlashdata('error'));
        $this->seeNumRecords(0, 'buchungen', []);
    }

    public function test_kioskwart_und_mitglied_bekommen_403(): void
    {
        $mitglied = $this->personAnlegen();

        foreach (['wart/getraenke/buchungen', 'wart/getraenke/korrektur'] as $pfad) {
            $this->alsAngemeldet($mitglied)->get($pfad)->assertStatus(403);
        }

        $this->alsAngemeldet($mitglied)->post('wart/getraenke/korrektur', $this->csrf())->assertStatus(403);

        $kiosk = $this->personAnlegen();
        $this->rolleGeben($kiosk, 'kioskwart');
        $this->alsAngemeldet($kiosk)->get('wart/getraenke/buchungen')->assertStatus(403);
        $this->alsAngemeldet($kiosk)->post('wart/getraenke/buchungen/1/storno', $this->csrf())->assertStatus(403);
    }
}
