<?php

namespace Config;

use App\Libraries\Einstellungen;
use App\Libraries\Protokollierer;
use App\Libraries\Uhr;
use CodeIgniter\Config\BaseService;

/**
 * Anwendungsspezifische Services: `uhr` (einzige Quelle für „jetzt“),
 * `einstellungen` (je Request gecacht) und `protokollierer`.
 */
class Services extends BaseService
{
    public static function uhr(bool $getShared = true): Uhr
    {
        if ($getShared) {
            return static::getSharedInstance('uhr');
        }

        return new Uhr();
    }

    public static function einstellungen(bool $getShared = true): Einstellungen
    {
        if ($getShared) {
            return static::getSharedInstance('einstellungen');
        }

        return new Einstellungen();
    }

    public static function protokollierer(bool $getShared = true): Protokollierer
    {
        if ($getShared) {
            return static::getSharedInstance('protokollierer');
        }

        return new Protokollierer();
    }
}
