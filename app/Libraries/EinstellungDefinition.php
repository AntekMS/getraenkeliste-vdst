<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Definition und Validierung der vom Admin aenderbaren Betriebswerte.
 */
final class EinstellungDefinition
{
    public const DEFINITIONEN = [
        'storno_frist_min'  => ['label' => 'Storno-Frist (Minuten)', 'typ' => 'int', 'min' => 0, 'max' => 120, 'default' => '10', 'aenderbar' => true],
        'tablet_timeout_s'  => ['label' => 'Tablet-Timeout (Sekunden)', 'typ' => 'int', 'min' => 10, 'max' => 600, 'default' => '30', 'aenderbar' => true],
        'vereinsname'       => ['label' => 'Vereinsname', 'typ' => 'text', 'min' => 1, 'max' => 100, 'default' => 'Verein deutscher Studenten zu Erlangen', 'aenderbar' => true],
        'erinnerung_tage'   => ['label' => 'Erinnerung nach (Tagen)', 'typ' => 'int', 'min' => 1, 'max' => 365, 'default' => '31', 'aenderbar' => true],
        'inbetriebnahme_at' => ['label' => 'Inbetriebnahme', 'typ' => 'datum', 'default' => '', 'aenderbar' => false],
    ];

    /**
     * @return ?string Fehlertext oder null, wenn der Wert gueltig ist
     */
    public static function validiere(string $schluessel, string $wert): ?string
    {
        $definition = self::DEFINITIONEN[$schluessel] ?? null;

        if ($definition === null) {
            return 'Unbekannte Einstellung.';
        }

        if (! $definition['aenderbar']) {
            return 'Diese Einstellung kann nicht geändert werden.';
        }

        if ($definition['typ'] === 'int') {
            if (preg_match('/^\d+\z/', $wert) !== 1 || (int) $wert < $definition['min'] || (int) $wert > $definition['max']) {
                return "Bitte eine ganze Zahl von {$definition['min']} bis {$definition['max']} eingeben.";
            }

            return null;
        }

        $laenge = mb_strlen($wert);

        return $laenge < $definition['min'] || $laenge > $definition['max']
            ? "Bitte {$definition['min']} bis {$definition['max']} Zeichen eingeben."
            : null;
    }

    /**
     * @return list<string> Schluessel, die der Admin aendern darf
     */
    public static function aenderbar(): array
    {
        return array_keys(array_filter(self::DEFINITIONEN, static fn (array $d): bool => $d['aenderbar']));
    }
}
