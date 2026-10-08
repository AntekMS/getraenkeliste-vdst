<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Zentrale Rechteentscheidung (Rolle x Bereich x Aktion), siehe Spec Abschnitt 4.
 * Controller und Filter fragen nur diese Klasse.
 */
final class Berechtigung
{
    public const ROLLEN = ['mitglied', 'getraenkewart', 'kioskwart', 'kassenwart', 'admin'];

    public const WART_BEREICH = ['getraenkewart' => 'getraenke', 'kioskwart' => 'kiosk'];

    public const BUCHEN                   = 'buchen';
    public const EIGENE_STORNIEREN        = 'eigene_stornieren';
    public const BESTAND_PFLEGEN          = 'bestand_pflegen';
    public const BUCHUNGEN_VERWALTEN      = 'buchungen_verwalten';
    public const AUSZAEHLUNG_DURCHFUEHREN = 'auszaehlung_durchfuehren';
    public const AUSZAEHLUNG_ANSEHEN      = 'auszaehlung_ansehen';
    public const STATISTIK_ANSEHEN        = 'statistik_ansehen';
    public const SPENDEN_ANSEHEN          = 'spenden_ansehen';
    public const SPENDEN_PFLEGEN          = 'spenden_pflegen';
    public const ADMIN                    = 'admin';

    /** Aktionen, die an den Bereich des Warts gebunden sind. */
    private const WART_AKTIONEN = [
        self::BESTAND_PFLEGEN,
        self::BUCHUNGEN_VERWALTEN,
        self::AUSZAEHLUNG_DURCHFUEHREN,
        self::AUSZAEHLUNG_ANSEHEN,
        self::STATISTIK_ANSEHEN,
    ];

    /** Bereichsunabhaengige Aktionen je Rolle (admin darf ohnehin alles). */
    private const BEREICHSUNABHAENGIG = [
        'mitglied'      => [self::BUCHEN, self::EIGENE_STORNIEREN],
        'getraenkewart' => [self::SPENDEN_ANSEHEN, self::SPENDEN_PFLEGEN],
        'kassenwart'    => [self::SPENDEN_ANSEHEN, self::SPENDEN_PFLEGEN],
    ];

    private const KASSENWART_BEREICHE = ['getraenke', 'kiosk'];

    /**
     * @param list<string> $rollen
     *
     * @throws InvalidArgumentException bei unbekannter Aktion
     */
    public static function darf(array $rollen, string $aktion, ?string $bereich = null): bool
    {
        if (! self::istBekannt($aktion)) {
            throw new InvalidArgumentException("Unbekannte Aktion: {$aktion}");
        }

        foreach ($rollen as $rolle) {
            if (self::rolleDarf($rolle, $aktion, $bereich)) {
                return true;
            }
        }

        return false;
    }

    private static function istBekannt(string $aktion): bool
    {
        return in_array($aktion, self::WART_AKTIONEN, true)
            || in_array($aktion, [
                self::BUCHEN, self::EIGENE_STORNIEREN, self::SPENDEN_ANSEHEN, self::SPENDEN_PFLEGEN, self::ADMIN,
            ], true);
    }

    private static function rolleDarf(string $rolle, string $aktion, ?string $bereich): bool
    {
        if ($rolle === 'admin') {
            return true;
        }

        if (in_array($aktion, self::BEREICHSUNABHAENGIG[$rolle] ?? [], true)) {
            return true;
        }

        if (! in_array($aktion, self::WART_AKTIONEN, true) || $bereich === null) {
            return false;
        }

        if (isset(self::WART_BEREICH[$rolle])) {
            return self::WART_BEREICH[$rolle] === $bereich;
        }

        return $rolle === 'kassenwart'
            && $aktion === self::AUSZAEHLUNG_ANSEHEN
            && in_array($bereich, self::KASSENWART_BEREICHE, true);
    }
}
