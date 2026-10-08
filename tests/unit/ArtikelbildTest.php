<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Artikelbild;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class ArtikelbildTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function groessen(): iterable
    {
        yield 'Querformat wird verkleinert' => [1200, 800, 600, 400];
        yield 'Hochformat wird verkleinert' => [800, 1200, 400, 600];
        yield 'kleines Bild bleibt' => [300, 300, 300, 300];
        yield 'genau 600 bleibt' => [600, 450, 600, 450];
        yield 'sehr schmal bleibt mindestens 1 px' => [8000, 1, 600, 1];
    }

    #[DataProvider('groessen')]
    public function test_zielgroesse(int $breite, int $hoehe, int $zielBreite, int $zielHoehe): void
    {
        $this->assertSame([$zielBreite, $zielHoehe], Artikelbild::zielgroesse($breite, $hoehe));
    }

    public function test_url_mit_version_oder_null(): void
    {
        $this->assertSame('artikelbild/7?v=3-abcdef01', Artikelbild::url(['id' => '7', 'bild_datei' => 'abcdef01' . str_repeat('a', 24) . '.jpg', 'bild_version' => '3']));
        $this->assertNull(Artikelbild::url(['id' => 7, 'bild_datei' => null, 'bild_version' => 3]));
    }

    public function test_verschiedene_dateien_mit_gleicher_version_ergeben_verschiedene_urls(): void
    {
        // z. B. nach einem Restore: gleiche Version, aber anderes Bild → der Browser-Cache darf nicht greifen.
        $erste  = Artikelbild::url(['id' => 7, 'bild_datei' => str_repeat('a', 32) . '.jpg', 'bild_version' => 2]);
        $zweite = Artikelbild::url(['id' => 7, 'bild_datei' => str_repeat('b', 32) . '.jpg', 'bild_version' => 2]);

        $this->assertNotSame($erste, $zweite);
    }

    public function test_content_type_aus_der_endung(): void
    {
        $this->assertSame('image/png', Artikelbild::contentType('x.png'));
        $this->assertSame('image/jpeg', Artikelbild::contentType('x.jpg'));
    }

    public function test_pfad_nur_fuer_gueltige_dateinamen(): void
    {
        $bilder = new Artikelbild(sys_get_temp_dir());

        $this->assertNull($bilder->pfad('../.env'));
        $this->assertNull($bilder->pfad(str_repeat('a', 32) . '.php'));
        $this->assertNull($bilder->pfad(null));
        $this->assertNull($bilder->pfad(str_repeat('a', 32) . '.jpg')); // gültig, aber nicht vorhanden
    }
}
