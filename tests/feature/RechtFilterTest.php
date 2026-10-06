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

    public function test_mitglied_darf_buchen_aber_nicht_admin(): void
    {
        session()->set($this->angemeldeteSitzung($this->personAnlegen()));

        $this->assertNull((new RechtFilter())->before(service('request'), ['buchen']));
        $this->assertSame(403, (new RechtFilter())->before(service('request'), ['admin'])->getStatusCode());
    }
}
