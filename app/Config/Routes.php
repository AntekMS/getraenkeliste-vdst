<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->addRedirect('/', 'buchen');

$routes->get('login', 'AuthController::loginForm');
$routes->post('login', 'AuthController::login');

$routes->group('', ['filter' => 'angemeldet'], static function (RouteCollection $routes): void {
    $routes->post('logout', 'AuthController::logout');
    $routes->get('buchen', 'BuchenController::index');
});
