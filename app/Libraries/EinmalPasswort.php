<?php

declare(strict_types=1);

namespace App\Libraries;

final class EinmalPasswort
{
    /** Ohne leicht verwechselbare Zeichen 0, O, 1, l, I. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function erzeuge(int $laenge = 10): string
    {
        $letzter  = strlen(self::ALPHABET) - 1;
        $passwort = '';

        for ($i = 0; $i < $laenge; $i++) {
            $passwort .= self::ALPHABET[random_int(0, $letzter)];
        }

        return $passwort;
    }
}
