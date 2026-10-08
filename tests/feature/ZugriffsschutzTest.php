<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FreischaltcodeModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Router\Router;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DbTestCase;

/**
 * Zugriffs-Sweep: jede Route aus `php spark routes` (feste Liste unten, nicht existierende Beispiel-ID 999999) mit fünf Akteuren
 * (zusätzlich `getraenkewart`: Mitglied mit Rolle getraenkewart; Klassen wart_getraenke = durchgelassen, wart_kiosk = 403, für Admin 404).
 *
 * Routenklassen und erwartetes Ergebnis je Akteur:
 *
 * | Klasse      | anonym                | mitglied              | admin                 | tablet (Cookie)   |
 * |-------------|-----------------------|-----------------------|-----------------------|-------------------|
 * | offen       | durchgelassen         | durchgelassen         | durchgelassen         | Redirect `tablet` |
 * | frei_tablet | durchgelassen         | durchgelassen         | durchgelassen         | Redirect `tablet` |
 * | tablet      | Redirect freischalten | Redirect freischalten | Redirect freischalten | durchgelassen     |
 * | angemeldet  | Redirect `login`      | durchgelassen         | durchgelassen         | Redirect `tablet` |
 * | abmelden    | Redirect `login`      | Redirect `login`      | Redirect `login`      | Redirect `tablet` |
 * | admin       | Redirect `login`      | 403                   | durchgelassen         | Redirect `tablet` |
 * | umleitung   | Redirect `buchen`     | Redirect `buchen`     | Redirect `buchen`     | Redirect `buchen` |
 * | bild        | 403                   | durchgelassen (404)   | durchgelassen (404)   | durchgelassen (404) |
 *
 * Der Test prüft nur die Zugriffsschicht (Filter), keine Fachlogik: „durchgelassen“ ist ein Access-Check, kein Funktionstest.
 * Alle ID-Routen nutzen eine nicht existierende ID, damit Admin-POSTs bei geteilter DB ($refresh = false) nie echte Zeilen ändern.
 *
 * „durchgelassen“ = kein 403 und kein Redirect zu Login/Tablet/Freischaltung (die Fachlogik darf mit
 * 404/422/Fehlerflash antworten, Beispiel-IDs existieren nicht). Jeder Fall ist ein eigener Test, weil
 * Session-CSRF-Token und Geräte-Cookie nicht zwischen Requests eines Tests mitgeführt werden.
 *
 * @internal
 */
final class ZugriffsschutzTest extends DbTestCase
{
    private const JETZT = '2026-10-05 12:00:00';

    // Die Matrix legt je Fall eigene Personen/Geräte an und prüft nur Zugriffswege: Neuaufbau der DB je Fall (~1 s) wäre Verschwendung.
    protected $refresh = false;
    // (Folge: Benutzernamen müssen über Läufe hinweg eindeutig sein, daher uniqid.)

    protected function tearDown(): void
    {
        service('superglobals')->unsetCookie('gl_geraet');
        parent::tearDown();
    }

    /**
     * Alle Routen (Methode, Beispielpfad, Klasse). Muss genau `service('routes')` entsprechen (siehe Vollständigkeitstest).
     */
    private const ROUTEN = [
        ['GET', 'login', 'offen'], ['POST', 'login', 'offen'],
        ['GET', 'tablet/freischalten', 'frei_tablet'], ['POST', 'tablet/freischalten', 'frei_tablet'],
        ['GET', 'tablet', 'tablet'], ['GET', 'tablet/pin/999999', 'tablet'], ['GET', 'tablet/buchen', 'tablet'],
        ['POST', 'tablet/waehlen/999999', 'tablet'], ['POST', 'tablet/pin/999999', 'tablet'], ['POST', 'tablet/buchen', 'tablet'],
        ['POST', 'tablet/rueckgaengig', 'tablet'], ['POST', 'tablet/fertig', 'tablet'],
        ['GET', 'artikelbild/999999', 'bild'],
        ['POST', 'logout', 'abmelden'],
        ['GET', 'konto/einrichten', 'angemeldet'], ['POST', 'konto/einrichten', 'angemeldet'],
        ['GET', 'konto', 'angemeldet'], ['POST', 'konto/passwort', 'angemeldet'], ['POST', 'konto/pin', 'angemeldet'],
        ['GET', 'buchen', 'angemeldet'], ['POST', 'buchen', 'angemeldet'], ['POST', 'buchen/rueckgaengig', 'angemeldet'],
        ['GET', 'meine-buchungen', 'angemeldet'], ['POST', 'meine-buchungen/storno/999999', 'angemeldet'],
        ['GET', 'admin/personen', 'admin'], ['GET', 'admin/personen/neu', 'admin'], ['POST', 'admin/personen', 'admin'],
        ['GET', 'admin/personen/einmalpasswoerter', 'admin'], ['GET', 'admin/personen/import', 'admin'],
        ['POST', 'admin/personen/import/vorschau', 'admin'], ['POST', 'admin/personen/import/ausfuehren', 'admin'],
        ['GET', 'admin/personen/999999', 'admin'], ['POST', 'admin/personen/999999', 'admin'],
        ['POST', 'admin/personen/999999/passwort-reset', 'admin'], ['POST', 'admin/personen/999999/pin-reset', 'admin'],
        ['POST', 'admin/personen/999999/archivieren', 'admin'],
        ['GET', 'admin/tablets', 'admin'], ['POST', 'admin/tablets/code', 'admin'],
        ['POST', 'admin/tablets/999999/umbenennen', 'admin'], ['POST', 'admin/tablets/999999/sperren', 'admin'],
        ['GET', 'admin/stammdaten', 'admin'], ['POST', 'admin/kategorien', 'admin'], ['POST', 'admin/kategorien/999999', 'admin'],
        ['POST', 'admin/kategorien/999999/verschieben/hoch', 'admin'], ['POST', 'admin/kategorien/999999/verschieben/runter', 'admin'],
        ['POST', 'admin/kategorien/999999/archivieren', 'admin'],
        ['GET', 'admin/artikel/neu', 'admin'], ['GET', 'admin/artikel/999999', 'admin'], ['POST', 'admin/artikel', 'admin'],
        ['POST', 'admin/artikel/999999', 'admin'], ['POST', 'admin/artikel/999999/verschieben/hoch', 'admin'],
        ['POST', 'admin/artikel/999999/verschieben/runter', 'admin'], ['POST', 'admin/artikel/999999/archivieren', 'admin'],
        ['GET', 'admin/einstellungen', 'admin'], ['POST', 'admin/einstellungen', 'admin'],
        ['GET', 'admin/protokoll', 'admin'],
        ['GET', 'wart/getraenke/bestand', 'wart_getraenke'], ['GET', 'wart/kiosk/bestand', 'wart_kiosk'],
        ['GET', 'wart/getraenke/lieferung', 'wart_getraenke'], ['POST', 'wart/getraenke/lieferung', 'wart_getraenke'],
        ['GET', 'wart/getraenke/bewegung', 'wart_getraenke'], ['POST', 'wart/getraenke/bewegung', 'wart_getraenke'],
        ['GET', 'wart/kiosk/lieferung', 'wart_kiosk'], ['POST', 'wart/kiosk/lieferung', 'wart_kiosk'],
        ['GET', 'wart/kiosk/bewegung', 'wart_kiosk'], ['POST', 'wart/kiosk/bewegung', 'wart_kiosk'],
        ['GET', 'wart/getraenke/buchungen', 'wart_getraenke'], ['POST', 'wart/getraenke/buchungen/999999/storno', 'wart_getraenke'],
        ['GET', 'wart/getraenke/korrektur', 'wart_getraenke'], ['POST', 'wart/getraenke/korrektur', 'wart_getraenke'],
        ['GET', 'wart/kiosk/buchungen', 'wart_kiosk'], ['POST', 'wart/kiosk/buchungen/999999/storno', 'wart_kiosk'],
        ['GET', 'wart/kiosk/korrektur', 'wart_kiosk'], ['POST', 'wart/kiosk/korrektur', 'wart_kiosk'],
        ['GET', 'wart/getraenke/auszaehlung', 'wart_getraenke'], ['POST', 'wart/getraenke/auszaehlung', 'wart_getraenke'],
        ['GET', 'wart/kiosk/auszaehlung', 'wart_kiosk'], ['POST', 'wart/kiosk/auszaehlung', 'wart_kiosk'],
        ['GET', 'wart/getraenke/auszaehlungen', 'wart_getraenke'], ['GET', 'wart/getraenke/auszaehlungen/999999/download', 'wart_getraenke'],
        ['POST', 'wart/getraenke/auszaehlungen/999999/neu-erzeugen', 'wart_getraenke'],
        ['GET', 'wart/kiosk/auszaehlungen', 'wart_kiosk'], ['GET', 'wart/kiosk/auszaehlungen/999999/download', 'wart_kiosk'],
        ['POST', 'wart/kiosk/auszaehlungen/999999/neu-erzeugen', 'wart_kiosk'],
        ['GET', '/', 'umleitung'],
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function routenmatrix(): array
    {
        $faelle = [];

        foreach (self::ROUTEN as [$methode, $pfad, $klasse]) {
            foreach (['anonym', 'mitglied', 'getraenkewart', 'admin', 'tablet'] as $akteur) {
                $faelle["{$methode} {$pfad} als {$akteur}"] = [$methode, $pfad, $klasse, $akteur];
            }
        }

        return $faelle;
    }

    #[DataProvider('routenmatrix')]
    public function test_routenmatrix(string $methode, string $pfad, string $klasse, string $akteur): void
    {
        $this->uhrStellen(self::JETZT);
        $sitzung = $this->csrf();

        if (in_array($akteur, ['mitglied', 'getraenkewart', 'admin'], true)) {
            $person = $this->personAnlegen(['benutzername' => uniqid('z', true)]);

            if ($akteur !== 'mitglied') {
                $this->rolleGeben($person, $akteur);
            }

            $sitzung = [...$sitzung, ...$this->angemeldeteSitzung($person)];
        }

        if ($akteur === 'tablet') {
            $admin = $this->personAnlegen(['benutzername' => uniqid('z', true)]);
            $this->rolleGeben($admin, 'admin');
            $code  = (new FreischaltcodeModel())->erzeuge($admin, service('uhr')->jetzt());
            $token = service('geraete')->freischalten($code, 'Kühlschrank');
            $this->assertNotNull($token);
            service('superglobals')->setCookie('gl_geraet', $token);
        }

        $antwort  = $this->anfrage($methode, $pfad, $sitzung);
        $erwartet = $this->erwartung($klasse, $akteur);

        if (str_starts_with($erwartet, 'redirect:')) {
            $this->assertNotNull($antwort, "{$methode} {$pfad} als {$akteur} lieferte 404");
            $antwort->assertRedirectTo(site_url(substr($erwartet, 9)));

            return;
        }

        if ($erwartet === 'verboten') {
            $this->assertNotNull($antwort);
            $antwort->assertStatus(403);

            return;
        }

        // Filter lässt durch, der Controller kennt den (inaktiven) Bereich nicht: 404 (als Exception oder Status).
        if ($erwartet === 'nicht_gefunden') {
            $this->assertTrue($antwort === null || $antwort->getStatusCode() === 404, "{$methode} {$pfad} als {$akteur}: erwartet 404");

            return;
        }

        // durchgelassen: 404 aus der Fachlogik (Beispiel-ID) zählt als durchgelassen.
        if ($antwort === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNotSame(403, $antwort->getStatusCode(), "{$methode} {$pfad} als {$akteur}: unerwartet 403");

        if ($antwort->isRedirect()) {
            $ziel = $antwort->getRedirectUrl();

            $verboten = [];

            if ($akteur !== 'tablet') {
                $verboten[] = 'tablet';
            }

            if (! in_array($klasse, ['offen', 'abmelden'], true)) {
                $verboten[] = 'login';
            }

            if ($klasse !== 'frei_tablet') {
                $verboten[] = 'tablet/freischalten';
            }

            foreach ($verboten as $verbotenesZiel) {
                $this->assertNotSame(site_url($verbotenesZiel), $ziel, "{$methode} {$pfad} als {$akteur}: Redirect nach {$verbotenesZiel}");
            }
        }
    }

    /**
     * Neue Route ohne Matrixeintrag (oder verwaister Eintrag) macht den Sweep unvollständig: dann rot.
     */
    public function test_routenliste_entspricht_den_registrierten_routen(): void
    {
        $sammlung    = service('routes')->loadRoutes();
        $registriert = [];

        foreach ([...Router::HTTP_METHODS, '*'] as $verb) {
            foreach (array_keys($sammlung->getRoutes($verb, false)) as $muster) {
                $registriert[] = [$verb, trim((string) $muster, '/')];
            }
        }

        $passt = static fn (string $verb, string $muster, string $methode, string $pfad): bool => ($verb === '*' || $verb === $methode)
            && preg_match('#\A' . $muster . '\z#u', trim($pfad, '/')) === 1;

        foreach ($registriert as [$verb, $muster]) {
            $treffer = array_filter(self::ROUTEN, static fn (array $r): bool => $passt($verb, $muster, $r[0], $r[1]));
            $this->assertNotSame([], $treffer, "Route {$verb} {$muster} fehlt in der Zugriffsmatrix.");
        }

        foreach (self::ROUTEN as [$methode, $pfad]) {
            $treffer = array_filter($registriert, static fn (array $r): bool => $passt($r[0], $r[1], $methode, $pfad));
            $this->assertNotSame([], $treffer, "Matrixeintrag {$methode} {$pfad} hat keine Route.");
        }
    }

    /**
     * Review Focus 1: Das CSRF-Token bleibt pro Session gleich (mehrere Tabs/Formulare, Tablet-JSON nach Formular-POST).
     */
    public function test_csrf_token_wird_nach_post_nicht_regeneriert(): void
    {
        $this->assertFalse(config('Security')->regenerate);

        $id = $this->personAnlegen(['benutzername' => uniqid('z', true)]);
        $this->alsAngemeldet($id)->post('konto/pin', [...$this->csrf(), 'passwort_aktuell' => 'falsch', 'pin' => '1', 'pin_wiederholen' => '1'])
            ->assertRedirectTo(site_url('konto'));

        $this->assertSame('test-token', session('csrf_test_name'));
    }

    private function erwartung(string $klasse, string $akteur): string
    {
        return match ($klasse) {
            'offen', 'frei_tablet' => $akteur === 'tablet' ? 'redirect:tablet' : 'durchgelassen',
            'tablet'               => $akteur === 'tablet' ? 'durchgelassen' : 'redirect:tablet/freischalten',
            'angemeldet', 'abmelden' => match ($akteur) {
                'anonym' => 'redirect:login',
                'tablet' => 'redirect:tablet',
                default  => 'durchgelassen',
            },
            'admin' => match ($akteur) {
                'anonym'                    => 'redirect:login',
                'mitglied', 'getraenkewart' => 'verboten',
                'tablet'                    => 'redirect:tablet',
                default                     => 'durchgelassen',
            },
            // Recht je Bereich in der URL: der Getränkewart darf nur getraenke, der Kiosk-Bereich ist bis Stufe 3 inaktiv (404 für alle, die der Filter durchlässt).
            'wart_getraenke' => match ($akteur) {
                'anonym'   => 'redirect:login',
                'mitglied' => 'verboten',
                'tablet'   => 'redirect:tablet',
                default    => 'durchgelassen',
            },
            'wart_kiosk' => match ($akteur) {
                'anonym'                    => 'redirect:login',
                'mitglied', 'getraenkewart' => 'verboten',
                'tablet'                    => 'redirect:tablet',
                default                     => 'nicht_gefunden',
            },
            // Artikelbild: Anmeldung oder gültiges Tablet; sonst 403 (kein Redirect, wird per <img> geladen). Beispiel-ID fehlt → 404.
            'bild'      => $akteur === 'anonym' ? 'verboten' : 'nicht_gefunden',
            'umleitung' => 'redirect:buchen',
        };
    }

    /**
     * @param array<string, mixed> $sitzung
     */
    private function anfrage(string $methode, string $pfad, array $sitzung): ?TestResponse
    {
        try {
            $anfrage = $this->withSession($sitzung);

            return $methode === 'GET' ? $anfrage->get($pfad) : $anfrage->post($pfad, $this->csrf());
        } catch (PageNotFoundException) {
            return null;
        }
    }
}
