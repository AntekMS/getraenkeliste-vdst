<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->addRedirect('/', 'buchen');

$routes->get('login', 'AuthController::loginForm', ['filter' => 'kein_tablet']);
$routes->post('login', 'AuthController::login', ['filter' => 'kein_tablet']);

$routes->get('tablet/freischalten', 'TabletController::freischaltenForm', ['filter' => 'tablet:frei']);
$routes->post('tablet/freischalten', 'TabletController::freischalten', ['filter' => 'tablet:frei']);
// Tablet-Routen sind vom globalen csrf ausgenommen (Config\Filters); tablet_csrf leitet bei Formularfehlern zur Namensauswahl.
$routes->group('tablet', ['filter' => ['tablet', 'tablet_csrf']], static function (RouteCollection $routes): void {
    $routes->get('', 'TabletController::index');
    $routes->post('waehlen/(:num)', 'TabletController::waehlen/$1');
    $routes->get('pin/(:num)', 'TabletController::pinForm/$1');
    $routes->post('pin/(:num)', 'TabletController::pinPruefen/$1');
    $routes->get('buchen', 'TabletController::buchenSeite');
    $routes->post('buchen', 'TabletController::buchen');
    $routes->post('rueckgaengig', 'TabletController::rueckgaengig');
    $routes->post('fertig', 'TabletController::fertig');
});

// Artikelbilder: persönliche Anmeldung oder gültiges Tablet (Filter `bild`), sonst 403.
$routes->get('artikelbild/(:num)', 'ArtikelbildController::zeige/$1', ['filter' => 'bild']);

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

    $routes->get('einstellungen', 'EinstellungenController::index');
    $routes->post('einstellungen', 'EinstellungenController::speichern');

    $routes->get('protokoll', 'ProtokollController::index');
});

// Wart-Bereich: das Recht hängt am Bereich in der URL (recht:<aktion>@<bereich>); der Controller liefert 404 für inaktive Bereiche (Kiosk bis Stufe 3).
foreach (['getraenke', 'kiosk'] as $bereich) {
    $routes->group('wart/' . $bereich, ['namespace' => 'App\Controllers\Wart'], static function (RouteCollection $routes) use ($bereich): void {
        $filter = ['filter' => ['angemeldet', 'recht:bestand_pflegen@' . $bereich]];

        $statistik = ['filter' => ['angemeldet', 'recht:statistik_ansehen@' . $bereich]];

        $routes->get('einkauf', 'EinkaufController::index/' . $bereich, $statistik);
        // Alte Bestandsseite (Stufe 2): dauerhaft auf „Einkauf“ umgeleitet.
        $routes->get('bestand', 'EinkaufController::bestand/' . $bereich, $statistik);

        $routes->get('lieferung', 'BewegungenController::lieferungForm/' . $bereich, $filter);
        $routes->post('lieferung', 'BewegungenController::lieferung/' . $bereich, $filter);
        $routes->get('bewegung', 'BewegungenController::bewegungForm/' . $bereich, $filter);
        $routes->post('bewegung', 'BewegungenController::bewegung/' . $bereich, $filter);

        $verwalten = ['filter' => ['angemeldet', 'recht:buchungen_verwalten@' . $bereich]];

        $routes->get('buchungen', 'BuchungenController::index/' . $bereich, $verwalten);
        $routes->post('buchungen/(:num)/storno', 'BuchungenController::storno/' . $bereich . '/$1', $verwalten);
        $routes->get('korrektur', 'BuchungenController::korrekturForm/' . $bereich, $verwalten);
        $routes->post('korrektur', 'BuchungenController::korrektur/' . $bereich, $verwalten);

        $auszaehlung = ['filter' => ['angemeldet', 'recht:auszaehlung_durchfuehren@' . $bereich]];

        $routes->get('auszaehlung', 'AuszaehlungController::index/' . $bereich, $auszaehlung);
        $routes->post('auszaehlung', 'AuszaehlungController::speichern/' . $bereich, $auszaehlung);
        $routes->post('auszaehlungen/(:num)/neu-erzeugen', 'AuszaehlungController::neuErzeugen/' . $bereich . '/$1', $auszaehlung);

        $ansehen = ['filter' => ['angemeldet', 'recht:auszaehlung_ansehen@' . $bereich]];

        $routes->get('auszaehlungen', 'AuszaehlungController::liste/' . $bereich, $ansehen);
        $routes->get('auszaehlungen/(:num)/download', 'AuszaehlungController::download/' . $bereich . '/$1', $ansehen);
    });
}
