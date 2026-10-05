# VDSt Getränkeliste

## Zweck & Maßstab
Web-App für den Verein deutscher Studenten zu Erlangen: Jedes Mitglied bucht seinen
**eigenen Verbrauch** (Artikel und Menge) am Kühlschrank-Tablet oder am eigenen Handy.
Die App **zählt nur Verbrauch** — Rechnung, Zahlung und Schulden bleiben im Kassensystem
(Verbindung ausschließlich über Excel-Exporte). 30–100 Konten, Betrieb auf einem Raspberry
Pi 4. Spec (Autorität): `docs/superpowers/specs/2026-10-05-getraenkeliste-design.md`,
Plan: `docs/superpowers/plans/`. Das Tool bleibt bewusst klein.

## Stack
- CodeIgniter 4.7 (PHP 8.3), MySQL 8, PHPUnit 10
- Bootstrap 5 + Bootstrap Icons (kommen mit den Views)
- Docker: `getraenkeliste-web` (Port **8090**), `getraenkeliste-db` (kein Port nach außen),
  `getraenkeliste-phpmyadmin` (Port **8091**). Kein Mailpit, die App verschickt keine Mails.
- Zeitzone `Europe/Berlin` (App, PHP, MySQL-Container)

## Befehle
Auf diesem Rechner gibt es **kein Host-PHP/Composer** — alles im Web-Container:
- `docker compose up -d --build` — Container starten
- `docker exec getraenkeliste-web composer install` — Abhängigkeiten (Volume `getraenkeliste_vendor`)
- `docker exec getraenkeliste-web vendor/bin/phpunit` — alle Tests (Suites `unit`, `database`,
  `feature`); entspricht `composer test`
- `docker exec getraenkeliste-web php spark migrate` — Migrationen
- `docker exec getraenkeliste-web php spark routes` — Routenliste
- `docker exec -it getraenkeliste-web php spark admin:anlegen` — ersten Admin anlegen
- `docker exec getraenkeliste-web php -l <datei>` — Syntax-Check
- Git Bash: bei `docker run -v` den Pfad mit `MSYS_NO_PATHCONV=1` bzw. `$(pwd -W)` übergeben

## Workflow
- Hauptbranch ist **`main`**. Änderungen auf Feature-Branch, am Ende PR gegen `main`.
- Commit-Nachrichten auf Deutsch.
- **Doku aktuell halten**: Nach jeder abgeschlossenen Aufgabe diese `CLAUDE.md` (Landkarte,
  Befehle, Invarianten) auf den Stand des Codes bringen.
- `.gitattributes` erzwingt LF, damit in den Container gemountete Dateien unter Windows
  nicht mit CRLF ankommen.

## Architektur-Landkarte
Aufbau bisher:
- `app/Config/` — angepasst: `App` (Europe/Berlin, Locale `de`, kein `index.php` in URLs),
  `Security` (CSRF `session`), `Session`, `Cookie`, `Filters` (`csrf` global), `Database`
  (liest `DB_HOST/DB_NAME/DB_USER/DB_PASS` aus der Compose-Umgebung)
- `app/Database/Migrations/` — 000001 Grundtabellen (`bereiche`, `personen`, `person_rollen`,
  `anmelde_tokens`, `einstellungen`, `protokoll`), 000002 `kategorien`/`artikel`, 000003 `buchungen`,
  000004 `geraete`/`freischaltcodes`, 000005 Startdaten (Bereiche `getraenke` aktiv / `kiosk`
  inaktiv, Sammelkonten Couleur/Bund, Einstellungs-Defaults, `inbetriebnahme_at` = jetzt).
  Raw-SQL, InnoDB, utf8mb4_unicode_ci, FKs `ON DELETE RESTRICT`. Migrationen referenzieren
  **keine** App-Klassen (Konstanten werden wiederholt; `MigrationTest` prüft sie gegen die App).
- `app/Models/` — CI4-Models (`array`, `$useTimestamps`): `PersonModel` (`rollen`,
  `findeAktivNachBenutzername`, `sammelkontoId`, `istAktiv`), `BereichModel::aktive`,
  `AnmeldeTokenModel::loescheFuerPerson`, `ArtikelModel::buchbar/findeBuchbar`, dazu schlanke
  Models für Kategorie, Buchung, Gerät, Freischaltcode, Einstellung, Protokoll, PersonRolle.
- `app/Libraries/Uhr.php` + `Config/Services.php` — Shared Services `uhr()`, `einstellungen()`,
  `protokollierer()`. **Jede zeitabhängige Stelle holt „jetzt“ über `service('uhr')->jetzt()`**
  (`DateTimeImmutable`, Europe/Berlin), nie `new DateTime()`/`time()`. `Einstellungen` (`int`, `text`,
  `inbetriebnahme`, `setze` → Fehlertext|null, protokolliert alt/neu) lädt die Werte je Request einmal.
  `Protokollierer::schreibe` entfernt oberste-Ebene-Schlüssel auf `_hash`, speichert JSON ohne
  Unicode-Escapes (MySQL normalisiert beim Lesen zu `{"wert": "10"}` → in Tests dekodiert vergleichen).
- `app/Libraries/BuchungService.php` (Service `buchungen()`, wirft `BuchungAbgelehnt` mit deutscher UI-Meldung):
  `bucheVorgang(vorgangId UUIDv4, kontoId, gebuchtVonId, geraetId, quelle web|tablet, positionen)` — bekannte `vorgang_id`
  wird **vor** jeder Fachprüfung beantwortet (gespeichertes Ergebnis, `wiederholt=true`; Konto UND gebucht_von müssen passen,
  sonst „Ungültiger Vorgang.“); sonst alles-oder-nichts in einer manuellen Transaktion (`transBegin/Commit/Rollback`), ein
  `gebucht_at` je Vorgang, aktueller Preis; Unique-Verletzung im Wettlauf → Rollback + gespeichertes Ergebnis. Doppelte
  Artikel werden addiert (Summe ≤ 99), max. 30 Positionen. `storniereVorgang/storniereBuchung` prüfen Doppel-Storno, Frist
  (`storno_frist_min` ab `gebucht_at`) und `ZeitraumErmittler::istEingefroren` (Stichtag in Stufe 1 noch `null`); **wer**
  stornieren darf, entscheidet der Controller. `zusammenfassung()` ist statisch/rein. **Transaktionen laufen über `transaktion()` mit `transException(true)`: CI4 wirft in Transaktionen sonst nicht, ein fehlgeschlagener Query würde still Teilergebnisse committen.** Storno-Update mit `storniert_at IS NULL` + `affectedRows`. Ergebnis enthält `storniert` (Replay eines inzwischen stornierten Vorgangs).
- `tests/_support/DbTestCase.php` — Basisklasse für DB-Tests (Migrationen laufen vor jedem
  Test frisch gegen `getraenkeliste_test`); Helfer `personAnlegen`, `rolleGeben`,
  `artikelAnlegen`, `alsAngemeldet`, `csrf`, `uhrStellen('Y-m-d H:i:s')` (fixiert `service('uhr')`;
  `tearDown` setzt alle Services zurück, damit Mocks/Einstellungs-Cache nicht lecken) (Passwort-Hashes mit Kosten 4 für Tempo)
- `app/Libraries/Anmeldung.php` (Service `anmeldung()`) — Login am eigenen Gerät:
  `pruefePasswort` prüft **zuerst** die Sperre (`PinSperre`, `login_fehlversuche`/`login_gesperrt_bis`),
  dann das Passwort; unbekannte/archivierte Person = dieselbe Meldung wie falsches Passwort.
  `anmelden` (`regenerate(true)`), `abmelden` (Session leeren), `person()` (nicht archiviert, sonst
- „Angemeldet bleiben“: Cookie `gl_merken` = `<selector 24 hex>:<validator 64 hex>` (90 Tage, httponly, Lax, `secure` aus ConfigCookie),
  DB nur `sha256(validator)` (`AnmeldeTokenModel::erzeuge/rotiere/loescheCookie/loescheFuerPerson`). Filter `angemeldet` stellt
  ohne Session per `Anmeldung::ausCookieAnmelden` her (rotiert; archivierte Person → alle Tokens weg; falscher Validator → alle
  Tokens der Person weg). **Cookie-Änderungen wendet `AnmeldungFilter::after()` auf die gesendete Antwort an; `->withCookies()` nur für Redirects aus `before()` und ungefilterte Routen (login)** (RedirectResponse ist eine neue
  Response). Archivieren/Passwort-Reset müssen `loescheFuerPerson` + `merkCookieLoeschen` aufrufen. Tests setzen den Cookie über
  `service('superglobals')->setCookie(...)`, nicht `$_COOKIE`.
  Abmeldung → Filter leitet zu `login`), `rollen()`.
- `app/Filters/` — `angemeldet` (AnmeldungFilter, per Routengruppe in `Routes.php`, nicht global) und
  `recht:<aktion>` (RechtFilter → 403 `errors/keine_berechtigung`); `csrf` bleibt global.
- Routen: `GET/POST login`, `POST logout` (kein GET → 404), `/` → Redirect `buchen`,
  `GET buchen`, `POST buchen`, `POST buchen/rueckgaengig` (Filter `angemeldet` + `recht:buchen`). `AuthController`, `BuchenController`.
- Buchen am eigenen Gerät (`BuchenController`): JSON-Endpunkte, CSRF per Header `X-CSRF-TOKEN` (ohne gültigen Token
  `SecurityException` → 403; im Test fängt man die Exception). Body via `getJSON(true)`; `artikel_id`/`menge` werden
  streng zu int gecastet (sonst 422 „Ungültige Position“). `konto` = `ich|couleur|bund`; `gebucht_von_id` immer die
  angemeldete Person. Antworten tragen `csrf_hash`, Erfolg zusätzlich `naechste_vorgang_id`
  (`BuchungService::neueVorgangId()`, UUID v4). Wiederholung eines stornierten Vorgangs → 200 `storniert:true`.
  Rückgängig nur für `konto_id`/`gebucht_von_id` = angemeldete Person, sonst 403. `public/js/buchen.js` liest
  Endpunkte/Vorgang-ID/Token aus `data-*` an `#buchen-app` (auch für das Tablet gedacht). `RechtFilter` ohne Argument → 403.
- Meine Buchungen (`MeineBuchungenController`): `GET meine-buchungen` (`angemeldet` + `recht:buchen`), `POST meine-buchungen/storno/(:num)`
  (`recht:eigene_stornieren`). Zeitraum Stufe 1 = ab `Einstellungen::inbetriebnahme()`. `BuchungModel::fuerKonto`,
  `vonPersonAufSammelkonten` (nur Sammelkonten, `gebucht_von_id` = Person), `offenerBetrag` (Cent, ohne stornierte). Je aktivem
  Bereich eine Tabelle; Sammelkonto-Buchungen separat und nicht im Betrag. Storno erlaubt für `konto_id`/`gebucht_von_id` = ich,
  sonst (auch unbekannte ID) 403; Redirect mit Flash success/error (BuchungAbgelehnt-Text); kein Protokolleintrag.
- Pflichtseite/Konto (`KontoController`): `PersonModel::brauchtEinrichtung` (Passwort bei `passwort_wechsel_erzwingen`,
  PIN bei `pin_hash` NULL). Filter `angemeldet` leitet dann jede Route zu `konto/einrichten` um, außer Routen mit
  Filter-Argument `angemeldet:frei` (`konto/einrichten` GET/POST, `logout`). `GET konto`, `POST konto/passwort`,
  `POST konto/pin` (verlangen aktuelles Passwort). Passwortwechsel löscht alle Remember-Tokens + `regenerate`;
  PIN-Wechsel setzt `pin_fehlversuche`/`pin_gesperrt_bis` zurück. Nicht protokolliert (eigene Änderungen).
- Views: `layouts/main.php` (Sidebar, „Verwaltung“ nur für `admin`), `layouts/einfach.php`
  (standalone: Login, Pflichtseite, Tablet), Partials `layouts/kopf.php` (Theme-Skript, CSS/CDN) und
  `layouts/meldungen.php` (Flash `success`/`error`). `public/css/app.css` + `img/vdst-logo.svg`
  aus dem Kassensystem (plus `@media print` am Ende von app.css); `public/js/app.js`: Theme-Toggle, `js-auto-dismiss`, `data-print` (Drucken).
- `php spark admin:anlegen` — fragt Vorname, Nachname, Benutzername, Passwort (2x) ab, prüft mit
  `Anmelderegeln`, ruft `PersonModel::legeAdminAn` (Mitglied, aktiv, Rolle admin, PIN leer).
- `codeigniter4/translations` liefert deutsche Framework-Meldungen (Locale `de`); eigene Texte sind Literale.
- Tests: `MockSession` tauscht die Session-ID nicht; `test_session_id_wechselt_beim_login` prüft
  `didRegenerate`. Login-POSTs in Feature-Tests: `withSession(['csrf_test_name'=>…])->post('login', [...csrf(), …])`.
- `docker/mysql-init/01-testdb.sql` — legt die Testdatenbank an (nur beim ersten Start des
  MySQL-Volumes; bei bestehendem Volume manuell nachholen)

- Admin Personen (`/admin/*`, Filter `angemeldet` + `recht:admin`, Namespace `App\Controllers\Admin`): `PersonenController` (Liste mit Filter
  gruppe/archiviert, Anlegen, Bearbeiten + Rollen-Checkboxen, `passwort-reset`, `pin-reset`, `archivieren`, `einmalpasswoerter`),
  `PersonenImportController` (CSV: `vorschau` legt die geparsten Zeilen in die Session `import_zeilen`, `ausfuehren` prüft Benutzername/Name
  in der Transaktion erneut und überspringt inzwischen Vergebene). `CsvPersonenParser` rein (BOM, Windows-1252, CRLF, Leerzeilen).
  Sammelkonten sind in der Admin-Personenverwaltung 404. Admin kann sich nicht selbst archivieren oder die Admin-Rolle entziehen.
  **Einmal-Passwörter nur als Flash `einmalpasswoerter` (PRG), nie im Protokoll** (Protokoll kennt nur `passwort_reset` ohne Werte).
  `PersonModel::transaktion()` = Transaktion mit `transException(true)` (R12), `legeMitgliedAn`, `mitglieder`, `benutzernameVergeben`.
  Upload-Prüfung ohne `isValid()` (Tests setzen `service('superglobals')->setFilesArray`).

- Admin Kategorien/Artikel (`/admin/stammdaten`, Nav „Getränke & Preise“): `StammdatenController`. Nur **aktive Bereiche**; alles in einem
  inaktiven Bereich (Kiosk) ist 404 (Anlegen/Ändern/Verschieben/Archivieren). Archivierte nur mit `?archiviert=ja`; Archivierte sind nicht
  bearbeitbar, Entarchivieren gibt es nicht. Kategorie archivieren archiviert Artikel nicht (sind aber nicht buchbar). Artikel-Umzug nur in
  aktive, nicht archivierte Kategorien, danach ans Ende (`naechsteSortierung`). Preis: `betrag_in_cent` (max. 7 Euro-Stellen), Preis 0 erlaubt;
  Protokoll `preis_geaendert` (alt/neu `preis_cent`) getrennt von `geaendert`. **Sortieren (`verschiebe`) nicht protokolliert**; `Sortierung::tausche`
  + lückenlose Neunummerierung (robust gegen Gleichstände), Rand = No-op. `Transaktion`-Trait (`transaktion()` mit transException) in Person-/Kategorie-/ArtikelModel.

- Tablets (`TabletController`, `Admin\TabletsController`, `Libraries\Geraete` = Service `geraete`): Admin erzeugt unter
  `admin/tablets` einen 8-stelligen Freischaltcode (`FreischaltcodeModel::erzeuge`, `random_int`, 15 Min gültig, DB nur
  sha256; Klartext nur als Flash `freischaltcode`, Protokoll `freischaltcode_erzeugt` ohne Code). `tablet/freischalten`
  (ohne Login; Felder `code`, `name`): Eingabe ohne Leerzeichen; `FreischaltcodeModel::loeseEin` = bedingtes UPDATE
  (`eingeloest_at IS NULL AND gueltig_bis >= jetzt`, `affectedRows() === 1`) in einer `Transaktion` mit dem Geräte-Insert
  → Code einmalig, auch bei Parallelität. Einheitliche Meldung „Code ungültig oder abgelaufen.“. Token = 32 Byte hex,
  Cookie `gl_geraet` (httponly, Lax, `Cookie::$secure`, 400 Tage), DB nur sha256. Filter `tablet` (`GeraetFilter`):
  `before` setzt Request-Zustand, `after` setzt das Cookie bei **jeder** Antwort neu (gleitend, auch bei Redirects, ohne
  `withCookies`); Argument `frei` (Freischaltseite) leitet freigeschaltete Tablets zu `tablet`. Gesperrtes Tablet auf
  Tablet-Routen: 403 `tablet/gesperrt`. Nicht-Tablet-Routen: `angemeldet` und `kein_tablet` (nur `login`) leiten ein
  **gültiges, nicht gesperrtes** Geräte-Cookie zu `tablet`; ein **gesperrtes** Gerät bekommt dort die 403-Seite
  „Dieses Tablet wurde gesperrt.“ (`Geraete::nichtTabletAntwort`); ein unbekanntes/ungültiges Cookie zählt als „kein Gerät“.
  Erfolgreiches Freischalten beendet den persönlichen Login im Browser (`merkenBeenden` + `abmelden`; `GeraetFilter::after`
  wendet auch die `gl_merken`-Löschung an). Admin: umbenennen, sperren (kein Entsperren),
  Protokoll `umbenannt`/`gesperrt`. **Bekannte Grenze Stufe 1:** keine Drosselung der Code-Eingabe (10^8 Codes, 15 Min).

- Tablet-Betrieb (Task 15, `TabletController`): `tablet` = Namenskacheln (`PersonModel::tabletKacheln`: fest Couleur/Bund, `zuletzt` = 12 aktive
  Mitglieder nach `MAX(gebucht_at)` über alle Buchungen auf ihr Konto inkl. stornierter, `alle`; Suche/Filter Zuletzt/Aktive/AHs/Alle clientseitig
  in `public/js/tablet.js`, Seite lädt im Leerlauf nach 10 min neu); Aufruf leert die Tablet-Sitzung. Tablet-Session-Keys **getrennt** vom
  Login: `tablet_konto_id`, `tablet_seit`, `tablet_letzter_vorgang` (nie `person_id`); alle `tablet/*`-Routen nur hinter `tablet` + `tablet_csrf`
  (nicht `angemeldet`). Sitzung gilt höchstens 300 s ab `tablet_seit` (`uhr`), danach 401 `Sitzung abgelaufen …` bzw. Redirect zu `tablet`.
  `tablet/waehlen/{id}` (POST-Kachel): Sammelkonto → `tablet/buchen`; Mitglied ohne PIN/archiviert → zurück mit Meldung; sonst `tablet/pin/{id}`.
  PIN: `PinSperre::istGesperrt` **vor** `password_verify` (gesperrt → abgelehnt, Zähler unverändert), 4–6 Ziffern, falsch → `nachFehlversuch`,
  Erfolg → Zähler zurück; Einzel-`update` mit Rückgabeprüfung (fail closed). Buchen: `quelle='tablet'`, `geraet_id` aus Cookie, `gebucht_von_id` =
  Person bzw. NULL bei Sammelkonto, `konto` im Body wird ignoriert; Rückgängig nur für `tablet_letzter_vorgang` (sonst 403). Gemeinsame
  JSON-Logik (Positionsprüfung, Antwortformat) in Trait `Controllers\Concerns\BuchungsAntworten` (auch `BuchenController`).
  `buchen/index.php` hat Modus `web|tablet` (Tablet: `layouts/einfach` mit Section `seitenklasse` = `login-breit`, `data-fertig-url`,
  `data-timeout-s`); `buchen.js` geht bei Leerlauf (`timeout_s`) und nach Bestätigung (min(`timeout_s`,10) s) per Formular-POST `tablet/fertig`.
  **CSRF am Tablet:** `tablet/*`-POSTs sind vom globalen `csrf` ausgenommen (`Config\Filters`); `TabletCsrfFilter` leitet Formular-POSTs mit
  ungültigem Token zu `tablet` mit Flash (statt `redirect()->back()`), JSON/AJAX bleibt 403. Neue Tablet-Routen gehören in die Gruppe `tablet` in `Routes.php`.

## Konventionen & Invarianten
- Geheimnisse und Infrastruktur nur in `.env`; `app.baseURL` und `cookie.secure` nur dort. `App::$baseURL` defaultet auf `http://localhost:8090/` (Dev); auf dem Pi muss `.env` `app.baseURL` setzen (Compose-Env erreicht CI nicht).
- `Security::$regenerate = false` ist Pflicht (doppeltes Absenden mit demselben CSRF-Token
  muss idempotent bleiben); `Security::$redirect = true` — Formular-POST ohne gültiges
  CSRF-Token wird zurückgeleitet statt 403 (JSON/AJAX bekommt 403).
- Session läuft nach 8 Stunden ab (`Session::$expiration = 28800`).
- `phpunit.xml.dist` trägt das Test-DB-Passwort als Literal (= Compose-Default
  `getraenkepass123`); phpunit expandiert keine `${DB_PASS}`.
- Alle Meldungen auf Deutsch.

## Bewusste Abweichungen vom Kassensystem (Spec Abschnitt 3)
- Rollen und Mehrbenutzerbetrieb sind der Zweck dieser App.
- Tabelle `einstellungen`, beschränkt auf vom Admin änderbare Betriebswerte.
- Beträge als Cent-INT statt DECIMAL; Komma-Eingabe über `normalisiere_betrag()`.
