<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Fachliche Ablehnung einer Buchung/Stornierung. Die Nachricht ist der deutsche
 * Text für die Oberfläche; Programmierfehler sind stattdessen InvalidArgumentException.
 */
final class BuchungAbgelehnt extends RuntimeException
{
}
