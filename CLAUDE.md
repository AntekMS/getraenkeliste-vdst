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
Bisher nur das Gerüst:
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
- `tests/_support/DbTestCase.php` — Basisklasse für DB-Tests (Migrationen laufen vor jedem
  Test frisch gegen `getraenkeliste_test`); Helfer `personAnlegen`, `rolleGeben`,
  `artikelAnlegen`, `alsAngemeldet`, `csrf`, `uhrStellen('Y-m-d H:i:s')` (fixiert `service('uhr')`;
  `tearDown` setzt alle Services zurück, damit Mocks/Einstellungs-Cache nicht lecken) (Passwort-Hashes mit Kosten 4 für Tempo)
- `docker/mysql-init/01-testdb.sql` — legt die Testdatenbank an (nur beim ersten Start des
  MySQL-Volumes; bei bestehendem Volume manuell nachholen)

## Konventionen & Invarianten
- Geheimnisse und Infrastruktur nur in `.env`; `app.baseURL` und `cookie.secure` nur dort.
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
