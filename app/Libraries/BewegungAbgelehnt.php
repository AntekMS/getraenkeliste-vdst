<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Fachliche Ablehnung einer Lieferung/eines Schwunds/einer Korrektur. Die Nachricht ist der deutsche Text
 * für die Oberfläche; `$fehler` enthält Feldfehler (Schlüssel `bemerkung`, `menge`, `zeilen.<i>.kisten` …).
 */
final class BewegungAbgelehnt extends RuntimeException
{
    /**
     * @param array<string, string> $fehler
     */
    public function __construct(string $message, public readonly array $fehler = [])
    {
        parent::__construct($message);
    }
}
