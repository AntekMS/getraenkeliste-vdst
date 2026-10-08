<?php

namespace Config;

use App\Libraries\Anmeldung;
use App\Libraries\Artikelbild;
use App\Libraries\AuszaehlungExport;
use App\Libraries\AuszaehlungService;
use App\Libraries\BestandService;
use App\Libraries\BuchungService;
use App\Libraries\Einstellungen;
use App\Libraries\Geraete;
use App\Libraries\Protokollierer;
use App\Libraries\StatistikService;
use App\Libraries\Uhr;
use App\Libraries\Zeitraeume;
use CodeIgniter\Config\BaseService;

/**
 * Anwendungsspezifische Services: `uhr` (einzige Quelle für „jetzt“),
 * `einstellungen`, `zeitraeume` (beide je Request gecacht), `protokollierer`, `auszaehlungExport`, `artikelbild` und `statistik`.
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

    public static function bestand(bool $getShared = true): BestandService
    {
        if ($getShared) {
            return static::getSharedInstance('bestand');
        }

        return new BestandService();
    }

    public static function auszaehlungen(bool $getShared = true): AuszaehlungService
    {
        if ($getShared) {
            return static::getSharedInstance('auszaehlungen');
        }

        return new AuszaehlungService();
    }

    public static function auszaehlungExport(bool $getShared = true): AuszaehlungExport
    {
        if ($getShared) {
            return static::getSharedInstance('auszaehlungExport');
        }

        return new AuszaehlungExport();
    }

    public static function zeitraeume(bool $getShared = true): Zeitraeume
    {
        if ($getShared) {
            return static::getSharedInstance('zeitraeume');
        }

        return new Zeitraeume();
    }

    public static function artikelbild(bool $getShared = true): Artikelbild
    {
        if ($getShared) {
            return static::getSharedInstance('artikelbild');
        }

        return new Artikelbild();
    }

    public static function statistik(bool $getShared = true): StatistikService
    {
        if ($getShared) {
            return static::getSharedInstance('statistik');
        }

        return new StatistikService();
    }
}
