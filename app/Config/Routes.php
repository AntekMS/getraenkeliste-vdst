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
