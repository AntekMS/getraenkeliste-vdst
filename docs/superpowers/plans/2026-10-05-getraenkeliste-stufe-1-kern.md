# Getränkeliste VDSt – Stufe 1 (Kern) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine lauffähige Getränkeliste, mit der Mitglieder am eigenen Gerät und am Kühlschrank-Tablet ihren Verbrauch buchen und stornieren und ein Admin Personen, Artikel, Tablets und Einstellungen über die Oberfläche pflegt.

**Architektur:** CodeIgniter-4-App im eigenen Docker-Compose-Projekt. Fachentscheidungen liegen in reinen Klassen ohne DB (`Berechtigung`, `PinSperre`, `StornoFrist`, `ZeitraumErmittler`, `CsvPersonenParser`, …), die DB-Logik in wenigen Libraries (`Anmeldung`, `BuchungService`, `Protokollierer`, `Einstellungen`), Controller bleiben dünn. Zugriffsschutz läuft ausschließlich über Filter, die `Berechtigung` fragen.

**Tech Stack:** CodeIgniter 4.7, PHP 8.3 (php:8.3-apache), MySQL 8.0, PHPUnit (Version aus dem CI-4.7-appstarter), Bootstrap 5.3 + Bootstrap Icons per CDN, Vanilla-JS. PhpSpreadsheet und Chart.js kommen erst in Stufe 2/3.

**Spec:** `docs/superpowers/specs/2026-10-05-getraenkeliste-design.md` (freigegeben). Referenz für Konventionen: `../kassensystem-vdst/CLAUDE.md` (Branch `small`).

## Global Constraints

- Arbeit auf Branch `feature/stufe-1-kern` (von `main`), am Ende PR gegen `main`. Commit-Nachrichten auf Deutsch, Trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Kein Host-PHP/Composer: alles im Container (`docker exec getraenkeliste-web …`). Container: `getraenkeliste-web` (Port **8090**), `getraenkeliste-db` (kein Port nach außen), `getraenkeliste-phpmyadmin` (Port **8091**). Kein Mailpit.
- Zeitzone `Europe/Berlin` (App, PHP, MySQL-Container); alle Zeitpunkte als Serverzeit `DATETIME`.
- Geldbeträge als **Cent (INT)**. Eingaben mit Komma über `normalisiere_betrag()` (wörtlich aus dem Kassensystem), dann `betrag_in_cent()`.
- Artikel und Personen werden **archiviert** (`archiviert_at`), Buchungen **storniert** – nie gelöscht. Alle Tabellen haben `created_at`/`updated_at`.
- Geheimnisse und Infrastruktur nur in `.env`; Tabelle `einstellungen` nur für vom Admin änderbare Betriebswerte.
- Passwörter, PINs, Geräte-Tokens, Remember-Tokens und Freischaltcodes nie im Klartext speichern (`password_hash` für Passwort/PIN, `hash('sha256')` für zufällige Tokens/Codes).
- Design: `public/css/app.css` und `public/img/vdst-logo.svg` **unverändert** aus `../kassensystem-vdst` kopieren; neue Klassen nur am Ende von `app.css` in einem Block `/* Getränkeliste */`, nur mit vorhandenen Tokens. Keine `<style>`-Blöcke und keine Inline-Styles in Views (Ausnahme: `style="display:none"` für JS-Toggles). Genau ein `.btn-vdst` pro Seite, Sekundäraktionen `.btn-outline-vdst`. Bootstrap Icons statt Emojis, Icon-only-Buttons mit `title` + `aria-label`.
- Ausgaben in Views immer `esc()`. Schreibende Aktionen nur per POST mit `csrf_field()`; Logout ist POST.
- Alle Meldungen auf Deutsch.
- Migrationen nie an lebenden App-Code koppeln (Konstanten in der Migration selbst wiederholen).
- Nach jedem Task: `CLAUDE.md` (Architektur-Landkarte, Befehle, Invarianten) auf Stand bringen; Task 1 legt sie an.

## Review Focus

1. **Doppeltes Absenden / WLAN-Wiederholung mit demselben Formular:** Der zweite POST mit derselben `vorgang_id` (und demselben, nicht rotierten CSRF-Token) liefert das gespeicherte Ergebnis und bucht nicht doppelt. Dafür ist `Config\Security::$regenerate = false` Pflicht. Test in Task 9.
2. **Tablet über Nacht im Leerlauf:** Die Session ist abgelaufen, das erste Antippen am Morgen darf keinen 403-Bildschirm zeigen. Die Namensauswahl lädt sich im Leerlauf alle 10 Minuten neu, ein CSRF-Fehler bei Formular-POSTs leitet mit Hinweis zurück (`$redirect = true`). Test in Task 15.
3. **CSV aus Excel:** Windows-1252-Kodierung, UTF-8-BOM, `\r\n`, Leerzeilen am Ende und Groß-/Kleinschreibung bei `gruppe` dürfen weder Umlaute zerstören noch Zeilen verwerfen. Test in Task 12.
4. **Archivierte Person mit offener Sitzung oder Remember-Cookie:** Beim nächsten Request ist sie abgemeldet, ihr Token gelöscht, am Tablet nicht wählbar. Test in Task 6.
5. **Tablet-Cookie läuft still ab:** Browser kappen Cookie-Laufzeiten (Chrome: 400 Tage). Das Geräte-Cookie wird bei jedem Request mit 400 Tagen Laufzeit neu gesetzt (gleitend). Test in Task 14.

---

## Dateistruktur

```
Dockerfile, docker-compose.yml, docker/mysql-init/01-testdb.sql, env, .gitignore, phpunit.xml.dist
app/Config/{App,Security,Session,Cookie,Filters,Routes,Services,Autoload}.php   (angepasst)
app/Helpers/betrag_helper.php                    normalisiere_betrag, betrag_in_cent, formatiere_cent
app/Libraries/  rein:  Berechtigung, PinSperre, Anmelderegeln, EinmalPasswort, StornoFrist,
                       ZeitraumErmittler, EinstellungDefinition, CsvPersonenParser
                DB:    Anmeldung, Geraete, BuchungService, BuchungAbgelehnt (Exception),
                       Protokollierer, Einstellungen
app/Models/     BereichModel, PersonModel, PersonRolleModel, AnmeldeTokenModel, KategorieModel,
                ArtikelModel, BuchungModel, GeraetModel, FreischaltcodeModel, EinstellungModel, ProtokollModel
app/Filters/    AnmeldungFilter ('angemeldet'), RechtFilter ('recht'), GeraetFilter ('tablet')
app/Controllers/ AuthController, KontoController, BuchenController, MeineBuchungenController,
                 TabletController, Admin/{Personen,PersonenImport,Stammdaten,Tablets,Einstellungen,Protokoll}Controller
app/Commands/AdminAnlegen.php
app/Database/Migrations/2026-10-05-0000NN_*.php
app/Views/layouts/{main,einfach}.php, auth/, konto/, buchen/, meine_buchungen/, tablet/, admin/…
public/css/app.css, public/img/vdst-logo.svg, public/js/app.js, public/js/buchen.js
tests/_support/DbTestCase.php, tests/unit/*, tests/database/*, tests/feature/*
```

---

### Task 1: Projektgerüst, Docker, Test-Infrastruktur

**Files:**
- Create: CI-4.7-appstarter im Repo-Wurzelverzeichnis, `Dockerfile`, `docker-compose.yml`, `docker/mysql-init/01-testdb.sql`, `env`, `.gitignore`, `CLAUDE.md`, `README.md`
- Modify: `composer.json`, `phpunit.xml.dist`, `app/Config/App.php`, `app/Config/Security.php`, `app/Config/Session.php`, `app/Config/Cookie.php`
- Test: `tests/unit/HealthTest.php`, `tests/_support/DbTestCase.php`, `tests/database/DbVerbindungTest.php`

**Interfaces:**
- Produces: `Tests\Support\DbTestCase` (erweitert `CIUnitTestCase`, nutzt `DatabaseTestTrait` + `FeatureTestTrait`, `$migrate = true`, `$refresh = true`, `$namespace = null`) mit Helfern, die spätere Tasks füllen (siehe Task 4/5).

- [ ] **Step 1: Gerüst erzeugen.** `docker run --rm -v "$PWD":/app -w /app composer:2 create-project codeigniter4/appstarter _ci "^4.7"`, Inhalt von `_ci/` ins Wurzelverzeichnis verschieben, `_ci/` löschen. In `composer.json`: `"name": "antekms/getraenkeliste-vdst"`, `"config": {"platform": {"php": "8.3"}}`, `"scripts": {"test": "phpunit"}`.
- [ ] **Step 2: Docker.** `Dockerfile` nach Vorbild `../kassensystem-vdst/Dockerfile`, aber `FROM php:8.3-apache`, ohne gd/dompdf-Abhängigkeiten, plus `ENV TZ=Europe/Berlin` und `date.timezone=Europe/Berlin` in einer ini, `ServerName localhost:8090`. `docker-compose.yml` nach Vorbild Kassensystem: Services `getraenkeliste-web` (`8090:80`, Volume `getraenkeliste_vendor:/var/www/html/vendor`, env `DB_HOST/DB_NAME=getraenkeliste/DB_USER=getraenkeuser/DB_PASS`, `CI_ENVIRONMENT`, `app.baseURL=${APP_BASEURL:-http://localhost:8090/}`), `getraenkeliste-db` (`mysql:8.0`, `TZ=Europe/Berlin`, Volume `getraenkeliste_mysql_data`, `./docker/mysql-init:/docker-entrypoint-initdb.d:ro`), `getraenkeliste-phpmyadmin` (`8091:80`). `01-testdb.sql` legt `getraenkeliste_test` an und gibt `getraenkeuser` alle Rechte darauf. `.gitignore` zusätzlich: `.env`, `docker-compose.override.yml`, `writable/exporte/*`.
- [ ] **Step 3: Konfiguration.** `app/Config/App.php`: `$appTimezone = 'Europe/Berlin'`, `$defaultLocale = 'de'`, `$indexPage = ''`. `Security.php`: `$csrfProtection = 'session'`, `$tokenRandomize = false`, `$regenerate = false` (Review Focus 1), `$redirect = true` (Review Focus 2). Filter `csrf` global `before` aktivieren. `Session.php`: `$expiration = 28800`. `Cookie.php`: `$httponly = true`, `$samesite = 'Lax'`, `$secure` bleibt `false` und wird nur über `.env` (`cookie.secure`) gesetzt. `env`: kommentierte Beispiele für `app.baseURL`, `cookie.secure`, `database.default.*`, `database.tests.*`.
- [ ] **Step 4: phpunit.xml.dist.** `<env>`-Einträge `CI_ENVIRONMENT=testing`, `database.tests.hostname=getraenkeliste-db`, `database.tests.database=getraenkeliste_test`, `database.tests.username=getraenkeuser`, `database.tests.password=${DB_PASS}`-Default aus compose, `database.tests.DBDriver=MySQLi`, `database.tests.DBPrefix=` (leer). Testsuites `unit`, `database`, `feature`.
- [ ] **Step 5: Tests schreiben.** `HealthTest::test_app_laeuft_in_berliner_zeit()` prüft `date_default_timezone_get() === 'Europe/Berlin'`. `DbVerbindungTest::test_testdatenbank_erreichbar()` prüft `db_connect('tests')->getDatabase() === 'getraenkeliste_test'`.
- [ ] **Step 6: Hochfahren und testen.** `docker compose up -d --build`, dann `docker exec getraenkeliste-web composer install` (mit Dev-Abhängigkeiten), dann `docker exec getraenkeliste-web vendor/bin/phpunit`. Erwartet: 2 Tests grün. `curl -s -o /dev/null -w "%{http_code}" http://localhost:8090/` liefert 200; `http://localhost:8091/` zeigt phpMyAdmin.
- [ ] **Step 7: CLAUDE.md und README anlegen.** CLAUDE.md mit Abschnitten wie im Kassensystem (Zweck & Maßstab, Stack, Befehle, Workflow – Hauptbranch `main`, Feature-Branch + PR –, Architektur-Landkarte, Konventionen & Invarianten, bewusste Abweichungen vom Kassensystem aus Spec Abschnitt 3). README: Start lokal, Port 8090/8091, Pi-Hinweis „phpMyAdmin per lokaler `docker-compose.override.yml` abschalten“.
- [ ] **Step 8: Commit** – `git add -A && git commit -m "Projektgerüst: CI 4.7, Docker (8090/8091), Test-Infrastruktur"`

---

### Task 2: Betrags-Helper

**Files:**
- Create: `app/Helpers/betrag_helper.php`
- Modify: `app/Config/Autoload.php` (`$helpers = ['betrag']`)
- Test: `tests/unit/BetragTest.php`

**Interfaces:**
- Produces: `normalisiere_betrag(?string $eingabe): ?string` (wörtlich aus `../kassensystem-vdst/app/Helpers/label_helper.php`), `betrag_in_cent(?string $eingabe): ?int`, `formatiere_cent(int $cent): string`.

- [ ] **Step 1: Failing tests** (Datenanbieter):
  - `betrag_in_cent`: `'1,50'→150`, `'1.50'→150`, `'2'→200`, `'0,5'→50`, `'1.000,00'→100000`, `'1.000'→100000`, `' 3,20 '→320`, `'0'→0`; ungültig → `null`: `''`, `'abc'`, `'1,234'`, `'-1,00'`, `null`.
  - `formatiere_cent`: `650→'6,50 €'`, `0→'0,00 €'`, `123456→'1.234,56 €'`, `-120→'-1,20 €'`.
  - `normalisiere_betrag`: Fälle aus `../kassensystem-vdst/tests/unit/BetragTest.php` übernehmen.
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/unit/BetragTest.php` → FAIL (Funktionen fehlen).
- [ ] **Step 3: Implementieren.** `betrag_in_cent` rechnet **ohne float**: nach `normalisiere_betrag` Regex `^\d+(\.\d{1,2})?$`, dann Euro- und Cent-Teil als Ganzzahlen zusammensetzen.
- [ ] **Step 4:** Test erneut → PASS.
- [ ] **Step 5: Commit** – `"Betrags-Helper: Komma-Eingabe in Cent, Cent-Formatierung"`

---

### Task 3: Reine Fachklassen – Berechtigung, Sperren, Fristen, Zeitraum

**Files:**
- Create: `app/Libraries/{Berechtigung,PinSperre,Anmelderegeln,EinmalPasswort,StornoFrist,ZeitraumErmittler,EinstellungDefinition}.php`
- Test: `tests/unit/{BerechtigungTest,PinSperreTest,AnmelderegelnTest,EinmalPasswortTest,StornoFristTest,ZeitraumErmittlerTest,EinstellungDefinitionTest}.php`

**Interfaces (Produces, alle `final` mit statischen Methoden):**
- `Berechtigung::darf(array $rollen, string $aktion, ?string $bereich = null): bool` – wirft `InvalidArgumentException` bei unbekannter Aktion. Konstanten: `ROLLEN = ['mitglied','getraenkewart','kioskwart','kassenwart','admin']`, `WART_BEREICH = ['getraenkewart' => 'getraenke', 'kioskwart' => 'kiosk']`, Aktionen `BUCHEN`, `EIGENE_STORNIEREN`, `BESTAND_PFLEGEN`, `BUCHUNGEN_VERWALTEN`, `AUSZAEHLUNG_DURCHFUEHREN`, `AUSZAEHLUNG_ANSEHEN`, `STATISTIK_ANSEHEN`, `SPENDEN_ANSEHEN`, `SPENDEN_PFLEGEN`, `ADMIN` (String-Werte = Kleinbuchstaben-Namen).
- `PinSperre::MAX_FEHLVERSUCHE = 5`, `SPERRE_MINUTEN = 5`; `istGesperrt(?DateTimeImmutable $gesperrtBis, DateTimeImmutable $jetzt): bool`; `nachFehlversuch(int $bisherigeFehlversuche, DateTimeImmutable $jetzt): array{fehlversuche:int, gesperrt_bis:?DateTimeImmutable}`. Gilt für PIN **und** Login.
- `Anmelderegeln::passwortFehler(string $passwort): ?string` (mind. 8 Zeichen), `pinFehler(string $pin): ?string` (`^\d{4,6}$`), `benutzernameNormalisieren(string $roh): ?string` (trim, lowercase, `^[a-z0-9._-]{3,40}$`, sonst `null`).
- `EinmalPasswort::erzeuge(int $laenge = 10): string` – `random_int`, Alphabet ohne `0O1lI`.
- `StornoFrist::istOffen(DateTimeImmutable $gebuchtAt, DateTimeImmutable $jetzt, int $fristMinuten): bool` – Grenze inklusiv.
- `ZeitraumErmittler::gehoertZumLaufendenZeitraum(DateTimeImmutable $zeitpunkt, DateTimeImmutable $inbetriebnahme, ?DateTimeImmutable $letzterStichtag): bool` (ohne Stichtag: `>= inbetriebnahme`; mit: `> stichtag`) und `istEingefroren(DateTimeImmutable $zeitpunkt, ?DateTimeImmutable $letzterStichtag): bool` (`<= stichtag`). In Stufe 1 ist `$letzterStichtag` immer `null`; Stufe 2 liefert ihn.
- `EinstellungDefinition::DEFINITIONEN` – `storno_frist_min` (int 0–120, Default `10`), `tablet_timeout_s` (int 10–600, `30`), `vereinsname` (Text 1–100, `Verein deutscher Studenten zu Erlangen`), `erinnerung_tage` (int 1–365, `31`), `inbetriebnahme_at` (Datum/Zeit, **nicht** vom Admin änderbar). `validiere(string $schluessel, string $wert): ?string` (Fehlertext oder `null`), `aenderbar(): array` (Schlüssel ohne `inbetriebnahme_at`).

- [ ] **Step 1: Failing tests schreiben.**
  - `BerechtigungTest::test_rollenmatrix()` mit Datenanbieter über **jede** Kombination Rolle × Aktion × Bereich (`getraenke`, `kiosk`, `null`). Erwartung laut Spec Abschnitt 4: `mitglied` darf nur `buchen`, `eigene_stornieren`; `getraenkewart` die Wart-Aktionen nur mit `getraenke`, dazu `spenden_ansehen`/`spenden_pflegen`; `kioskwart` die Wart-Aktionen nur mit `kiosk`, keine Spenden; `kassenwart` `auszaehlung_ansehen` für beide Bereiche und die Spenden-Aktionen, keine Bestandsaktionen; `admin` alles. Dazu: kombinierte Rollen `['mitglied','kioskwart']` vereinigen die Rechte; leere Rollen dürfen nichts; unbekannte Aktion wirft.
  - `PinSperreTest`: 4. Fehlversuch → `fehlversuche=4`, `gesperrt_bis=null`; 5. → `fehlversuche=0`, `gesperrt_bis=jetzt+5min`; `istGesperrt` am Ende genau `false`, eine Sekunde davor `true`, `null` → `false`.
  - `AnmelderegelnTest`: `'1234'` ok, `'123'`, `'1234567'`, `'12a4'` Fehler; Passwort `'1234567'` Fehler, `'12345678'` ok; Benutzername `' Max.Muster '→'max.muster'`, `'ä'`/`'ab'` → `null`.
  - `EinmalPasswortTest`: Länge 10, nur erlaubte Zeichen, zwei Aufrufe verschieden.
  - `StornoFristTest`: 10 min Frist: +9:59 offen, +10:00 offen, +10:01 zu; Frist 0 → nur exakt gleiche Sekunde offen.
  - `ZeitraumErmittlerTest`: Fälle ohne/mit Stichtag inkl. Grenzsekunde.
  - `EinstellungDefinitionTest`: `storno_frist_min` `'abc'`/`'121'` Fehler, `'0'` ok; unbekannter Schlüssel Fehler; `aenderbar()` enthält `inbetriebnahme_at` nicht.
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/unit` → neue Tests FAIL.
- [ ] **Step 3: Klassen implementieren** gemäß Interfaces.
- [ ] **Step 4:** Test erneut → PASS.
- [ ] **Step 5: Commit** – `"Reine Fachklassen: Berechtigung, PinSperre, Fristen, Zeitraum, Einstellungen"`

---

### Task 4: Migrationen, Models, Seed-Daten

**Files:**
- Create: `app/Database/Migrations/2026-10-05-000001_Grundtabellen.php` (`bereiche`, `personen`, `person_rollen`, `anmelde_tokens`, `einstellungen`, `protokoll`), `…000002_Stammdaten.php` (`kategorien`, `artikel`), `…000003_Buchungen.php` (`buchungen`), `…000004_Geraete.php` (`geraete`, `freischaltcodes`), `…000005_Startdaten.php`; alle Models aus der Dateistruktur
- Modify: `tests/_support/DbTestCase.php`
- Test: `tests/database/MigrationTest.php`, `tests/database/PersonModelTest.php`

**Interfaces:**
- Spalten exakt wie Spec Abschnitt 6, InnoDB, `utf8mb4_unicode_ci`, FKs `ON DELETE RESTRICT`. `buchungen.vorgang_id CHAR(36)`; Indizes: unique (`vorgang_id`,`artikel_id`), (`konto_id`,`gebucht_at`), (`artikel_id`,`gebucht_at`). `personen.benutzername` unique nullable. `anmelde_tokens.selector` unique. `einstellungen.schluessel` Primärschlüssel. `geraete.token_hash` und `freischaltcodes.code_hash` je `CHAR(64)` mit Index.
- Startdaten (Werte in der Migration wiederholt): `bereiche` `getraenke`/„Getränke“/`getraenkewart`/aktiv=1 und `kiosk`/„Fuxenkiosk“/`kioskwart`/aktiv=0; `personen` „Couleur“ und „Bund“ (`typ=sammelkonto`, `gruppe=sonstige`, ohne Benutzername/Passwort/PIN); `einstellungen` mit allen Defaults aus `EinstellungDefinition`, `inbetriebnahme_at` = Zeitpunkt der Migration.
- Produces (Models, CI4 `Model` mit `$useTimestamps = true`, Rückgabe `array`):
  - `PersonModel::rollen(int $personId): array` (Rollen aus `person_rollen`, plus `'mitglied'` bei `typ=mitglied`), `findeAktivNachBenutzername(string $benutzername): ?array`, `sammelkontoId(string $name): int` (`'Couleur'|'Bund'`), `istAktiv(array $person): bool` (`archiviert_at === null`).
  - `BereichModel::aktive(): array` (nach `id`).
  - `AnmeldeTokenModel::loescheFuerPerson(int $personId): void` (löscht alle Remember-Tokens der Person; Task 7, 8 und 12 nutzen sie).
  - `ArtikelModel::buchbar(): array` – nur aktive Bereiche, nicht archivierte Kategorien/Artikel, sortiert nach `sortierung`, `id`; Struktur `[['schluessel','name','kategorien'=>[['id','name','artikel'=>[['id','name','einheit','preis_cent']]]]]]`; `findeBuchbar(int $id): ?array` (inkl. `bereich_schluessel`).
- Produces (`DbTestCase`-Helfer): `personAnlegen(array $werte = []): int` (Standard: Mitglied, Benutzername eindeutig, Passwort `geheim123`, PIN `1234`, kein Pflichtwechsel), `rolleGeben(int $personId, string $rolle): void`, `artikelAnlegen(array $werte = []): int` (legt bei Bedarf Kategorie „Bier“ im Bereich `getraenke` an; Standard „Helles“, `0,5 l`, 150 Cent), `alsAngemeldet(int $personId): static` (`withSession(['person_id' => …, 'csrf_test_name' => 'test-token'])`), `csrf(): array` (`['csrf_test_name' => 'test-token']`).

- [ ] **Step 1: Failing tests.** `MigrationTest`: `test_bereiche_angelegt()` (genau zwei, `kiosk` inaktiv, `verwalter_rolle` je Bereich passt zu `Berechtigung::WART_BEREICH`), `test_sammelkonten_angelegt()`, `test_einstellungen_haben_alle_defaults()` (Schlüssel = `array_keys(EinstellungDefinition::DEFINITIONEN)`), `test_vorgang_artikel_eindeutig()` (zweiter Insert gleicher `vorgang_id`+`artikel_id` wirft `DatabaseException`). `PersonModelTest`: `rollen()` liefert `['mitglied','admin']` für Mitglied mit Admin-Rolle und `[]` für ein Sammelkonto; `ArtikelModel::buchbar()` blendet `kiosk`, archivierte Kategorien und archivierte Artikel aus.
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/database` → FAIL.
- [ ] **Step 3: Migrationen, Models und Helfer implementieren.**
- [ ] **Step 4:** Tests → PASS. Zusätzlich `docker exec getraenkeliste-web php spark migrate` gegen die Entwicklungs-DB → ohne Fehler.
- [ ] **Step 5: Commit** – `"Migrationen Stufe 1, Models und Startdaten (Bereiche, Couleur/Bund, Einstellungen)"`

---

### Task 5: Einstellungen und Protokoll

**Files:**
- Create: `app/Libraries/Einstellungen.php`, `app/Libraries/Protokollierer.php`; Registrierung in `app/Config/Services.php` (`einstellungen()`, `protokollierer()`, `uhr()`)
- Test: `tests/database/EinstellungenTest.php`, `tests/database/ProtokolliererTest.php`

**Interfaces:**
- Produces: `service('uhr')` liefert `DateTimeImmutable` „jetzt“ (Closure, im Test per `Services::injectMock('uhr', …)` fixierbar – **alle** zeitabhängigen Stellen nutzen diesen Service).
- `Einstellungen::int(string $schluessel): int`, `text(string $schluessel): string`, `inbetriebnahme(): DateTimeImmutable`, `setze(string $schluessel, string $wert, int $adminId): ?string` (validiert über `EinstellungDefinition`, lehnt nicht änderbare Schlüssel ab, protokolliert alt/neu, gibt Fehlertext oder `null`). Werte je Request einmal geladen.
- `Protokollierer::schreibe(int $personId, string $aktion, string $tabelle, ?int $datensatzId, ?array $alt = null, ?array $neu = null): void` – entfernt aus `alt`/`neu` alle Schlüssel, die auf `_hash` enden, speichert JSON (`JSON_UNESCAPED_UNICODE`), `erfolgt_at` aus `service('uhr')`.

- [ ] **Step 1: Failing tests.** `EinstellungenTest`: Default lesbar; `setze('storno_frist_min','15',…)` speichert und schreibt Protokoll mit `alt={"wert":"10"}`; `setze('inbetriebnahme_at',…)` liefert Fehler und ändert nichts; ungültiger Wert ändert nichts. `ProtokolliererTest`: `passwort_hash`/`pin_hash` erscheinen nicht im gespeicherten JSON, Umlaute bleiben lesbar.
- [ ] **Step 2:** Tests → FAIL. **Step 3:** implementieren. **Step 4:** Tests → PASS.
- [ ] **Step 5: Commit** – `"Einstellungen-Service, Protokollierer, Uhr-Service"`

---

### Task 6: Login am eigenen Gerät, Layout, Admin-Anlage

**Files:**
- Create: `app/Libraries/Anmeldung.php`, `app/Filters/AnmeldungFilter.php`, `app/Filters/RechtFilter.php`, `app/Controllers/AuthController.php`, `app/Commands/AdminAnlegen.php`, `app/Views/layouts/main.php`, `app/Views/layouts/einfach.php`, `app/Views/auth/login.php`, `app/Views/errors/keine_berechtigung.php`, `public/css/app.css`, `public/img/vdst-logo.svg`, `public/js/app.js`
- Modify: `app/Config/Filters.php`, `app/Config/Routes.php`, `app/Config/Services.php` (`anmeldung()`), `app/Models/PersonModel.php`
- Test: `tests/feature/LoginTest.php`, `tests/database/AdminAnlegenTest.php`

**Interfaces:**
- Consumes: `PinSperre`, `PersonModel`, `Berechtigung`, `service('uhr')`.
- Produces:
  - `Anmeldung::pruefePasswort(string $benutzername, string $passwort): array{ok:bool, person:?array, meldung:?string}` – Meldungen: `Benutzername oder Passwort falsch.` (auch bei unbekanntem Namen und archivierter Person) bzw. `Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen.`; Fehlversuche/Sperre über `PinSperre` in `login_fehlversuche`/`login_gesperrt_bis`, Erfolg setzt Zähler zurück.
  - `Anmeldung::anmelden(int $personId): void` (`session()->regenerate(true)`, `person_id` setzen), `abmelden(): void`, `person(): ?array` (aktuelle, **nicht archivierte** Person, sonst Abmeldung), `rollen(): array`.
  - `PersonModel::legeAdminAn(string $vorname, string $nachname, string $benutzername, string $passwort): int` – Mitglied, Gruppe `aktiv`, Rolle `admin`, `passwort_wechsel_erzwingen=0`, PIN leer (Pflichtseite aus Task 7 fordert sie an).
  - Filter `angemeldet` (alle Routen außer `login`, `tablet/*`): ohne Person → Redirect `login`; Remember-Cookie-Prüfung wird in Task 8 eingehängt. Filter `recht:<aktion>` → 403 mit View `errors/keine_berechtigung` (Text „Dafür fehlt dir die Berechtigung.“).
  - Routen: `GET login`, `POST login`, `POST logout`, `GET /` → Redirect `buchen` (Platzhalterseite bis Task 10).
  - `layouts/main.php` nach Vorbild Kassensystem (Darkmode-Inline-Skript, Sidebar/Topbar, Flash-Banner mit `js-auto-dismiss`), Marke „VDSt Getränkeliste“; Navigation: Buchen, Meine Buchungen, Konto; Gruppe „Verwaltung“ nur bei `Berechtigung::darf($rollen,'admin')`: Personen, Getränke & Preise, Tablets, Einstellungen, Protokoll. `layouts/einfach.php` = Standalone ohne Sidebar (Login, Pflichtseite, Tablet). `public/js/app.js` enthält nur Theme-Toggle und `js-auto-dismiss` aus dem Kassensystem.
  - `php spark admin:anlegen` fragt Vorname, Nachname, Benutzername, Passwort (zweimal) per `CLI::prompt` ab, prüft mit `Anmelderegeln`, ruft `legeAdminAn`.

- [ ] **Step 1: Failing tests.** `LoginTest`:
  - `test_login_erfolgreich_leitet_weiter_und_setzt_session()`
  - `test_falsches_passwort_zeigt_meldung()`
  - `test_fuenf_fehlversuche_sperren_auch_richtiges_passwort()` (danach mit `uhr` +5 min +1 s wieder erfolgreich)
  - `test_archivierte_person_kann_sich_nicht_anmelden()`
  - `test_archivierte_person_mit_offener_session_wird_abgemeldet()` (Review Focus 4: Person in Session, dann `archiviert_at` setzen, `GET buchen` → Redirect `login`)
  - `test_session_id_wechselt_beim_login()`
  - `test_logout_nur_per_post()` (`GET logout` → 404)
  - `test_post_ohne_csrf_token_wird_abgewiesen()`
  - `test_geschuetzte_route_ohne_login_leitet_zu_login()`

  `AdminAnlegenTest::test_legt_admin_ohne_pin_an()`.
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/feature/LoginTest.php` → FAIL.
- [ ] **Step 3: Implementieren**, `app.css` und Logo kopieren (`cp ../kassensystem-vdst/public/css/app.css public/css/`, ebenso `public/img/vdst-logo.svg`).
- [ ] **Step 4:** Tests → PASS. Sichtprüfung: `http://localhost:8090/login` hell und dunkel, Handybreite.
- [ ] **Step 5: Commit** – `"Login eigenes Gerät, Login-Sperre, Layout aus dem Design-System, admin:anlegen"`

---

### Task 7: Pflichtseite (neues Passwort und PIN) und Konto-Seite

**Files:**
- Create: `app/Controllers/KontoController.php`, `app/Views/konto/einrichten.php`, `app/Views/konto/index.php`
- Modify: `app/Filters/AnmeldungFilter.php`, `app/Config/Routes.php`, `app/Models/PersonModel.php`
- Test: `tests/feature/KontoTest.php`

**Interfaces:**
- Consumes: `Anmeldung`, `Anmelderegeln`, `AnmeldeTokenModel::loescheFuerPerson()` (Task 4).
- Produces: `PersonModel::brauchtEinrichtung(array $person): array{passwort:bool, pin:bool}` (`passwort = passwort_wechsel_erzwingen == 1`, `pin = pin_hash === null`).
  - Filter `angemeldet`: Solange eins davon `true` ist, führt jede Route außer `konto/einrichten` (GET/POST) und `logout` zu Redirect `konto/einrichten`.
  - `GET/POST konto/einrichten`: zeigt nur die nötigen Feldpaare (`passwort_neu`, `passwort_wiederholen`; `pin`, `pin_wiederholen`) und speichert sie. Ein Passwortwechsel löscht alle Remember-Tokens der Person.
  - `GET konto`, `POST konto/passwort` (aktuelles Passwort + neues zweimal), `POST konto/pin` (aktuelles Passwort + PIN zweimal).

- [ ] **Step 1: Failing tests.**
  - `test_einmalpasswort_erzwingt_passwort_und_pin_vor_allem_anderen()` (`GET buchen` → Redirect `konto/einrichten`)
  - `test_nach_pin_reset_wird_nur_pin_verlangt()`
  - `test_einrichten_mit_ungleichen_pins_scheitert()`
  - `test_nach_einrichtung_ist_buchen_erreichbar()`
  - `test_passwort_aendern_braucht_aktuelles_passwort()`
  - `test_pin_aendern_speichert_nur_hash()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Pflichtseite für neues Passwort/PIN, Konto-Seite"`

---

### Task 8: „Angemeldet bleiben“ (Remember-Token)

**Files:**
- Modify: `app/Models/AnmeldeTokenModel.php`, `app/Libraries/Anmeldung.php`, `app/Filters/AnmeldungFilter.php`, `app/Controllers/AuthController.php`, `app/Views/auth/login.php`
- Test: `tests/feature/MerkTokenTest.php`

**Interfaces:**
- Produces: Cookie `gl_merken` = `<selector>:<validator>` (je 16 bzw. 32 Byte, hex), 90 Tage gültig, `httponly`; DB speichert `selector`, `sha256(validator)`, `gueltig_bis`.
  - `AnmeldeTokenModel::erzeuge(int $personId, DateTimeImmutable $jetzt): string` (Cookie-Wert), `rotiere(string $cookie, DateTimeImmutable $jetzt): ?array{person_id:int, cookie:string}` (prüft mit `hash_equals`, löscht den alten Datensatz, legt einen neuen an; bei passendem Selector mit falschem Validator **alle** Tokens der Person löschen), `loescheCookie(string $cookie): void`.
  - Der Filter `angemeldet` stellt die Anmeldung ohne Session aus dem Cookie wieder her (nur wenn die Person aktiv ist), dann `anmelden()` + neues Cookie. Gelöscht wird bei Logout (nur dieses Token), Passwortwechsel, Passwort-Reset, Archivierung (alle).

- [ ] **Step 1: Failing tests** (Cookie über `$_COOKIE['gl_merken']` setzen, im `tearDown` entfernen):
  - `test_login_mit_merken_setzt_cookie()`
  - `test_cookie_meldet_ohne_session_an_und_rotiert()` (alter Cookie danach ungültig)
  - `test_gestohlener_validator_loescht_alle_tokens()`
  - `test_archivierte_person_wird_trotz_cookie_nicht_angemeldet()` (Review Focus 4)
  - `test_passwortwechsel_macht_cookie_ungueltig()`
  - `test_abgelaufenes_token_wird_ignoriert()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Angemeldet bleiben: rotierendes Remember-Token"`

---

### Task 9: BuchungService (Buchen, Idempotenz, Storno)

**Files:**
- Create: `app/Libraries/BuchungService.php`, `app/Libraries/BuchungAbgelehnt.php`
- Modify: `app/Models/BuchungModel.php`, `app/Config/Services.php` (`buchungen()`)
- Test: `tests/unit/VorgangZusammenfassungTest.php`, `tests/database/BuchungServiceTest.php`

**Interfaces:**
- Consumes: `ArtikelModel::findeBuchbar()`, `StornoFrist`, `ZeitraumErmittler`, `Einstellungen`, `service('uhr')`.
- Produces:
  - `BuchungAbgelehnt extends RuntimeException` (Nachricht ist der deutsche Text für die Oberfläche).
  - `BuchungService::bucheVorgang(string $vorgangId, int $kontoId, ?int $gebuchtVonId, ?int $geraetId, string $quelle, array $positionen): array` mit `$positionen = [['artikel_id'=>int,'menge'=>int], …]`. Rückgabe `['vorgang_id','konto_id','positionen'=>[['artikel_id','name','menge','einzelpreis_cent']],'summe_cent'=>int,'zusammenfassung'=>string,'gebucht_at'=>string,'wiederholt'=>bool]`.
    - Regeln: `vorgang_id` muss UUID v4 sein; 1–30 Positionen, je Menge 1–99; doppelte `artikel_id` werden addiert. Jeder Artikel muss buchbar sein, sonst `BuchungAbgelehnt("„<Name>“ ist nicht mehr buchbar. Nicht gebucht.")`, bei unbekannter ID `"Ein Artikel ist nicht mehr buchbar. Nicht gebucht."`. Das Konto muss aktiv sein. `einzelpreis_cent` = aktueller Preis. Alles in **einer Transaktion**.
    - Idempotenz: Gibt es die `vorgang_id` schon, wird das gespeicherte Ergebnis mit `wiederholt=true` zurückgegeben, sofern `konto_id` und `gebucht_von_id` übereinstimmen; sonst `BuchungAbgelehnt("Ungültiger Vorgang.")`. Eine Unique-Verletzung im Wettlauf zweier Requests wird abgefangen und ebenso beantwortet.
  - `BuchungService::zusammenfassung(array $positionen): string` (statisch, rein) → `"3× Helles, 1× Spezi – 6,50 €"` (Reihenfolge wie übergeben, `formatiere_cent`).
  - `BuchungService::vorgang(string $vorgangId): ?array` (gleiche Struktur, nur nicht stornierte Positionen).
  - `BuchungService::storniereVorgang(string $vorgangId, ?int $stornoVonId): void` und `storniereBuchung(int $buchungId, ?int $stornoVonId): void` – lehnen mit `BuchungAbgelehnt` ab, wenn schon storniert (`"Bereits storniert."`), die Frist (`einstellungen.storno_frist_min`, ab `gebucht_at`) abgelaufen ist (`"Die Storno-Frist ist abgelaufen."`) oder `ZeitraumErmittler::istEingefroren` greift. Die Prüfung, **wer** stornieren darf, liegt im Controller.

- [ ] **Step 1: Failing tests.**
  - `VorgangZusammenfassungTest`: Beispiel aus Spec 7.1 ergibt genau `"3× Helles, 1× Spezi – 6,50 €"` (Helles 150, Spezi 200).
  - `BuchungServiceTest`:
    - `test_bucht_vorgang_mit_aktuellem_preis()`
    - `test_gleiche_vorgang_id_bucht_nicht_doppelt()` (Review Focus 1: zweiter Aufruf → `wiederholt=true`, weiterhin genau eine Zeile je Artikel)
    - `test_gleiche_vorgang_id_fremdes_konto_abgelehnt()`
    - `test_preisaenderung_vor_dem_buchen_gilt_sofort()` (Preis nach dem Laden ändern → Buchung und Zusammenfassung mit neuem Preis)
    - `test_archivierter_artikel_lehnt_ganzen_vorgang_ab()` (keine Zeile gespeichert, Meldung nennt den Artikel)
    - `test_kiosk_artikel_nicht_buchbar_solange_bereich_inaktiv()`
    - `test_menge_null_oder_100_abgelehnt()`
    - `test_doppelte_artikel_werden_addiert()`
    - `test_storno_innerhalb_frist()`
    - `test_storno_nach_frist_abgelehnt()` (`uhr` +11 min)
    - `test_doppeltes_storno_abgelehnt()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"BuchungService: idempotentes Buchen und Storno mit Frist"`

---

### Task 10: Buchungsseite am eigenen Gerät

**Files:**
- Create: `app/Controllers/BuchenController.php`, `app/Views/buchen/index.php`, `app/Views/buchen/_artikel.php`, `public/js/buchen.js`
- Modify: `app/Config/Routes.php`, `public/css/app.css` (Block `/* Getränkeliste */`: Artikelkacheln, Warenkorb-Leiste, Bestätigung)
- Test: `tests/feature/BuchenWebTest.php`

**Interfaces:**
- Consumes: `ArtikelModel::buchbar()`, `BuchungService`, `PersonModel::sammelkontoId()`.
- Produces:
  - `GET buchen`: rendert Bereichs-Reiter (nur aktive), Kategorie-Reiter, Artikelkacheln (Name, Einheit, Preis, `+`/`−`), Auswahl `konto` = `ich|couleur|bund` (Standard `ich`), Warenkorb mit Summe und **Buchen** (`.btn-vdst`). Neue `vorgang_id` (UUID v4, serverseitig `random_bytes`) als `data-vorgang-id`.
  - `POST buchen` (JSON, CSRF über Header `X-CSRF-TOKEN`): Body `{vorgang_id, konto, positionen:[{artikel_id,menge}]}`; `konto=ich` → eigene Person, sonst Sammelkonto. `gebucht_von_id` ist immer die angemeldete Person, `quelle='web'`. Antwort 200 `{ok:true, zusammenfassung, summe_cent, vorgang_id, naechste_vorgang_id}`; bei `BuchungAbgelehnt` 422 `{ok:false, meldung}`. Recht: `buchen`.
  - `POST buchen/rueckgaengig` (JSON `{vorgang_id}`): nur, wenn die Person `konto_id` oder `gebucht_von_id` des Vorgangs ist, sonst 403 `{ok:false, meldung:"Dieser Vorgang gehört nicht zu dir."}`; ansonsten `storniereVorgang(…, personId)`.
  - `buchen.js` (für Web und Tablet; Endpunkte aus `data-buchen-url`, `data-rueckgaengig-url`): Warenkorb im Speicher, Bestätigung mit **Rückgängig**. Fehler → Meldung `"Nicht gebucht – bitte erneut versuchen"` (bzw. `meldung` vom Server), Warenkorb und **dieselbe** `vorgang_id` bleiben erhalten; nach Erfolg `naechste_vorgang_id` übernehmen. Bei 403 wegen CSRF: Seite neu laden. Button während des Requests deaktiviert (Spinner).

- [ ] **Step 1: Failing tests.**
  - `test_buchungsseite_zeigt_nur_buchbare_artikel()`
  - `test_buchen_fuer_mich()`
  - `test_buchen_auf_couleur_speichert_gebucht_von()`
  - `test_doppelter_post_bucht_einmal()` (Review Focus 1: identischer Request zweimal mit demselben CSRF-Token → beide 200, eine Buchungszeile)
  - `test_abgelehnte_buchung_liefert_422_mit_meldung()`
  - `test_rueckgaengig_eigener_vorgang()`
  - `test_rueckgaengig_fremder_vorgang_403()`
  - `test_konto_wert_ungueltig_422()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS. Sichtprüfung: Buchen am Handy-Viewport, Bestätigung, Rückgängig.
- [ ] **Step 5: Commit** – `"Buchungsseite am eigenen Gerät mit Couleur/Bund-Auswahl"`

---

### Task 11: Meine Buchungen und Storno

**Files:**
- Create: `app/Controllers/MeineBuchungenController.php`, `app/Views/meine_buchungen/index.php`
- Modify: `app/Models/BuchungModel.php`, `app/Config/Routes.php`
- Test: `tests/feature/MeineBuchungenTest.php`

**Interfaces:**
- Produces:
  - `BuchungModel::fuerKonto(int $kontoId, DateTimeImmutable $ab): array` und `vonPersonAufSammelkonten(int $personId, DateTimeImmutable $ab): array` – je Zeile inkl. Artikelname, Bereich, `storniert_at`.
  - `BuchungModel::offenerBetrag(int $kontoId, string $bereichSchluessel, DateTimeImmutable $ab): int` = Summe `menge × einzelpreis_cent` der nicht stornierten Buchungen.
  - `GET meine-buchungen`: je aktivem Bereich eine Tabelle (`.table-stack`) für den laufenden Zeitraum (`$ab` = `Einstellungen::inbetriebnahme()`) mit offenem Betrag; darunter gesondert „Von dir auf Couleur/Bund gebucht“ (zählt nicht zum offenen Betrag). Storno-Button nur, solange `StornoFrist::istOffen`. „Frühere Zeiträume“ (Spec 7.2) entstehen erst mit Auszählungen in Stufe 2 und werden hier nicht gebaut.
  - `POST meine-buchungen/storno/(:num)`: erlaubt, wenn `konto_id == person` **oder** `gebucht_von_id == person`; sonst 403. Sonst `storniereBuchung`. Wird **nicht** protokolliert.

- [ ] **Step 1: Failing tests.**
  - `test_zeigt_eigene_buchungen_und_offenen_betrag()` (stornierte zählen nicht)
  - `test_sammelkonto_buchungen_getrennt_und_nicht_im_betrag()`
  - `test_storno_eigene_buchung()`
  - `test_storno_fremde_buchung_403()`
  - `test_storno_nach_frist_zeigt_fehler()`
  - `test_storno_schreibt_kein_protokoll()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Meine Buchungen mit offenem Betrag und Storno"`

---

### Task 12: Admin – Personen, Rollen, Resets, CSV-Import

**Files:**
- Create: `app/Controllers/Admin/PersonenController.php`, `app/Controllers/Admin/PersonenImportController.php`, `app/Libraries/CsvPersonenParser.php`, `app/Views/admin/personen/{index,formular,import,import_vorschau,einmalpasswoerter}.php`
- Modify: `app/Models/PersonModel.php`, `app/Config/Routes.php` (Gruppe `admin`, Filter `angemeldet` + `recht:admin`)
- Test: `tests/unit/CsvPersonenParserTest.php`, `tests/feature/AdminPersonenTest.php`

**Interfaces:**
- Consumes: `Anmelderegeln`, `EinmalPasswort`, `Protokollierer`, `AnmeldeTokenModel::loescheFuerPerson`.
- Produces:
  - Routen: `GET admin/personen` (Liste der Mitglieder, Filter Gruppe/archiviert; Sammelkonten erscheinen nicht), `GET admin/personen/neu`, `POST admin/personen`, `GET admin/personen/(:num)`, `POST admin/personen/(:num)` (Stammdaten + Rollen-Checkboxen), `POST admin/personen/(:num)/passwort-reset`, `POST …/pin-reset`, `POST …/archivieren`.
  - Anlegen und Passwort-Reset erzeugen ein `EinmalPasswort`, setzen `passwort_wechsel_erzwingen=1`, löschen Remember-Tokens und zeigen das Passwort **einmal** (Flash, View `einmalpasswoerter`). PIN-Reset setzt `pin_hash=NULL`, `pin_fehlversuche=0`. Archivieren setzt `archiviert_at` und löscht Tokens.
  - Ein Admin kann sich selbst weder archivieren noch die eigene Admin-Rolle entziehen (`"Du kannst dir die Admin-Rolle nicht selbst entziehen."`).
  - Alle Änderungen über `Protokollierer` (Aktionen `angelegt`, `geaendert`, `rollen_geaendert`, `passwort_reset`, `pin_reset`, `archiviert`, `importiert`).
  - `CsvPersonenParser::parse(string $inhalt, array $vorhandeneBenutzernamen, array $vorhandeneNamen): array{zeilen: list<array{zeile:int, vorname:string, nachname:string, gruppe:string, benutzername:string, fehler:?string}>, fehler:?string}`.
    - `$vorhandeneNamen` ist eine Liste von `mb_strtolower("vorname nachname")`.
    - Vorbereitung: BOM entfernen; kein gültiges UTF-8 → aus Windows-1252 umwandeln; `\r\n`/`\r` normalisieren; Leerzeilen überspringen. Kopfzeile genau `vorname;nachname;gruppe;benutzername` (Groß-/Kleinschreibung egal), sonst Gesamtfehler `"Kopfzeile muss lauten: vorname;nachname;gruppe;benutzername"`.
    - `gruppe` case-insensitiv auf `aktiv|ah|sonstige`. Benutzername über `Anmelderegeln::benutzernameNormalisieren`.
    - Zeilenfehler: `"Gruppe unbekannt"`, `"Benutzername ungültig"`, `"Benutzername existiert bereits"`, `"Benutzername doppelt in der Datei"`, `"Person existiert bereits"`, `"Vor- und Nachname erforderlich"`.
  - Import: `GET admin/personen/import` → `POST admin/personen/import/vorschau` (Datei hochladen, Ergebnis in der Session, Vorschau mit Fehlern je Zeile) → `POST admin/personen/import/ausfuehren`: legt nur fehlerfreie Zeilen in **einer Transaktion** an, je mit Einmal-Passwort, und zeigt die Liste `Name – Benutzername – Einmal-Passwort` einmalig, druckbar.

- [ ] **Step 1: Failing tests.**
  - `CsvPersonenParserTest`:
    - `test_gueltige_datei()`
    - `test_windows_1252_umlaute()` (Review Focus 3: `"J\xFCrgen;M\xFCller;AH;jmueller"` → `Jürgen`, `Müller`, `ah`)
    - `test_bom_und_crlf_und_leerzeilen()`
    - `test_falsche_kopfzeile()`
    - `test_dubletten_in_db_und_datei()`
    - `test_unbekannte_gruppe()`
  - `AdminPersonenTest`:
    - `test_mitglied_ohne_adminrolle_403_auf_allen_adminrouten()`
    - `test_person_anlegen_zeigt_einmalpasswort_und_erzwingt_wechsel()`
    - `test_rollen_vergeben_wird_protokolliert()`
    - `test_pin_reset_loescht_pin()`
    - `test_passwort_reset_loescht_tokens()`
    - `test_archivieren()`
    - `test_admin_kann_sich_nicht_selbst_entmachten()`
    - `test_import_legt_nur_fehlerfreie_zeilen_an()`
    - `test_protokoll_enthaelt_keine_hashes()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Admin: Personen, Rollen, Passwort-/PIN-Reset, CSV-Import"`

---

### Task 13: Admin – Kategorien und Artikel

**Files:**
- Create: `app/Controllers/Admin/StammdatenController.php`, `app/Views/admin/stammdaten/{index,artikel_formular}.php`
- Modify: `app/Models/KategorieModel.php`, `app/Models/ArtikelModel.php`, `app/Config/Routes.php`
- Test: `tests/feature/AdminStammdatenTest.php`

**Interfaces:**
- Produces:
  - `GET admin/stammdaten` – nur **aktive** Bereiche (Kiosk bis Stufe 3 ausgeblendet). Je Kategorie eine Artikeltabelle; archivierte Einträge nur mit Schalter „Archivierte zeigen“.
  - `POST admin/kategorien` (anlegen: `bereich_id`, `name`), `POST admin/kategorien/(:num)` (umbenennen), `POST admin/kategorien/(:num)/verschieben/(hoch|runter)`, `POST admin/kategorien/(:num)/archivieren`.
  - `GET admin/artikel/neu?kategorie=`, `GET admin/artikel/(:num)`, `POST admin/artikel`, `POST admin/artikel/(:num)`, `POST admin/artikel/(:num)/verschieben/(hoch|runter)`, `POST admin/artikel/(:num)/archivieren`.
  - Artikelfelder: `name`, `kategorie_id`, `preis` (Eingabe mit Komma → `betrag_in_cent`), `einheit`, `gebinde_groesse` (leer oder 1–100), `mindestbestand` (≥ 0), `bestand_fuehren`.
  - Sortieren tauscht `sortierung` mit dem Nachbarn (Transaktion). Neue Einträge kommen ans Ende.
  - Eine Preisänderung wirkt nur auf künftige Buchungen (bestehende `einzelpreis_cent` bleiben). Protokoll-Aktion `preis_geaendert` mit alt/neu.
  - Anlegen in einer Kategorie eines inaktiven Bereichs wird serverseitig abgelehnt.
  - `KategorieModel::verschiebe(int $id, string $richtung): void`, `ArtikelModel::verschiebe(int $id, string $richtung): void`.

- [ ] **Step 1: Failing tests.**
  - `test_artikel_anlegen_mit_kommapreis()` (`'1,80'` → 180)
  - `test_ungueltiger_preis_zeigt_fehler()`
  - `test_preisaenderung_aendert_alte_buchungen_nicht()`
  - `test_preisaenderung_wird_protokolliert()`
  - `test_verschieben_hoch_runter()`
  - `test_archivierter_artikel_verschwindet_von_buchungsseite()`
  - `test_kiosk_nicht_sichtbar_und_nicht_anlegbar()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Admin: Kategorien und Artikel mit Preisen und Sortierung"`

---

### Task 14: Tablets freischalten und verwalten

**Files:**
- Create: `app/Libraries/Geraete.php`, `app/Filters/GeraetFilter.php`, `app/Controllers/Admin/TabletsController.php`, `app/Views/admin/tablets/index.php`, `app/Views/tablet/freischalten.php`
- Modify: `app/Models/GeraetModel.php`, `app/Models/FreischaltcodeModel.php`, `app/Filters/AnmeldungFilter.php`, `app/Config/{Filters,Routes,Services}.php`
- Test: `tests/feature/TabletFreischaltungTest.php`

**Interfaces:**
- Produces:
  - `FreischaltcodeModel::erzeuge(int $adminId, DateTimeImmutable $jetzt): string` – 8 Ziffern, `gueltig_bis = jetzt + 15 min`, speichert `sha256`.
  - `Geraete::freischalten(string $code, string $name): ?string` – Code gültig und nicht eingelöst → `eingeloest_at` setzen, Gerät anlegen, 32-Byte-Token (hex) zurückgeben; sonst `null`.
  - `Geraete::ausCookie(?string $token): ?array` (nur nicht gesperrte Geräte, aktualisiert `zuletzt_gesehen_at`), `Geraete::istGesperrtesToken(?string $token): bool`.
  - Cookie `gl_geraet`: `httponly`, 400 Tage, **bei jedem Tablet-Request neu gesetzt** (Review Focus 5).
  - `GET/POST tablet/freischalten` (Felder `code`, `name`, ohne Login). Fehler: `"Code ungültig oder abgelaufen."`.
  - Filter `tablet` (alle `tablet/*` außer `freischalten`): ohne gültiges Gerät → Redirect `tablet/freischalten`; gesperrtes Gerät → Seite `"Dieses Tablet wurde gesperrt."`.
  - Filter `angemeldet` (Ergänzung): Ein gültiges Geräte-Cookie leitet **jede** Nicht-Tablet-Route nach `tablet` um, auch `login` – am Tablet gibt es nur Buchen.
  - Admin: `GET admin/tablets` (Geräteliste mit zuletzt gesehen und Status, Button „Freischaltcode erzeugen“, Code einmalig groß angezeigt mit Ablaufzeit), `POST admin/tablets/code`, `POST admin/tablets/(:num)/umbenennen`, `POST admin/tablets/(:num)/sperren` – jeweils protokolliert.

- [ ] **Step 1: Failing tests.**
  - `test_code_einmalig()`
  - `test_code_nach_15_minuten_ungueltig()`
  - `test_freischaltung_setzt_geraete_cookie()`
  - `test_geraete_cookie_wird_bei_jedem_request_erneuert()` (Review Focus 5)
  - `test_gesperrtes_tablet_abgewiesen()`
  - `test_tablet_erreicht_keine_anderen_routen()` (mit Geräte-Cookie: `GET login`, `GET meine-buchungen`, `GET admin/personen` → Redirect `tablet`)
  - `test_nur_admin_erzeugt_codes()`
  - `test_code_wird_nur_als_hash_gespeichert()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Tablets: Freischaltcode, Geräte-Cookie, Sperren"`

---

### Task 15: Tablet – Namensauswahl, PIN, Buchen, Rückgängig

**Files:**
- Create: `app/Controllers/TabletController.php`, `app/Views/tablet/{namen,pin,buchen}.php`, `public/js/tablet.js`
- Modify: `app/Models/PersonModel.php`, `app/Views/buchen/index.php` (Modus `web|tablet`), `public/js/buchen.js`, `public/css/app.css` (Namenskacheln, PIN-Tastatur)
- Test: `tests/feature/TabletBuchenTest.php`

**Interfaces:**
- Consumes: `Geraete`, `PinSperre`, `BuchungService`, `Einstellungen::int('tablet_timeout_s')`.
- Produces:
  - `PersonModel::tabletKacheln(): array{fest: list<array>, zuletzt: list<array>, alle: list<array>}` – `fest` = Couleur, Bund; `zuletzt` = die **12** aktiven Mitglieder mit den jüngsten Buchungen (`MAX(gebucht_at)`); `alle` = alle aktiven Mitglieder, sortiert nach Anzeigename. Jede Person enthält `id`, `anzeigename`, `gruppe`, `hat_pin`.
  - `GET tablet`: Kacheln. Personen ohne PIN ausgegraut mit `"Bitte zuerst am eigenen Gerät eine PIN setzen"`. Suchfeld und Filter Aktive/AHs/Alle clientseitig in `tablet.js` (`sonstige` nur unter „Alle“). Die Seite lädt sich nach 10 min ohne Eingabe neu (Review Focus 2). Aufruf leert die Tablet-Sitzung.
  - `POST tablet/waehlen/(:num)`: Sammelkonto → Session `tablet_konto_id`, Redirect `tablet/buchen`; Mitglied ohne PIN → zurück mit Meldung; sonst Redirect `tablet/pin/{id}`.
  - `GET/POST tablet/pin/(:num)`: Bildschirm-Ziffernfeld. Prüft PIN mit `PinSperre` (`pin_fehlversuche`/`pin_gesperrt_bis`). Meldungen `"PIN falsch."` bzw. `"Zu viele Fehlversuche. Bitte in 5 Minuten erneut versuchen."`. Erfolg → Session `tablet_konto_id`, `tablet_seit`.
  - `GET tablet/buchen`: gleiche View wie Task 10 im Modus `tablet` (keine Konto-Auswahl, Name oben, Button „Abbrechen“). `data-timeout-s` aus der Einstellung: `buchen.js` geht nach so vielen Sekunden ohne Eingabe und nach der Bestätigung (Anzeige `timeout_s` Sekunden, höchstens 10 s) per `POST tablet/fertig` zur Namensauswahl.
  - `POST tablet/buchen` (JSON wie Task 10, ohne `konto`): `quelle='tablet'`, `geraet_id` gesetzt, `gebucht_von_id` = Person bzw. `NULL` bei Sammelkonto; merkt `tablet_letzter_vorgang`. Die Tablet-Sitzung gilt höchstens 300 s ab `tablet_seit`, sonst 401 `{ok:false, meldung:"Sitzung abgelaufen – bitte Namen erneut wählen."}`.
  - `POST tablet/rueckgaengig`: nur `vorgang_id == tablet_letzter_vorgang`, sonst 403.
  - `POST tablet/fertig`: Tablet-Sitzung leeren, Redirect `tablet`.
  - Routen-Zugriff: Ohne Geräte-Cookie leiten alle `tablet/*`-Routen (außer `freischalten`) auf `tablet/freischalten` um.

- [ ] **Step 1: Failing tests.**
  - `test_namensauswahl_zeigt_couleur_bund_und_12_zuletzt_aktive()` (14 Personen mit Buchungen anlegen)
  - `test_person_ohne_pin_nicht_waehlbar()`
  - `test_archivierte_person_nicht_waehlbar()` (Review Focus 4)
  - `test_pin_richtig_fuehrt_zum_buchen()`
  - `test_pin_fuenfmal_falsch_sperrt()`
  - `test_couleur_ohne_pin_bebuchbar_gebucht_von_null()`
  - `test_tablet_buchung_speichert_geraet_und_quelle()`
  - `test_rueckgaengig_nur_letzter_vorgang()`
  - `test_tablet_sitzung_laeuft_nach_300s_ab()`
  - `test_pin_post_ohne_session_leitet_mit_hinweis_um()` (Review Focus 2: POST ohne gültigen CSRF-Token in leerer Session → Redirect statt 403)
  - `test_ohne_geraet_kein_zugriff()`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS. Sichtprüfung im Tablet-Querformat (1280×800): Kacheln, Ziffernfeld, Buchen, automatische Rückkehr.
- [ ] **Step 5: Commit** – `"Tablet: Namenskacheln, PIN, Buchen, Rückgängig, Timeout"`

---

### Task 16: Admin – Einstellungen und Protokoll-Ansicht, Zugriffs-Sweep, Abschluss

**Files:**
- Create: `app/Controllers/Admin/EinstellungenController.php`, `app/Controllers/Admin/ProtokollController.php`, `app/Views/admin/einstellungen/index.php`, `app/Views/admin/protokoll/index.php`
- Modify: `app/Models/ProtokollModel.php`, `app/Config/Routes.php`, `CLAUDE.md`, `README.md`
- Test: `tests/feature/AdminEinstellungenTest.php`, `tests/feature/ZugriffsschutzTest.php`

**Interfaces:**
- Produces:
  - `GET/POST admin/einstellungen`: Felder aus `EinstellungDefinition::aenderbar()`, Validierung über `Einstellungen::setze`; `inbetriebnahme_at` nur angezeigt.
  - `GET admin/protokoll`: Filter Person, Tabelle, von/bis (Datum); 50 je Seite (CI-Pager), neueste zuerst; alt/neu als Schlüssel-Wert-Liste.
  - `ProtokollModel::gefiltert(array $filter): ProtokollModel` (Query-Builder für `paginate(50)`).

- [ ] **Step 1: Failing tests.**
  - `AdminEinstellungenTest`:
    - `test_storno_frist_aendern_wirkt_auf_storno()` (auf 0 setzen → Storno nach 1 s abgelehnt)
    - `test_ungueltiger_wert_zeigt_fehler()`
    - `test_inbetriebnahme_nicht_aenderbar()`
  - `ZugriffsschutzTest::test_routenmatrix()` mit Datenanbieter über **alle** Routen aus `php spark routes` (fest im Test aufgelistet). Für jede Route vier Akteure:
    - anonym → Redirect `login` (Tablet-Routen: `tablet/freischalten`)
    - Mitglied → Admin-Routen 403, eigene Routen 200/302
    - Admin → 200/302
    - Tablet-Cookie → nur `tablet/*`
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** Gesamte Suite: `docker exec getraenkeliste-web vendor/bin/phpunit` → alles grün.
- [ ] **Step 5: Ende-zu-Ende-Sichtprüfung** gegen die Entwicklungs-DB:
  1. `php spark migrate`, `php spark admin:anlegen`
  2. Login → Pflichtseite → PIN setzen
  3. CSV mit 3 Personen importieren
  4. Kategorie und 3 Artikel anlegen
  5. Freischaltcode erzeugen und in einem zweiten Browserprofil als Tablet freischalten
  6. Am Tablet buchen (Person und Couleur), Rückgängig
  7. Am eigenen Gerät buchen auf Bund, Meine Buchungen prüfen
  8. Protokoll ansehen

  Darkmode und Handybreite prüfen.
- [ ] **Step 6: Doku.** CLAUDE.md (vollständige Architektur-Landkarte, Befehle inkl. `admin:anlegen`, Invarianten: Idempotenz über `vorgang_id`, `$regenerate=false`, `service('uhr')`, Geräte-Cookie gleitend, keine Hashes im Protokoll) und README (Inbetriebnahme Spec Abschnitt 11, Schritte 1–3 und 5; Start-Auszählung und Backup folgen in Stufe 2).
- [ ] **Step 7: Commit und PR.** `git commit -m "Admin: Einstellungen und Protokoll, Zugriffsschutz-Tests, Doku"`, dann `git push -u origin feature/stufe-1-kern` und PR gegen `main`.
