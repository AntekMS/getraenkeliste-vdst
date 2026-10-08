<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filters\RechtFilter;
use Tests\Support\DbTestCase;

/**
 * @internal
 */
final class RechtFilterTest extends DbTestCase
{
    public function test_ohne_argumente_403(): void
    {
        session()->set($this->angemeldeteSitzung($this->personAnlegen()));

        $this->assertSame(403, (new RechtFilter())->before(service('request'), null)->getStatusCode());
        $this->assertSame(403, (new RechtFilter())->before(service('request'), [])->getStatusCode());
    }

    private function filterStatus(int $person, string $argument): ?int
    {
        session()->set($this->angemeldeteSitzung($person));

        return (new RechtFilter())->before(service('request'), [$argument])?->getStatusCode();
    }

    private function personMitRolle(string $rolle): int
    {
        $id = $this->personAnlegen();
        $this->rolleGeben($id, $rolle);

        return $id;
    }

    public function test_aktion_mit_bereich(): void
    {
        $this->assertNull($this->filterStatus($this->personMitRolle('getraenkewart'), 'bestand_pflegen@getraenke'));
        $this->assertSame(403, $this->filterStatus($this->personMitRolle('kioskwart'), 'bestand_pflegen@getraenke'));
        $this->assertSame(403, $this->filterStatus($this->personAnlegen(), 'bestand_pflegen@getraenke'));
        $this->assertNull($this->filterStatus($this->personMitRolle('admin'), 'bestand_pflegen@getraenke'));
        $this->assertSame(403, $this->filterStatus($this->personMitRolle('getraenkewart'), 'bestand_pflegen@kiosk'));
    }

    public function test_unbekannter_oder_leerer_bereich_403(): void
    {
        $wart = $this->personMitRolle('getraenkewart');

        $this->assertSame(403, $this->filterStatus($wart, 'bestand_pflegen@unbekannt'));
        $this->assertSame(403, $this->filterStatus($wart, 'bestand_pflegen@'));
        $this->assertSame(403, $this->filterStatus($this->personMitRolle('admin'), 'bestand_pflegen@'));
    }

    public function test_mitglied_darf_buchen_aber_nicht_admin(): void
    {
        session()->set($this->angemeldeteSitzung($this->personAnlegen()));

        $this->assertNull((new RechtFilter())->before(service('request'), ['buchen']));
        $this->assertSame(403, (new RechtFilter())->before(service('request'), ['admin'])->getStatusCode());
    }
}
