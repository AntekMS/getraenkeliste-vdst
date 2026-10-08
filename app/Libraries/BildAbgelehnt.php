<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/**
 * Hochgeladenes Artikelbild abgelehnt (zu groß, falscher Typ, nicht lesbar). Die Nachricht ist der deutsche
 * Text für die Oberfläche (Feldfehler `bild`).
 */
final class BildAbgelehnt extends RuntimeException
{
}
