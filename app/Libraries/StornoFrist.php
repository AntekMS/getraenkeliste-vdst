<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;

final class StornoFrist
{
    /** Die Fristgrenze selbst gilt noch als offen. */
    public static function istOffen(DateTimeImmutable $gebuchtAt, DateTimeImmutable $jetzt, int $fristMinuten): bool
    {
        return $jetzt->getTimestamp() <= $gebuchtAt->getTimestamp() + $fristMinuten * 60;
    }
}
