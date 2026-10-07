<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Fachliche Ablehnung beim Speichern einer Auszählung. Die Nachricht ist der deutsche Text für die
 * Oberfläche; `$fehler` enthält Feldfehler (Schlüssel `stichtag`, `ist.<artikel_id>`).
 */
final class AuszaehlungAbgelehnt extends RuntimeException
{
    /**
     * @param array<string, string> $fehler
     */
    public function __construct(string $message, public readonly array $fehler = [])
    {
        parent::__construct($message);
    }
}
