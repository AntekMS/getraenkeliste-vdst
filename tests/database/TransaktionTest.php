<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\BuchungModel;
use RuntimeException;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class TransaktionTest extends DbTestCase
{
    public function test_vorheriger_trans_exception_wert_wird_wiederhergestellt(): void
    {
        $db = db_connect();

        $db->transException(true);
        (new BuchungModel())->transaktion(static function (): void {});
        $this->assertTrue($db->transException);

        $db->transException(false);
        (new BuchungModel())->transaktion(static function (): void {});
        $this->assertFalse($db->transException);
    }

    public function test_fehler_rollt_zurueck_und_wirft_weiter(): void
    {
        $id = $this->personAnlegen();

        try {
            (new BuchungModel())->transaktion(static function () use ($id): void {
                db_connect()->table('personen')->where('id', $id)->update(['anzeigename' => 'Geändert']);

                throw new RuntimeException('Abbruch');
            });
            $this->fail('Exception erwartet.');
        } catch (RuntimeException $e) {
            $this->assertSame('Abbruch', $e->getMessage());
        }

        $this->assertNotSame('Geändert', db_connect()->table('personen')->where('id', $id)->get()->getRowArray()['anzeigename']);
    }
}
