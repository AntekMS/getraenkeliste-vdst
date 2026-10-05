<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\Berechtigung;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 */
final class BerechtigungTest extends CIUnitTestCase
{
    private const ALLE_AKTIONEN = [
        'buchen', 'eigene_stornieren', 'bestand_pflegen', 'buchungen_verwalten',
        'auszaehlung_durchfuehren', 'auszaehlung_ansehen', 'statistik_ansehen',
        'spenden_ansehen', 'spenden_pflegen', 'admin',
    ];

    private const BEREICHE = ['getraenke', 'kiosk', null];

    private const WART_AKTIONEN = [
        'bestand_pflegen', 'buchungen_verwalten', 'auszaehlung_durchfuehren',
        'auszaehlung_ansehen', 'statistik_ansehen',
    ];

    /**
     * Erwartete Rechte je Rolle: Aktion => 'jeder' (Bereich egal) oder Liste erlaubter Bereiche.
     * Alles, was nicht aufgeführt ist, ist verboten.
     */
    private static function erwartung(): array
    {
        $wart = static fn (string $bereich): array => array_fill_keys(self::WART_AKTIONEN, [$bereich]);

        return [
            'mitglied'      => ['buchen' => 'jeder', 'eigene_stornieren' => 'jeder'],
            'getraenkewart' => array_merge($wart('getraenke'), ['spenden_ansehen' => 'jeder', 'spenden_pflegen' => 'jeder']),
            'kioskwart'     => $wart('kiosk'),
            'kassenwart'    => [
                'auszaehlung_ansehen' => ['getraenke', 'kiosk'],
                'spenden_ansehen'     => 'jeder',
                'spenden_pflegen'     => 'jeder',
            ],
            'admin'         => array_fill_keys(self::ALLE_AKTIONEN, 'jeder'),
        ];
    }

    public static function matrixProvider(): iterable
    {
        foreach (self::erwartung() as $rolle => $rechte) {
            foreach (self::ALLE_AKTIONEN as $aktion) {
                foreach (self::BEREICHE as $bereich) {
                    $regel   = $rechte[$aktion] ?? [];
                    $erlaubt = $regel === 'jeder' || ($bereich !== null && in_array($bereich, $regel, true));

                    yield "{$rolle} / {$aktion} / " . ($bereich ?? 'null') => [$rolle, $aktion, $bereich, $erlaubt];
                }
            }
        }
    }

    /**
     * @dataProvider matrixProvider
     */
    public function test_rollenmatrix(string $rolle, string $aktion, ?string $bereich, bool $erwartet): void
    {
        $this->assertSame($erwartet, Berechtigung::darf([$rolle], $aktion, $bereich));
    }

    public function test_kombinierte_rollen_vereinigen_rechte(): void
    {
        $rollen = ['mitglied', 'kioskwart'];

        $this->assertTrue(Berechtigung::darf($rollen, Berechtigung::BUCHEN));
        $this->assertTrue(Berechtigung::darf($rollen, Berechtigung::BESTAND_PFLEGEN, 'kiosk'));
        $this->assertFalse(Berechtigung::darf($rollen, Berechtigung::BESTAND_PFLEGEN, 'getraenke'));
        $this->assertFalse(Berechtigung::darf($rollen, Berechtigung::SPENDEN_ANSEHEN));
    }

    public function test_leere_rollen_duerfen_nichts(): void
    {
        foreach (self::ALLE_AKTIONEN as $aktion) {
            $this->assertFalse(Berechtigung::darf([], $aktion, 'getraenke'), $aktion);
        }
    }

    public function test_unbekannte_aktion_wirft(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Berechtigung::darf(['admin'], 'zaubern');
    }

    public function test_unbekannter_bereich_ist_verboten_fuer_wart_aktionen(): void
    {
        $this->assertFalse(Berechtigung::darf(['getraenkewart'], Berechtigung::BESTAND_PFLEGEN, 'keller'));
    }

    public function test_konstanten(): void
    {
        $this->assertSame(['mitglied', 'getraenkewart', 'kioskwart', 'kassenwart', 'admin'], Berechtigung::ROLLEN);
        $this->assertSame(['getraenkewart' => 'getraenke', 'kioskwart' => 'kiosk'], Berechtigung::WART_BEREICH);
    }
}
