<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\AnmeldeTokenModel;
use App\Models\ArtikelModel;
use App\Models\BereichModel;
use App\Models\PersonModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class PersonModelTest extends DbTestCase
{
    public function test_rollen_mitglied_mit_admin(): void
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, 'admin');

        $this->assertSame(['mitglied', 'admin'], (new PersonModel())->rollen($id));
    }

    public function test_rollen_sammelkonto_leer(): void
    {
        $model = new PersonModel();

        $this->assertSame([], $model->rollen($model->sammelkontoId('Couleur')));
        $this->assertNotSame($model->sammelkontoId('Couleur'), $model->sammelkontoId('Bund'));
    }

    public function test_findeAktivNachBenutzername_ignoriert_archivierte(): void
    {
        $id = $this->personAnlegen(['benutzername' => 'anna']);
        $model = new PersonModel();

        $this->assertSame($id, (int) $model->findeAktivNachBenutzername('anna')['id']);

        $model->update($id, ['archiviert_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($model->findeAktivNachBenutzername('anna'));
        $this->assertFalse($model->istAktiv($model->find($id)));
    }

    public function test_aktive_bereiche(): void
    {
        $bereiche = (new BereichModel())->aktive();

        $this->assertSame(['getraenke'], array_column($bereiche, 'schluessel'));
    }

    public function test_loescheFuerPerson_loescht_nur_eigene_tokens(): void
    {
        $a = $this->personAnlegen();
        $b = $this->personAnlegen();
        $zeile = static fn (int $p, string $s): array => [
            'person_id' => $p, 'selector' => $s, 'token_hash' => str_repeat('a', 64),
            'gueltig_bis' => date('Y-m-d H:i:s', time() + 3600),
        ];
        $model = new AnmeldeTokenModel();
        $model->insert($zeile($a, str_repeat('1', 24)));
        $model->insert($zeile($a, str_repeat('2', 24)));
        $model->insert($zeile($b, str_repeat('3', 24)));

        $model->loescheFuerPerson($a);

        $this->assertSame(1, $model->countAllResults());
    }

    public function test_buchbar_blendet_kiosk_und_archiviertes_aus(): void
    {
        $sichtbar = $this->artikelAnlegen(['name' => 'Helles']);
        $this->artikelAnlegen(['name' => 'Altes', 'archiviert_at' => date('Y-m-d H:i:s')]);

        $kategorie = db_connect()->table('kategorien')->where('name', 'Bier')->get()->getRowArray();
        db_connect()->table('kategorien')->insert([
            'bereich_id' => $kategorie['bereich_id'], 'name' => 'Alt', 'archiviert_at' => date('Y-m-d H:i:s'),
        ]);
        $archivierteKategorie = (int) db_connect()->insertID();
        db_connect()->table('artikel')->insert(['kategorie_id' => $archivierteKategorie, 'name' => 'Versteckt', 'preis_cent' => 100]);

        $kioskId = (int) db_connect()->table('bereiche')->where('schluessel', 'kiosk')->get()->getRow()->id;
        db_connect()->table('kategorien')->insert(['bereich_id' => $kioskId, 'name' => 'Snacks']);
        db_connect()->table('artikel')->insert(['kategorie_id' => (int) db_connect()->insertID(), 'name' => 'Chips', 'preis_cent' => 100]);

        $buchbar = (new ArtikelModel())->buchbar();

        $this->assertCount(1, $buchbar);
        $this->assertSame('getraenke', $buchbar[0]['schluessel']);
        $this->assertCount(1, $buchbar[0]['kategorien']);
        $this->assertSame('Bier', $buchbar[0]['kategorien'][0]['name']);
        $this->assertSame(
            ['id' => $sichtbar, 'name' => 'Helles', 'einheit' => '0,5 l', 'preis_cent' => 150, 'bild_url' => null],
            $buchbar[0]['kategorien'][0]['artikel'][0],
        );

        $this->assertSame('getraenke', (new ArtikelModel())->findeBuchbar($sichtbar)['bereich_schluessel']);
        $this->assertNull((new ArtikelModel())->findeBuchbar($archivierteKategorie + 1000));
    }
}
