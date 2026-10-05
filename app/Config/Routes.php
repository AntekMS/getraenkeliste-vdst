<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->addRedirect('/', 'buchen');

$routes->get('login', 'AuthController::loginForm');
$routes->post('login', 'AuthController::login');

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
});
