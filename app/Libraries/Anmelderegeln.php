<?php

declare(strict_types=1);

namespace App\Libraries;

final class Anmelderegeln
{
    public static function passwortFehler(string $passwort): ?string
    {
        return mb_strlen($passwort) < 8 ? 'Das Passwort muss mindestens 8 Zeichen lang sein.' : null;
    }

    public static function pinFehler(string $pin): ?string
    {
        return preg_match('/^\d{4,6}\z/', $pin) === 1 ? null : 'Die PIN muss aus 4 bis 6 Ziffern bestehen.';
    }

    /**
     * Trimmt und kleinschreibt; null, wenn das Ergebnis kein gueltiger Benutzername ist.
     */
    public static function benutzernameNormalisieren(string $roh): ?string
    {
        $name = mb_strtolower(trim($roh));

        return preg_match('/^[a-z0-9._-]{3,40}\z/', $name) === 1 ? $name : null;
    }
}
