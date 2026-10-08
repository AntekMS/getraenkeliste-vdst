<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Versuchszaehler;
use App\Models\PersonModel;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\DbTestCase;

/**
 * Atomarer Fehlversuch-Zähler (Login und PIN): Versuch wird per UPDATE beansprucht, nicht gelesen und zurückgeschrieben.
 *
 * @internal
 */
final class VersuchszaehlerTest extends DbTestCase
{
    private function zeit(string $zeit): DateTimeImmutable
    {
        return new DateTimeImmutable($zeit, new DateTimeZone('Europe/Berlin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $id): array
    {
        return (new PersonModel())->find($id);
    }

    public function test_zwei_versuche_zaehlen_ohne_erneutes_lesen_zwei(): void
    {
        $id      = $this->personAnlegen();
        $zaehler = new Versuchszaehler('login');
        $jetzt   = $this->zeit('2026-10-05 12:00:00');

        $this->assertTrue($zaehler->beanspruchen($id, $jetzt));
        $this->assertTrue($zaehler->beanspruchen($id, $jetzt));

        $this->assertSame(2, (int) $this->person($id)['login_fehlversuche']);
        $this->assertSame(0, (int) $this->person($id)['pin_fehlversuche']);
    }

    public function test_fuenfter_versuch_sperrt_und_setzt_zaehler_zurueck(): void
    {
        $id = $this->personAnlegen();
        (new PersonModel())->update($id, ['login_fehlversuche' => 4]);
        $zaehler = new Versuchszaehler('login');
        $jetzt   = $this->zeit('2026-10-05 12:00:00');

        $this->assertTrue($zaehler->beanspruchen($id, $jetzt));

        $person = $this->person($id);
        $this->assertSame(0, (int) $person['login_fehlversuche']);
        $this->assertSame('2026-10-05 12:05:00', $person['login_gesperrt_bis']);
        $this->assertTrue($zaehler->gesperrt($id, $jetzt));

        // Während der Sperre: kein Versuch, Zähler unverändert.
        $this->assertFalse($zaehler->beanspruchen($id, $this->zeit('2026-10-05 12:04:59')));
        $this->assertSame(0, (int) $this->person($id)['login_fehlversuche']);
        $this->assertSame('2026-10-05 12:05:00', $this->person($id)['login_gesperrt_bis']);

        // Ab Ablauf wieder frei, abgelaufene Sperre wird geleert.
        $this->assertTrue($zaehler->beanspruchen($id, $this->zeit('2026-10-05 12:05:00')));
        $this->assertSame(1, (int) $this->person($id)['login_fehlversuche']);
        $this->assertNull($this->person($id)['login_gesperrt_bis']);
    }

    public function test_erfolg_setzt_zurueck_auch_nach_sperrendem_versuch(): void
    {
        $id = $this->personAnlegen();
        (new PersonModel())->update($id, ['pin_fehlversuche' => 4]);
        $zaehler = new Versuchszaehler('pin');
        $jetzt   = $this->zeit('2026-10-05 12:00:00');

        $this->assertTrue($zaehler->beanspruchen($id, $jetzt));
        $this->assertTrue($zaehler->erfolg($id));

        $person = $this->person($id);
        $this->assertSame(0, (int) $person['pin_fehlversuche']);
        $this->assertNull($person['pin_gesperrt_bis']);
        $this->assertFalse($zaehler->gesperrt($id, $jetzt));
    }

    public function test_unbekanntes_praefix_wird_abgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Versuchszaehler('passwort_hash');
    }
}
