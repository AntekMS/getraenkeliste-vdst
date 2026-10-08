<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Reine Rechenklasse der Statistik (keine DB, keine Uhr).
 */
final class StatistikRechner
{
    public const MAX_GRUNDLAGE_TAGE = 28;

    /**
     * Volle Kalendertage zwischen den Datumsteilen, mindestens 1, höchstens 28.
     */
    public static function grundlageTage(DateTimeImmutable $inbetriebnahme, DateTimeImmutable $jetzt): int
    {
        $von = $inbetriebnahme->setTime(0, 0);
        $bis = $jetzt->setTime(0, 0);
        $tage = $bis < $von ? 0 : (int) $von->diff($bis)->days;

        return max(1, min(self::MAX_GRUNDLAGE_TAGE, $tage));
    }

    public static function tagesverbrauch(int $verkauft, int $grundlageTage): float
    {
        return $verkauft / max(1, $grundlageTage);
    }

    public static function reichweiteTage(int $bestand, float $tagesverbrauch): ?int
    {
        if ($tagesverbrauch <= 0.0) {
            return null;
        }

        if ($bestand <= 0) {
            return 0;
        }

        return (int) floor($bestand / $tagesverbrauch);
    }

    /**
     * @return array{stueck: int, kisten: ?int}|null
     */
    public static function vorschlag(float $tagesverbrauch, int $reichweiteTage, int $mindestbestand, int $bestand, ?int $gebinde): ?array
    {
        // round() fängt Gleitkomma-Rauschen ab (0.3 × 30 darf nicht 9.000000000000002 → 10 ergeben).
        $bedarf = (int) ceil(round($tagesverbrauch * $reichweiteTage + $mindestbestand - $bestand, 6));

        if ($bedarf <= 0) {
            return null;
        }

        if ($gebinde === null || $gebinde <= 0) {
            return ['stueck' => $bedarf, 'kisten' => null];
        }

        $kisten = intdiv($bedarf + $gebinde - 1, $gebinde);

        return ['stueck' => $kisten * $gebinde, 'kisten' => $kisten];
    }

    /**
     * @param array{mitglieder: int, couleur: int, bund: int} $werte
     *
     * @return array{mitglieder: ?float, couleur: ?float, bund: ?float}
     */
    public static function anteile(array $werte): array
    {
        $summe = $werte['mitglieder'] + $werte['couleur'] + $werte['bund'];
        $punkt = static fn (int $wert): ?float => $summe === 0 ? null : round($wert * 100 / $summe, 1);

        return [
            'mitglieder' => $punkt($werte['mitglieder']),
            'couleur'    => $punkt($werte['couleur']),
            'bund'       => $punkt($werte['bund']),
        ];
    }

    public static function quote(int $fehlmenge, int $verkauft): ?float
    {
        return $verkauft === 0 ? null : round($fehlmenge * 100 / $verkauft, 1);
    }

    public static function isoWoche(DateTimeImmutable $tag): string
    {
        return $tag->format('o-\WW');
    }

    /**
     * @return list<string> älteste zuerst, inklusive der laufenden Woche
     */
    public static function letzteWochen(DateTimeImmutable $jetzt, int $anzahl): array
    {
        $montag = $jetzt->modify('monday this week')->setTime(12, 0);
        $wochen = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $wochen[] = self::isoWoche($montag);
            $montag   = $montag->modify('-1 week');
        }

        return array_reverse($wochen);
    }
}
