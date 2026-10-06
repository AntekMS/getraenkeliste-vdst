<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BuchungModel;
use App\Models\PersonModel;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DbTestCase;

/**
 * Meine Buchungen ab dem letzten abgeschlossenen Stichtag je Bereich, frühere Zeiträume mit Summe.
 *
 * @internal
 */
final class MeineBuchungenZeitraumTest extends DbTestCase
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
    private function buchung(int $konto, int $artikel, string $gebuchtAt, array $werte = []): int
    {
        return (int) (new BuchungModel())->insert(array_merge([
            'vorgang_id' => bin2hex(random_bytes(4)) . '-0000-4000-8000-000000000000',
            'konto_id' => $konto, 'artikel_id' => $artikel, 'menge' => 1, 'einzelpreis_cent' => 150,
            'quelle' => 'web', 'gebucht_von_id' => $konto, 'gebucht_at' => $gebuchtAt,
        ], $werte), true);
    }

    private function z(string $zeit): DateTimeImmutable
    {
        return new DateTimeImmutable($zeit, new DateTimeZone('Europe/Berlin'));
    }

    public function test_offener_betrag_zaehlt_nur_nach_dem_stichtag(): void
    {
        $ich     = $this->personAnlegen();
        $artikel = $this->artikelAnlegen(['name' => 'Helles']);
        $this->auszaehlungAnlegen('2026-10-01 18:00:00');
        $this->buchung($ich, $artikel, '2026-10-01 18:00:00', ['einzelpreis_cent' => 777]);
        $this->buchung($ich, $artikel, '2026-10-01 18:00:01', ['menge' => 2]);

        $seite = $this->alsAngemeldet($ich)->get('meine-buchungen');

        $seite->assertOK();
        $seite->assertSee('Offener Betrag: <strong>3,00 €</strong>', null, false);
        $seite->assertSee('01.10.2026 18:00');
        $this->assertSame(1, substr_count($seite->getBody(), 'data-label="Artikel"'));

        $model = new BuchungModel();
        $this->assertSame(300, $model->offenerBetrag($ich, 'getraenke', $this->z('2026-10-01 18:00:00'), false));
        $this->assertSame(1077, $model->offenerBetrag($ich, 'getraenke', $this->z('2026-10-01 18:00:00'), true));
        $this->assertCount(1, $model->fuerKonto($ich, $this->z('2026-10-01 18:00:00'), false, $this->bereichId('getraenke')));
    }

    public function test_fruehere_zeitraeume_zeigen_die_eigene_summe(): void
    {
        $ich     = $this->personAnlegen();
        $fremd   = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        $this->auszaehlungAnlegen('2026-03-01 18:00:00');
        $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'getraenke', ['zeitraum_von' => '2026-03-01 18:00:00']);
        $this->auszaehlungAnlegen('2026-10-04 18:00:00', 'entwurf');

        // Erster Zeitraum: ab Inbetriebnahme inklusiv bis Stichtag inklusiv → 1,00 + 2,00 = 3,00
        $this->buchung($ich, $artikel, '2026-01-01 00:00:00', ['einzelpreis_cent' => 100]);
        $this->buchung($ich, $artikel, '2026-03-01 18:00:00', ['einzelpreis_cent' => 200]);
        // Zweiter Zeitraum: 4,00 + 8,00 = 12,00; storniert und fremd zählen nicht
        $this->buchung($ich, $artikel, '2026-03-01 18:00:01', ['einzelpreis_cent' => 400]);
        $this->buchung($ich, $artikel, '2026-10-01 18:00:00', ['einzelpreis_cent' => 800]);
        $this->buchung($ich, $artikel, '2026-05-01 12:00:00', ['einzelpreis_cent' => 1600, 'storniert_at' => '2026-05-01 12:01:00']);
        $this->buchung($fremd, $artikel, '2026-05-01 12:00:00', ['einzelpreis_cent' => 3200]);

        $seite = $this->alsAngemeldet($ich)->get('meine-buchungen');
        $body  = $seite->getBody();

        $seite->assertSee('Frühere Zeiträume');
        $seite->assertSee('01.03.2026 18:00 – 01.10.2026 18:00');
        $seite->assertSee('01.01.2026 00:00 – 01.03.2026 18:00');
        // Rohes HTML: die Antwort kodiert – und € als Entitäten.
        $this->assertMatchesRegularExpression('/01\.03\.2026 18:00 &ndash; 01\.10\.2026 18:00.*?12,00 &euro;/s', $body);
        $this->assertMatchesRegularExpression('/01\.01\.2026 00:00 &ndash; 01\.03\.2026 18:00.*?3,00 &euro;/s', $body);
        $this->assertStringNotContainsString('04.10.2026 18:00', $body);
        $this->assertStringNotContainsString('16,00', $body);
        $this->assertStringNotContainsString('32,00', $body);
        $seite->assertSee('Offener Betrag: <strong>0,00 €</strong>', null, false);

        $model     = new BuchungModel();
        $bereichId = $this->bereichId('getraenke');
        $this->assertSame(1200, $model->summeImZeitraum($ich, $bereichId, $this->z('2026-03-01 18:00:00'), $this->z('2026-10-01 18:00:00')));
        $this->assertSame(200, $model->summeImZeitraum($ich, $bereichId, $this->z('2026-01-01 00:00:00'), $this->z('2026-03-01 18:00:00')));
        $this->assertSame(300, $model->summeImZeitraum($ich, $bereichId, $this->z('2026-01-01 00:00:00'), $this->z('2026-03-01 18:00:00'), true));
    }

    public function test_ohne_abschluss_keine_frueheren_zeitraeume(): void
    {
        $ich = $this->personAnlegen();
        $this->auszaehlungAnlegen('2026-10-04 18:00:00', 'entwurf');

        $this->alsAngemeldet($ich)->get('meine-buchungen')->assertDontSee('Frühere Zeiträume');
    }

    public function test_storno_button_fehlt_bei_eingefrorenen_buchungen(): void
    {
        $ich     = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();
        $couleur = (new PersonModel())->sammelkontoId('Couleur');
        // Innerhalb der Storno-Frist, aber vor dem Stichtag.
        $eigene = $this->buchung($ich, $artikel, '2026-10-05 11:55:00');
        $this->buchung($couleur, $artikel, '2026-10-05 11:56:00', ['gebucht_von_id' => $ich]);
        $this->auszaehlungAnlegen('2026-10-05 11:58:00');
        $offen = $this->buchung($ich, $artikel, '2026-10-05 11:59:00');

        $body = $this->alsAngemeldet($ich)->get('meine-buchungen')->getBody();

        $this->assertSame(1, substr_count($body, 'meine-buchungen/storno/'));
        $this->assertStringContainsString('meine-buchungen/storno/' . $offen, $body);
        $this->assertStringNotContainsString('Von dir auf Couleur/Bund gebucht', $body);

        // Direkt-POST auf die eingefrorene Buchung wird serverseitig abgewiesen.
        $antwort = $this->alsAngemeldet($ich)->post('meine-buchungen/storno/' . $eigene, $this->csrf());
        $antwort->assertRedirectTo(site_url('meine-buchungen'));
        $antwort->assertSessionHas('error', 'Dieser Zeitraum ist abgeschlossen.');
        $this->assertNull((new BuchungModel())->find($eigene)['storniert_at']);
    }

    public function test_bereiche_haben_eigene_zeitraeume(): void
    {
        db_connect()->table('bereiche')->where('schluessel', 'kiosk')->update(['aktiv' => 1]);
        $ich   = $this->personAnlegen();
        $bier  = $this->artikelAnlegen();
        db_connect()->table('kategorien')->insert(['bereich_id' => $this->bereichId('kiosk'), 'name' => 'Snacks']);
        $kat   = (int) db_connect()->insertID();
        $snack = $this->artikelAnlegen(['name' => 'Brezel', 'kategorie_id' => $kat, 'preis_cent' => 90]);
        $this->auszaehlungAnlegen('2026-10-01 18:00:00', 'abgeschlossen', 'kiosk');
        $this->buchung($ich, $bier, '2026-09-01 12:00:00');
        $this->buchung($ich, $snack, '2026-09-01 12:00:00', ['einzelpreis_cent' => 90]);

        $body = $this->alsAngemeldet($ich)->get('meine-buchungen')->getBody();

        // Getränke ohne Abschluss: Septemberbuchung offen; Kiosk: eingefroren, nur als früherer Zeitraum.
        $this->assertStringContainsString('Offener Betrag: <strong>1,50 &euro;</strong>', $body);
        $this->assertStringContainsString('Offener Betrag: <strong>0,00 &euro;</strong>', $body);
        $this->assertStringContainsString('01.01.2026 00:00 &ndash; 01.10.2026 18:00', $body);
        $this->assertStringContainsString('0,90 &euro;', $body);
        $this->assertStringNotContainsString('Brezel', $body);
    }
}
