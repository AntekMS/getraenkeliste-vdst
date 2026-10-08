<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\Berechtigung;
use App\Libraries\EinstellungDefinition;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class MigrationTest extends DbTestCase
{
    public function test_bereiche_angelegt(): void
    {
        $bereiche = db_connect()->table('bereiche')->get()->getResultArray();

        $this->assertCount(2, $bereiche);

        $nachSchluessel = array_column($bereiche, null, 'schluessel');
        $this->assertSame(['getraenke', 'kiosk'], array_keys($nachSchluessel));
        $this->assertSame('0', (string) $nachSchluessel['kiosk']['aktiv']);
        $this->assertSame('1', (string) $nachSchluessel['getraenke']['aktiv']);

        foreach (Berechtigung::WART_BEREICH as $rolle => $schluessel) {
            $this->assertSame($rolle, $nachSchluessel[$schluessel]['verwalter_rolle']);
        }
    }

    public function test_sammelkonten_angelegt(): void
    {
        $konten = db_connect()->table('personen')->where('typ', 'sammelkonto')->orderBy('anzeigename')->get()->getResultArray();

        $this->assertSame(['Bund', 'Couleur'], array_column($konten, 'anzeigename'));

        foreach ($konten as $konto) {
            $this->assertNull($konto['benutzername']);
            $this->assertNull($konto['passwort_hash']);
            $this->assertNull($konto['pin_hash']);
            $this->assertSame('sonstige', $konto['gruppe']);
        }
    }

    public function test_einstellungen_haben_alle_defaults(): void
    {
        $zeilen = db_connect()->table('einstellungen')->get()->getResultArray();
        $werte  = array_column($zeilen, 'wert', 'schluessel');

        $this->assertEqualsCanonicalizing(array_keys(EinstellungDefinition::DEFINITIONEN), array_keys($werte));
        $this->assertSame('10', $werte['storno_frist_min']);
        $this->assertNotFalse(strtotime($werte['inbetriebnahme_at']));
    }

    public function test_vorgang_artikel_eindeutig(): void
    {
        $personId  = $this->personAnlegen();
        $artikelId = $this->artikelAnlegen();
        $zeile     = [
            'vorgang_id' => '11111111-1111-4111-8111-111111111111', 'konto_id' => $personId,
            'artikel_id' => $artikelId, 'menge' => 1, 'einzelpreis_cent' => 150,
            'quelle' => 'web', 'gebucht_at' => date('Y-m-d H:i:s'),
        ];

        db_connect()->table('buchungen')->insert($zeile);

        $this->expectException(DatabaseException::class);
        db_connect()->table('buchungen')->insert($zeile);
    }

    public function test_bestandstabellen_angelegt(): void
    {
        $db = db_connect();

        $this->assertEqualsCanonicalizing(
            ['id', 'artikel_id', 'art', 'menge', 'einkaufspreis_cent', 'bemerkung', 'person_id', 'erfolgt_at', 'created_at', 'updated_at'],
            $db->getFieldNames('bestandsbewegungen'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'bereich_id', 'art', 'stichtag', 'zeitraum_von', 'status', 'erstellt_von_id', 'abgeschlossen_at', 'datei_pfad', 'bemerkung', 'created_at', 'updated_at'],
            $db->getFieldNames('auszaehlungen'),
        );
        $this->assertEqualsCanonicalizing(
            ['auszaehlung_id', 'artikel_id', 'anfangsbestand', 'lieferungen', 'schwund_erfasst', 'korrekturen', 'verkauft', 'soll', 'ist', 'differenz', 'start', 'preis_cent', 'created_at', 'updated_at'],
            $db->getFieldNames('auszaehlung_positionen'),
        );

        $bewegungen = array_column($db->getIndexData('bestandsbewegungen'), 'fields');
        $this->assertContains(['artikel_id', 'erfolgt_at'], $bewegungen);

        $auszaehlungen = array_column($db->getIndexData('auszaehlungen'), 'fields');
        $this->assertContains(['bereich_id', 'status', 'stichtag'], $auszaehlungen);

        $this->assertSame(['auszaehlung_id', 'artikel_id'], $db->getIndexData('auszaehlung_positionen')['PRIMARY']->fields);
    }

    public function test_buchungen_haben_bemerkung(): void
    {
        $this->assertContains('bemerkung', db_connect()->getFieldNames('buchungen'));
    }

    public function test_buchungen_sind_standardmaessig_bestandswirksam(): void
    {
        $personId  = $this->personAnlegen();
        $artikelId = $this->artikelAnlegen();
        db_connect()->table('buchungen')->insert([
            'vorgang_id' => '22222222-2222-4222-8222-222222222222', 'konto_id' => $personId, 'artikel_id' => $artikelId,
            'menge' => 1, 'einzelpreis_cent' => 150, 'quelle' => 'web', 'gebucht_at' => '2026-10-05 12:00:00',
        ]);

        $this->assertSame('1', (string) db_connect()->table('buchungen')->get()->getRowArray()['bestandswirksam']);
    }

    public function test_eine_position_je_artikel_und_auszaehlung(): void
    {
        $db        = db_connect();
        $artikelId = $this->artikelAnlegen();
        $personId  = $this->personAnlegen();
        $bereichId = (int) $db->table('bereiche')->where('schluessel', 'getraenke')->get()->getRow()->id;

        $db->table('auszaehlungen')->insert([
            'bereich_id' => $bereichId, 'art' => 'start', 'stichtag' => '2026-10-06 12:00:00',
            'zeitraum_von' => '2026-10-01 00:00:00', 'status' => 'entwurf', 'erstellt_von_id' => $personId,
        ]);
        $position = [
            'auszaehlung_id' => (int) $db->insertID(), 'artikel_id' => $artikelId, 'anfangsbestand' => 0, 'lieferungen' => 0,
            'schwund_erfasst' => 0, 'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'ist' => null,
            'differenz' => 0, 'start' => 1, 'preis_cent' => 150,
        ];

        $db->table('auszaehlung_positionen')->insert($position);

        $this->expectException(DatabaseException::class);
        $db->table('auszaehlung_positionen')->insert($position);
    }
}
