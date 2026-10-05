<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->addRedirect('/', 'buchen');

$routes->get('login', 'AuthController::loginForm', ['filter' => 'kein_tablet']);
$routes->post('login', 'AuthController::login', ['filter' => 'kein_tablet']);

$routes->get('tablet/freischalten', 'TabletController::freischaltenForm', ['filter' => 'tablet:frei']);
$routes->post('tablet/freischalten', 'TabletController::freischalten', ['filter' => 'tablet:frei']);
$routes->get('tablet', 'TabletController::index', ['filter' => 'tablet']);

$routes->group('', ['filter' => 'angemeldet:frei'], static function (RouteCollection $routes): void {
    $routes->post('logout', 'AuthController::logout');
    $routes->get('konto/einrichten', 'KontoController::einrichtenForm');
    $routes->post('konto/einrichten', 'KontoController::einrichten');
});

$routes->group('', ['filter' => 'angemeldet'], static function (RouteCollection $routes): void {
    $routes->get('konto', 'KontoController::index');
    $routes->post('konto/passwort', 'KontoController::passwortAendern');
    $routes->post('konto/pin', 'KontoController::pinAendern');
});

$routes->group('', ['filter' => ['angemeldet', 'recht:buchen']], static function (RouteCollection $routes): void {
    $routes->get('buchen', 'BuchenController::index');
    $routes->post('buchen', 'BuchenController::buchen');
    $routes->post('buchen/rueckgaengig', 'BuchenController::rueckgaengig');
});

$routes->get('meine-buchungen', 'MeineBuchungenController::index', ['filter' => ['angemeldet', 'recht:buchen']]);
$routes->post('meine-buchungen/storno/(:num)', 'MeineBuchungenController::storno/$1', ['filter' => ['angemeldet', 'recht:eigene_stornieren']]);

$routes->group('admin', ['filter' => ['angemeldet', 'recht:admin'], 'namespace' => 'App\Controllers\Admin'], static function (RouteCollection $routes): void {
    $routes->get('personen', 'PersonenController::index');
    $routes->get('personen/neu', 'PersonenController::neu');
    $routes->post('personen', 'PersonenController::anlegen');
    $routes->get('personen/einmalpasswoerter', 'PersonenController::einmalpasswoerter');
    $routes->get('personen/import', 'PersonenImportController::form');
    $routes->post('personen/import/vorschau', 'PersonenImportController::vorschau');
    $routes->post('personen/import/ausfuehren', 'PersonenImportController::ausfuehren');
    $routes->get('personen/(:num)', 'PersonenController::bearbeiten/$1');
    $routes->post('personen/(:num)', 'PersonenController::speichern/$1');
    $routes->post('personen/(:num)/passwort-reset', 'PersonenController::passwortReset/$1');
    $routes->post('personen/(:num)/pin-reset', 'PersonenController::pinReset/$1');
    $routes->post('personen/(:num)/archivieren', 'PersonenController::archivieren/$1');

    $routes->get('tablets', 'TabletsController::index');
    $routes->post('tablets/code', 'TabletsController::codeErzeugen');
    $routes->post('tablets/(:num)/umbenennen', 'TabletsController::umbenennen/$1');
    $routes->post('tablets/(:num)/sperren', 'TabletsController::sperren/$1');

    $routes->get('stammdaten', 'StammdatenController::index');
    $routes->post('kategorien', 'StammdatenController::kategorieAnlegen');
    $routes->post('kategorien/(:num)', 'StammdatenController::kategorieSpeichern/$1');
    $routes->post('kategorien/(:num)/verschieben/(hoch|runter)', 'StammdatenController::kategorieVerschieben/$1/$2');
    $routes->post('kategorien/(:num)/archivieren', 'StammdatenController::kategorieArchivieren/$1');
    $routes->get('artikel/neu', 'StammdatenController::artikelNeu');
    $routes->get('artikel/(:num)', 'StammdatenController::artikelBearbeiten/$1');
    $routes->post('artikel', 'StammdatenController::artikelAnlegen');
    $routes->post('artikel/(:num)', 'StammdatenController::artikelSpeichern/$1');
    $routes->post('artikel/(:num)/verschieben/(hoch|runter)', 'StammdatenController::artikelVerschieben/$1/$2');
    $routes->post('artikel/(:num)/archivieren', 'StammdatenController::artikelArchivieren/$1');
});
