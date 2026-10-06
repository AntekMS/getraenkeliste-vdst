<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\AuszaehlungModel;
use App\Models\AuszaehlungPositionModel;
use App\Models\KategorieModel;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class AuszaehlungModelTest extends DbTestCase
{
    public function test_entwurf_ist_nicht_die_letzte_abgeschlossene(): void
    {
        $bereich = $this->bereichId('getraenke');
        $this->auszaehlungAnlegen('2026-04-01 18:00:00', 'entwurf');

        $this->assertNull((new AuszaehlungModel())->letzteAbgeschlossene($bereich));
        $this->assertSame([], (new AuszaehlungModel())->abgeschlossene($bereich));
    }

    public function test_neuester_stichtag_gewinnt_gleichstand_nach_id(): void
    {
        $bereich = $this->bereichId('getraenke');
        $this->auszaehlungAnlegen('2026-05-01 18:00:00');
        $this->auszaehlungAnlegen('2026-03-01 18:00:00');
        $gleich = $this->auszaehlungAnlegen('2026-05-01 18:00:00');

        $model = new AuszaehlungModel();

        $this->assertSame($gleich, (int) $model->letzteAbgeschlossene($bereich)['id']);
        $this->assertSame(
            ['2026-05-01 18:00:00', '2026-05-01 18:00:00', '2026-03-01 18:00:00'],
            array_column($model->abgeschlossene($bereich), 'stichtag'),
        );
    }

    public function test_entwurf_je_bereich(): void
    {
        $getraenke = $this->bereichId('getraenke');
        $kiosk     = $this->bereichId('kiosk');
        $entwurf   = $this->auszaehlungAnlegen('2026-04-01 18:00:00', 'entwurf', 'kiosk');
        $this->auszaehlungAnlegen('2026-03-01 18:00:00', 'abgeschlossen', 'getraenke');

        $this->assertNull((new AuszaehlungModel())->entwurf($getraenke));
        $this->assertSame($entwurf, (int) (new AuszaehlungModel())->entwurf($kiosk)['id']);
    }

    public function test_positionen_sortiert_nach_kategorie_dann_artikel(): void
    {
        $bereich = $this->bereichId('getraenke');
        $wasser  = (int) (new KategorieModel())->insert(['bereich_id' => $bereich, 'name' => 'Wasser', 'sortierung' => 2], true);
        $bier    = (int) (new KategorieModel())->insert(['bereich_id' => $bereich, 'name' => 'Bier', 'sortierung' => 1], true);
        $still   = $this->artikelAnlegen(['name' => 'Still', 'kategorie_id' => $wasser, 'sortierung' => 1]);
        $weizen  = $this->artikelAnlegen(['name' => 'Weizen', 'kategorie_id' => $bier, 'sortierung' => 2]);
        $helles  = $this->artikelAnlegen(['name' => 'Helles', 'kategorie_id' => $bier, 'sortierung' => 1]);
        $id      = $this->auszaehlungAnlegen('2026-04-01 18:00:00', 'entwurf');

        foreach ([$still, $weizen, $helles] as $artikel) {
            db_connect()->table('auszaehlung_positionen')->insert([
                'auszaehlung_id' => $id, 'artikel_id' => $artikel, 'anfangsbestand' => 0, 'lieferungen' => 0,
                'schwund_erfasst' => 0, 'korrekturen' => 0, 'verkauft' => 0, 'soll' => 0, 'differenz' => 0, 'preis_cent' => 150,
            ]);
        }

        $positionen = (new AuszaehlungPositionModel())->fuer($id);

        $this->assertSame(['Helles', 'Weizen', 'Still'], array_column($positionen, 'artikel_name'));
        $this->assertSame(['Bier', 'Bier', 'Wasser'], array_column($positionen, 'kategorie_name'));
    }
}
