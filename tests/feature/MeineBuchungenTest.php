<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BuchungModel;
use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class MeineBuchungenTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->uhrStellen('2026-10-05 12:00:00');
        db_connect()->table('einstellungen')->where('schluessel', 'inbetriebnahme_at')->update(['wert' => '2026-01-01 00:00:00']);
    }

    /**
     * @param array<string, mixed> $werte
     */
    private function buchung(int $konto, int $artikel, array $werte = []): int
    {
        return (int) (new BuchungModel())->insert(array_merge([
            'vorgang_id' => bin2hex(random_bytes(4)) . '-0000-4000-8000-000000000000',
            'konto_id' => $konto, 'artikel_id' => $artikel, 'menge' => 1, 'einzelpreis_cent' => 150,
            'quelle' => 'web', 'gebucht_von_id' => $konto, 'gebucht_at' => '2026-10-05 11:55:00',
        ], $werte), true);
    }

    public function test_zeigt_eigene_buchungen_und_offenen_betrag(): void
    {
        $id      = $this->personAnlegen();
        $helles  = $this->artikelAnlegen(['name' => 'Helles']);
        $weizen  = $this->artikelAnlegen(['name' => 'Weizen']);
        $this->buchung($id, $helles, ['menge' => 2]);
        $this->buchung($id, $weizen, ['storniert_at' => '2026-10-05 11:56:00', 'einzelpreis_cent' => 999]);
        $this->buchung($id, $helles, ['gebucht_at' => '2025-12-31 23:00:00', 'einzelpreis_cent' => 777]);
        $fremd = $this->personAnlegen();
        $this->buchung($fremd, $helles, ['einzelpreis_cent' => 555]);

        $seite = $this->alsAngemeldet($id)->get('meine-buchungen');

        $seite->assertOK();
        $seite->assertSee('Helles');
        $seite->assertSee('Weizen');
        $seite->assertSee('05.10.2026 11:55');
        $seite->assertSee('Offener Betrag');
        $seite->assertSee('Offener Betrag: <strong>3,00 €</strong>', null, false);
        $seite->assertDontSee('7,77');
        $seite->assertDontSee('5,55');
        $seite->assertSee('storniert');
        $seite->assertSee('table-stack');
        $this->assertSame(1, preg_match_all('/class="[^"]*\bbtn-vdst\b[^"]*"/', $seite->getBody()));
        $this->assertStringNotContainsString('<style', $seite->getBody());
        $this->assertSame(300, (new BuchungModel())->offenerBetrag($id, 'getraenke', new \DateTimeImmutable('2026-01-01')));
    }

    public function test_sammelkonto_buchungen_getrennt_und_nicht_im_betrag(): void
    {
        $id      = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        $couleur = (new PersonModel())->sammelkontoId('Couleur');
        $this->buchung($id, $artikel);
        $this->buchung($couleur, $artikel, ['gebucht_von_id' => $id, 'menge' => 4]);
        $this->buchung($couleur, $artikel, ['gebucht_von_id' => $this->personAnlegen(), 'menge' => 9]);

        $seite = $this->alsAngemeldet($id)->get('meine-buchungen');

        $seite->assertSee('Von dir auf Couleur/Bund gebucht');
        $seite->assertSee('Couleur');
        $body = $seite->getBody();
        $this->assertStringContainsString('1,50', $body);
        $this->assertStringContainsString('6,00', $body); // 4 x 1,50 in der Sammelkonto-Liste
        $this->assertStringNotContainsString('13,50', $body);
        $this->assertStringNotContainsString('7,50', $body); // nicht im offenen Betrag
        $this->assertSame(150, (new BuchungModel())->offenerBetrag($id, 'getraenke', new \DateTimeImmutable('2026-01-01')));
        $this->assertCount(1, (new BuchungModel())->vonPersonAufSammelkonten($id, new \DateTimeImmutable('2026-01-01')));
    }

    public function test_storno_eigene_buchung(): void
    {
        $id      = $this->personAnlegen();
        $buchung = $this->buchung($id, $this->artikelAnlegen());

        $antwort = $this->alsAngemeldet($id)->post('meine-buchungen/storno/' . $buchung, $this->csrf());

        $antwort->assertRedirectTo(site_url('meine-buchungen'));
        $antwort->assertSessionHas('success', 'Buchung storniert.');
        $zeile = (new BuchungModel())->find($buchung);
        $this->assertNotNull($zeile['storniert_at']);
        $this->assertSame($id, (int) $zeile['storniert_von_id']);
    }

    public function test_storno_buchung_auf_sammelkonto_durch_buchenden(): void
    {
        $id      = $this->personAnlegen();
        $buchung = $this->buchung((new PersonModel())->sammelkontoId('Bund'), $this->artikelAnlegen(), ['gebucht_von_id' => $id]);

        $this->alsAngemeldet($id)->post('meine-buchungen/storno/' . $buchung, $this->csrf())->assertSessionHas('success', 'Buchung storniert.');
        $this->assertNotNull((new BuchungModel())->find($buchung)['storniert_at']);
    }

    public function test_storno_fremde_buchung_403(): void
    {
        $id      = $this->personAnlegen();
        $fremd   = $this->personAnlegen();
        $buchung = $this->buchung($fremd, $this->artikelAnlegen());

        $antwort = $this->alsAngemeldet($id)->post('meine-buchungen/storno/' . $buchung, $this->csrf());

        $antwort->assertStatus(403);
        $this->assertNull((new BuchungModel())->find($buchung)['storniert_at']);
    }

    public function test_storno_unbekannte_buchung_403(): void
    {
        $id = $this->personAnlegen();

        $this->alsAngemeldet($id)->post('meine-buchungen/storno/999999', $this->csrf())->assertStatus(403);
    }

    public function test_storno_nach_frist_zeigt_fehler(): void
    {
        $id      = $this->personAnlegen();
        $buchung = $this->buchung($id, $this->artikelAnlegen(), ['gebucht_at' => '2026-10-05 11:00:00']);

        $antwort = $this->alsAngemeldet($id)->post('meine-buchungen/storno/' . $buchung, $this->csrf());

        $antwort->assertRedirectTo(site_url('meine-buchungen'));
        $antwort->assertSessionHas('error', 'Die Storno-Frist ist abgelaufen.');
        $this->assertNull((new BuchungModel())->find($buchung)['storniert_at']);
    }

    public function test_storno_button_nur_innerhalb_der_frist(): void
    {
        $id = $this->personAnlegen();
        $a  = $this->artikelAnlegen();
        $this->buchung($id, $a);
        $this->buchung($id, $a, ['gebucht_at' => '2026-10-05 10:00:00']);

        $body = $this->alsAngemeldet($id)->get('meine-buchungen')->getBody();

        $this->assertSame(1, substr_count($body, 'meine-buchungen/storno/'));
    }

    public function test_storno_schreibt_kein_protokoll(): void
    {
        $id      = $this->personAnlegen();
        $buchung = $this->buchung($id, $this->artikelAnlegen());
        $vorher  = db_connect()->table('protokoll')->countAllResults();

        $this->alsAngemeldet($id)->post('meine-buchungen/storno/' . $buchung, $this->csrf());

        $this->assertSame($vorher, db_connect()->table('protokoll')->countAllResults());
    }
}
