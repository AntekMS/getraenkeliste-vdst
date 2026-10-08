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
}
