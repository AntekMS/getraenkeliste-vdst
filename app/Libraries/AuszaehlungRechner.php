<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

final class AuszaehlungRechner
{
    /**
     * `schwundErfasst` ist bereits negativ gespeichert. Ohne Ist gibt es keine Differenz.
     *
     * @return array{anfangsbestand: int, lieferungen: int, schwund_erfasst: int, korrekturen: int, verkauft: int, soll: int, ist: ?int, differenz: ?int, differenz_cent: ?int, start: bool}
     */
    public static function position(
        int $anfangsbestand,
        int $lieferungen,
        int $schwundErfasst,
        int $korrekturen,
        int $verkauft,
        ?int $ist,
        int $preisCent,
        bool $start,
    ): array {
        $soll = $anfangsbestand + $lieferungen + $schwundErfasst + $korrekturen - $verkauft;
        $differenz = $ist === null ? null : $ist - $soll;

        return [
            'anfangsbestand' => $anfangsbestand,
            'lieferungen'    => $lieferungen,
            'schwund_erfasst' => $schwundErfasst,
            'korrekturen'    => $korrekturen,
            'verkauft'       => $verkauft,
            'soll'           => $soll,
            'ist'            => $ist,
            'differenz'      => $differenz,
            'differenz_cent' => $differenz === null ? null : $differenz * $preisCent,
            'start'          => $start,
        ];
    }

    /**
     * Positiver Betrag in Cent: Summe |differenz_cent| aller Nicht-Start-Positionen mit Differenz < 0.
     * Bei einer Start-Auszählung immer 0.
     *
     * @param list<array{differenz_cent: ?int, start: bool}> $positionen
     */
    public static function schwundCent(array $positionen, string $art): int
    {
        if ($art === 'start') {
            return 0;
        }

        $summe = 0;
        foreach ($positionen as $p) {
            if ($p['start'] || $p['differenz_cent'] === null || $p['differenz_cent'] >= 0) {
                continue;
            }
            $summe += -$p['differenz_cent'];
        }

        return $summe;
    }

    /** Vergleich in voller Genauigkeit; der Aufrufer normalisiert auf Minuten. */
    public static function pruefeStichtag(DateTimeImmutable $stichtag, ?DateTimeImmutable $letzterStichtag, DateTimeImmutable $jetzt): ?string
    {
        if ($letzterStichtag !== null && $stichtag <= $letzterStichtag) {
            return 'Der Stichtag muss nach dem letzten Abschluss liegen.';
        }
        if ($stichtag > $jetzt) {
            return 'Der Stichtag darf nicht in der Zukunft liegen.';
        }

        return null;
    }
}
