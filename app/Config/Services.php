<?php

namespace Config;

use App\Libraries\Anmeldung;
use App\Libraries\BuchungService;
use App\Libraries\Einstellungen;
use App\Libraries\Geraete;
use App\Libraries\Protokollierer;
use App\Libraries\Uhr;
use CodeIgniter\Config\BaseService;

/**
 * Anwendungsspezifische Services: `uhr` (einzige Quelle für „jetzt“),
 * `einstellungen` (je Request gecacht) und `protokollierer`.
 */
class Services extends BaseService
{
    public static function anmeldung(bool $getShared = true): Anmeldung
    {
        if ($getShared) {
            return static::getSharedInstance('anmeldung');
        }

        return new Anmeldung();
    }

    public static function geraete(bool $getShared = true): Geraete
    {
        if ($getShared) {
            return static::getSharedInstance('geraete');
        }

        return new Geraete();
    }

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

    public static function buchungen(bool $getShared = true): BuchungService
    {
        if ($getShared) {
            return static::getSharedInstance('buchungen');
        }

        return new BuchungService();
    }

    public static function protokollierer(bool $getShared = true): Protokollierer
    {
        if ($getShared) {
            return static::getSharedInstance('protokollierer');
        }

        return new Protokollierer();
    }
}
