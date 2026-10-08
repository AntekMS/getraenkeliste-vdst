<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Sortierung;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SortierungTest extends CIUnitTestCase
{
    public function test_hoch_und_runter_tauschen_mit_dem_nachbarn(): void
    {
        $ids = [1, 2, 3];
        $this->assertTrue(Sortierung::tausche($ids, 3, 'hoch'));
        $this->assertSame([1, 3, 2], $ids);
        $this->assertTrue(Sortierung::tausche($ids, 1, 'runter'));
        $this->assertSame([3, 1, 2], $ids);
    }

    public function test_rand_und_unbekannte_id_sind_no_op(): void
    {
        $ids = [1, 2, 3];
        $this->assertFalse(Sortierung::tausche($ids, 1, 'hoch'));
        $this->assertFalse(Sortierung::tausche($ids, 3, 'runter'));
        $this->assertFalse(Sortierung::tausche($ids, 99, 'hoch'));
        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_einzelelement_und_leere_liste(): void
    {
        $eins = [5];
        $this->assertFalse(Sortierung::tausche($eins, 5, 'runter'));
        $leer = [];
        $this->assertFalse(Sortierung::tausche($leer, 5, 'hoch'));
    }
}
