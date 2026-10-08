<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\BuchungService;
use App\Models\BuchungModel;
use App\Models\PersonModel;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class BuchenWebTest extends DbTestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @param array<string, mixed> $daten
     */
    private function senden(int $personId, string $pfad, array $daten): TestResponse
    {
        return $this->alsAngemeldet($personId)
            ->withHeaders(['X-CSRF-TOKEN' => 'test-token', 'X-Requested-With' => 'XMLHttpRequest'])
            ->withBodyFormat('json')
            ->post($pfad, $daten);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(TestResponse $antwort): array
    {
        return json_decode($antwort->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buchungen(): array
    {
        return (new BuchungModel())->findAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function warenkorb(int $artikel, int $menge = 1, string $konto = 'ich'): array
    {
        return ['vorgang_id' => BuchungService::neueVorgangId(), 'konto' => $konto, 'positionen' => [['artikel_id' => $artikel, 'menge' => $menge]]];
    }

    public function test_buchungsseite_zeigt_nur_buchbare_artikel(): void
    {
        $id = $this->personAnlegen();
        $this->artikelAnlegen(['name' => 'Helles']);
        $this->artikelAnlegen(['name' => 'Altbier', 'archiviert_at' => '2026-01-01 00:00:00']);

        $seite = $this->alsAngemeldet($id)->get('buchen');

        $seite->assertOK();
        $seite->assertSee('Helles');
        $seite->assertDontSee('Altbier');
        $seite->assertSee('Getränke');
        $seite->assertDontSee('Fuxenkiosk');
        $seite->assertSee('Bier');
        $seite->assertSee('value="ich"');
        $seite->assertSee('value="couleur"');
        $seite->assertSee('value="bund"');
        $seite->assertSee('data-buchen-url');
        $seite->assertSee('data-rueckgaengig-url');
        $seite->assertSee('js/buchen.js?v=');
        $this->assertSame(1, preg_match_all('/class="[^"]*\bbtn-vdst\b[^"]*"/', $seite->getBody()));
        $this->assertStringNotContainsString('<style', $seite->getBody());
        $this->assertSame(1, preg_match('/data-vorgang-id="([^"]+)"/', $seite->getBody(), $m));
        $this->assertMatchesRegularExpression(self::UUID_V4, $m[1]);
    }

    public function test_buchen_fuer_mich(): void
    {
        $id      = $this->personAnlegen();
        $artikel = $this->artikelAnlegen(['preis_cent' => 150]);
        $daten   = $this->warenkorb($artikel, 2);

        $antwort = $this->senden($id, 'buchen', $daten);

        $antwort->assertStatus(200);
        $j = $this->json($antwort);
        $this->assertTrue($j['ok']);
        $this->assertSame(300, $j['summe_cent']);
        $this->assertSame('2× Helles – 3,00 €', $j['zusammenfassung']);
        $this->assertSame($daten['vorgang_id'], $j['vorgang_id']);
        $this->assertMatchesRegularExpression(self::UUID_V4, $j['naechste_vorgang_id']);
        $this->assertNotSame($daten['vorgang_id'], $j['naechste_vorgang_id']);
        $this->assertArrayHasKey('csrf_hash', $j);

        $zeilen = $this->buchungen();
        $this->assertCount(1, $zeilen);
        $this->assertSame($id, (int) $zeilen[0]['konto_id']);
        $this->assertSame($id, (int) $zeilen[0]['gebucht_von_id']);
        $this->assertSame('web', $zeilen[0]['quelle']);
    }

    public function test_buchen_auf_couleur_speichert_gebucht_von(): void
    {
        $id      = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();

        $this->senden($id, 'buchen', $this->warenkorb($artikel, 1, 'couleur'))->assertStatus(200);
        $this->senden($id, 'buchen', $this->warenkorb($artikel, 1, 'bund'))->assertStatus(200);

        $zeilen   = $this->buchungen();
        $personen = new PersonModel();
        $this->assertCount(2, $zeilen);
        $this->assertSame($personen->sammelkontoId('Couleur'), (int) $zeilen[0]['konto_id']);
        $this->assertSame($personen->sammelkontoId('Bund'), (int) $zeilen[1]['konto_id']);
        $this->assertSame($id, (int) $zeilen[0]['gebucht_von_id']);
        $this->assertSame($id, (int) $zeilen[1]['gebucht_von_id']);
    }

    public function test_doppelter_post_bucht_einmal(): void
    {
        $id    = $this->personAnlegen();
        $daten = $this->warenkorb($this->artikelAnlegen());

        $erste  = $this->senden($id, 'buchen', $daten);
        $zweite = $this->senden($id, 'buchen', $daten);

        $erste->assertStatus(200);
        $zweite->assertStatus(200);
        $this->assertSame($this->json($erste)['vorgang_id'], $this->json($zweite)['vorgang_id']);
        $this->assertSame($this->json($erste)['zusammenfassung'], $this->json($zweite)['zusammenfassung']);
        $this->assertSame($this->json($erste)['summe_cent'], $this->json($zweite)['summe_cent']);
        $this->assertTrue($this->json($zweite)['ok']);
        $this->assertCount(1, $this->buchungen());
    }

    public function test_abgelehnte_buchung_liefert_422_mit_meldung(): void
    {
        $id      = $this->personAnlegen();
        $artikel = $this->artikelAnlegen(['name' => 'Altbier', 'archiviert_at' => '2026-01-01 00:00:00']);

        $antwort = $this->senden($id, 'buchen', $this->warenkorb($artikel));

        $antwort->assertStatus(422);
        $j = $this->json($antwort);
        $this->assertFalse($j['ok']);
        $this->assertStringContainsString('Altbier', $j['meldung']);
        $this->assertSame([], $this->buchungen());
    }

    public function test_ungueltige_positionen_422(): void
    {
        $id = $this->personAnlegen();

        foreach ([
            [['artikel_id' => 'abc', 'menge' => 1]],
            [['artikel_id' => 1, 'menge' => 1.5]],
            [['artikel_id' => 1]],
            ['x'],
            'kein array',
        ] as $positionen) {
            $antwort = $this->senden($id, 'buchen', ['vorgang_id' => BuchungService::neueVorgangId(), 'konto' => 'ich', 'positionen' => $positionen]);

            $antwort->assertStatus(422);
            $this->assertSame('Ungültige Position. Nicht gebucht.', $this->json($antwort)['meldung']);
        }
    }

    public function test_numerische_strings_werden_als_zahl_akzeptiert(): void
    {
        $id      = $this->personAnlegen();
        $artikel = $this->artikelAnlegen();

        $this->senden($id, 'buchen', ['vorgang_id' => BuchungService::neueVorgangId(), 'konto' => 'ich', 'positionen' => [['artikel_id' => (string) $artikel, 'menge' => '2']]])
            ->assertStatus(200);
    }

    public function test_konto_wert_ungueltig_422(): void
    {
        $id = $this->personAnlegen();

        $antwort = $this->senden($id, 'buchen', $this->warenkorb($this->artikelAnlegen(), 1, 'fremd'));

        $antwort->assertStatus(422);
        $this->assertFalse($this->json($antwort)['ok']);
        $this->assertSame([], $this->buchungen());
    }

    public function test_ohne_csrf_header_wird_mit_403_abgewiesen(): void
    {
        $id = $this->personAnlegen();

        try {
            $this->alsAngemeldet($id)
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->withBodyFormat('json')
                ->post('buchen', $this->warenkorb($this->artikelAnlegen()));
            $this->fail('CSRF-Prüfung hat nicht abgewiesen.');
        } catch (SecurityException $e) {
            // Im Testlauf wird die Exception nicht in eine Antwort umgewandelt; ihr HTTP-Code ist 403.
            $this->assertSame(403, $e->getCode());
        }

        $this->assertSame([], $this->buchungen());
    }

    public function test_wiederholung_nach_storno_meldet_storniert(): void
    {
        $id    = $this->personAnlegen();
        $daten = $this->warenkorb($this->artikelAnlegen());

        $this->senden($id, 'buchen', $daten)->assertStatus(200);
        $this->senden($id, 'buchen/rueckgaengig', ['vorgang_id' => $daten['vorgang_id']])->assertStatus(200);

        $antwort = $this->senden($id, 'buchen', $daten);

        $antwort->assertStatus(200);
        $j = $this->json($antwort);
        $this->assertTrue($j['ok']);
        $this->assertTrue($j['storniert']);
        $this->assertSame('Dieser Vorgang wurde bereits rückgängig gemacht.', $j['meldung']);
        $this->assertMatchesRegularExpression(self::UUID_V4, $j['naechste_vorgang_id']);
    }

    public function test_rueckgaengig_eigener_vorgang(): void
    {
        $id    = $this->personAnlegen();
        $daten = $this->warenkorb($this->artikelAnlegen(), 1, 'couleur');

        $this->senden($id, 'buchen', $daten);
        $antwort = $this->senden($id, 'buchen/rueckgaengig', ['vorgang_id' => $daten['vorgang_id']]);

        $antwort->assertStatus(200);
        $this->assertTrue($this->json($antwort)['ok']);
        $this->assertNotNull($this->buchungen()[0]['storniert_at']);
        $this->assertSame($id, (int) $this->buchungen()[0]['storniert_von_id']);
    }

    public function test_rueckgaengig_abgelaufene_frist_422(): void
    {
        $id    = $this->personAnlegen();
        $daten = $this->warenkorb($this->artikelAnlegen());

        $this->uhrStellen('2026-10-05 12:00:00');
        $this->senden($id, 'buchen', $daten);
        $this->uhrStellen('2026-10-05 14:00:00');

        $antwort = $this->senden($id, 'buchen/rueckgaengig', ['vorgang_id' => $daten['vorgang_id']]);

        $antwort->assertStatus(422);
        $this->assertSame('Die Storno-Frist ist abgelaufen.', $this->json($antwort)['meldung']);
    }

    public function test_rueckgaengig_fremder_vorgang_403(): void
    {
        $ich     = $this->personAnlegen();
        $anderer = $this->personAnlegen();
        $daten   = $this->warenkorb($this->artikelAnlegen());

        $this->senden($anderer, 'buchen', $daten);
        $antwort = $this->senden($ich, 'buchen/rueckgaengig', ['vorgang_id' => $daten['vorgang_id']]);

        $antwort->assertStatus(403);
        // buchen.js unterscheidet die eigene 403 (ok:false) vom Framework-CSRF-403 (ohne ok, Seite wird neu geladen).
        $this->assertFalse($this->json($antwort)['ok']);
        $this->assertSame('Dieser Vorgang gehört nicht zu dir.', $this->json($antwort)['meldung']);
        $this->assertNull($this->buchungen()[0]['storniert_at']);
    }

    public function test_neue_vorgang_id_ist_uuid_v4(): void
    {
        $a = BuchungService::neueVorgangId();

        $this->assertMatchesRegularExpression(self::UUID_V4, $a);
        $this->assertNotSame($a, BuchungService::neueVorgangId());
    }
}
