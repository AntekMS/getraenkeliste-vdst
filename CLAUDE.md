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
- Bootstrap 5 + Bootstrap Icons (CDN, Theme-CSS `public/css/app.css`)
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
- `BACKUP_MOUNT= BACKUP_DIR=./backups ./scripts/backup.sh` — Backup lokal testen (liest die Dev-DB nur;
  `backups/` ist ignoriert). Restore-Proben nur mit `DB_NAME=<wegwerf_db>`, nie gegen `getraenkeliste`
- `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)":/mnt -w /mnt koalaman/shellcheck:stable scripts/*.sh docker/entrypoint.sh` — Shellcheck

## Workflow
- Hauptbranch ist **`main`**. Änderungen auf Feature-Branch, am Ende PR gegen `main`.
- Commit-Nachrichten auf Deutsch.
- **Doku aktuell halten**: Nach jeder abgeschlossenen Aufgabe diese `CLAUDE.md` (Landkarte,
  Befehle, Invarianten) auf den Stand des Codes bringen.
- `.gitattributes` erzwingt LF, damit in den Container gemountete Dateien unter Windows
  nicht mit CRLF ankommen.

## Architektur-Landkarte
Aufbau (Stufe 1 komplett, plus Backup/Pi-Deployment aus Stufe 2):
- `app/Config/` — angepasst: `App` (Europe/Berlin, Locale `de`, kein `index.php` in URLs),
  `Security` (CSRF `session`), `Session`, `Cookie`, `Filters` (`csrf` global), `Database`
  (liest `DB_HOST/DB_NAME/DB_USER/DB_PASS` aus der Compose-Umgebung)
- `app/Database/Migrations/` — 000001 Grundtabellen (`bereiche`, `personen`, `person_rollen`,
  `anmelde_tokens`, `einstellungen`, `protokoll`), 000002 `kategorien`/`artikel`, 000003 `buchungen`,
  000004 `geraete`/`freischaltcodes`, 000005 Startdaten (Bereiche `getraenke` aktiv / `kiosk`
  inaktiv, Sammelkonten Couleur/Bund, Einstellungs-Defaults, `inbetriebnahme_at` = jetzt),
  Stufe 2: 2026-10-06-000001 Bestand (`buchungen.bemerkung`, `bestandsbewegungen`, `auszaehlungen`,
  `auszaehlung_positionen` mit `start`-Flag und `ist` NULL im Entwurf).
  Raw-SQL, InnoDB, utf8mb4_unicode_ci, FKs `ON DELETE RESTRICT`. Migrationen referenzieren
  **keine** App-Klassen (Konstanten werden wiederholt; `MigrationTest` prüft sie gegen die App).
- `app/Models/` — CI4-Models (`array`, `$useTimestamps`): `PersonModel` (`rollen`,
  `findeAktivNachBenutzername`, `sammelkontoId`, `istAktiv`), `BereichModel::aktive` und `::sperre(ids)` (`SELECT … ORDER BY id FOR UPDATE`, nur in Transaktionen, als erste Abfrage),
  `AnmeldeTokenModel::loescheFuerPerson`, `ArtikelModel::buchbar/findeBuchbar`, dazu schlanke
  Models für Kategorie, Buchung, Gerät, Freischaltcode, Einstellung, Protokoll, PersonRolle.
  Stufe 2: `AuszaehlungModel` (`letzteAbgeschlossene`, `entwurf`, `abgeschlossene`),
  `AuszaehlungPositionModel::fuer`, `BestandsbewegungModel` (alle mit Trait `Transaktion`).
  PhpSpreadsheet (`phpoffice/phpspreadsheet:^5`) braucht die PHP-Erweiterungen `gd` und `zip` (Dockerfile);
  `GETRAENKELISTE_VERSION` in `Config/Constants.php`.
- `app/Libraries/Uhr.php` + `Config/Services.php` — Shared Services `uhr()`, `einstellungen()`,
  `protokollierer()`. **Jede zeitabhängige Stelle holt „jetzt“ über `service('uhr')->jetzt()`**
  (`DateTimeImmutable`, Europe/Berlin), nie `new DateTime()`/`time()`. `Einstellungen` (`int`, `text`,
  `inbetriebnahme`, `setze` → Fehlertext|null, protokolliert alt/neu) lädt die Werte je Request einmal.
  `Protokollierer::schreibe` entfernt oberste-Ebene-Schlüssel auf `_hash`, speichert JSON ohne
  Unicode-Escapes (MySQL normalisiert beim Lesen zu `{"wert": "10"}` → in Tests dekodiert vergleichen).
- Stufe 2, reine Rechenklassen (statisch, ohne DB): `BestandRechner` (Bestand, Ampel negativ>leer>niedrig>ok),
  `AuszaehlungRechner` (Position/Soll/Differenz, `schwundCent` = positiver Betrag ohne Start-Positionen, `pruefeStichtag`),
  `Lieferumrechnung` (Kisten × Gebinde + Stück).
- `app/Libraries/BuchungService.php` (Service `buchungen()`, wirft `BuchungAbgelehnt` mit deutscher UI-Meldung):
  `bucheVorgang(vorgangId UUIDv4, kontoId, gebuchtVonId, geraetId, quelle web|tablet, positionen)` — bekannte `vorgang_id`
  wird **vor** jeder Fachprüfung beantwortet (gespeichertes Ergebnis, `wiederholt=true`; Konto UND gebucht_von müssen passen,
  sonst „Ungültiger Vorgang.“); sonst alles-oder-nichts in einer manuellen Transaktion (`transBegin/Commit/Rollback`), ein
  `gebucht_at` je Vorgang, aktueller Preis; Unique-Verletzung im Wettlauf → Rollback + gespeichertes Ergebnis. Doppelte
  Artikel werden addiert (Summe ≤ 99), max. 30 Positionen. `storniereVorgang/storniereBuchung` prüfen Doppel-Storno, Frist
  (`storno_frist_min` ab `gebucht_at`) und Einfrieren (`zeitraeume()->istEingefroren`, **vor** der Frist, Meldung „Dieser Zeitraum ist abgeschlossen.“); **wer**
  stornieren darf, entscheidet der Controller. `zusammenfassung()` ist statisch/rein. **Transaktionen laufen über `BuchungModel::transaktion()` (Trait `Transaktion`) mit `transException(true)`: CI4 wirft in Transaktionen sonst nicht, ein fehlgeschlagener Query würde still Teilergebnisse committen.** Storno-Update mit `storniert_at IS NULL` + `affectedRows`. Ergebnis enthält `storniert` (Replay eines inzwischen stornierten Vorgangs).
  **Bereichssperre (Stufe 2, Entscheidung 1):** Buchen und Storno sperren in ihrer Transaktion zuerst die Bereiche der Artikel
  (`BereichModel::sperre`), leeren den `zeitraeume`-Cache, lesen dann „jetzt“ und den Stichtag; Buchen in einen eingefrorenen
  Zeitraum (Abschluss mit Stichtag ≥ jetzt schon committet) → „Dieser Zeitraum ist abgeschlossen. Nicht gebucht.“. Testnaht
  `vorDemSchreiben` läuft **in** der Transaktion direkt nach der Sperre (Buchen und Storno); Konkurrenz-Tests schreiben daher über
  eine zweite Verbindung (`\Config\Database::connect('tests', false)`, danach `close()`).
- `app/Libraries/Zeitraeume.php` (Service `zeitraeume()`, je Request gecacht, `vergiss()` leert): `letzterStichtag(bereichId)` (nur
  **abgeschlossene** Auszählungen), `beginn` (Stichtag oder Inbetriebnahme), `beginnInklusiv` (nur ohne Abschluss), `istEingefroren(zeit, bereichId)`
  (≤ Stichtag), `fruehere` (`von`/`bis`/`von_inklusiv`/`auszaehlung_id`, neueste zuerst; `von` = vorheriger Stichtag bzw. Inbetriebnahme).
  `BuchungModel::fuerKonto/vonPersonAufSammelkonten(…, ab, abInklusiv, bereichId)`, `offenerBetrag(…, ab, abInklusiv)`,
  `summeImZeitraum(konto, bereich, von exkl., bis inkl., vonInklusiv)`.
- `tests/_support/DbTestCase.php` — Basisklasse für DB-Tests (Migrationen laufen vor jedem
  Test frisch gegen `getraenkeliste_test`); Helfer `personAnlegen`, `rolleGeben`,
  `artikelAnlegen`, `bereichId`, `auszaehlungAnlegen(stichtag, status, bereich)`, `alsAngemeldet`/`angemeldeteSitzung` (inkl. Passwort-Fingerabdruck), `csrf`, `uhrStellen('Y-m-d H:i:s')` (fixiert `service('uhr')`;
  `tearDown` setzt alle Services zurück, damit Mocks/Einstellungs-Cache nicht lecken) (Passwort-Hashes mit Kosten 4 für Tempo)
- `app/Libraries/Anmeldung.php` (Service `anmeldung()`) — Login am eigenen Gerät:
  `pruefePasswort` → `passwortPruefen($person, $passwort)` (auch für das aktuelle Passwort auf `konto/passwort` und `konto/pin`):
  beansprucht den Versuch **vor** `password_verify` atomar über `Versuchszaehler('login')`, dann Passwort, Erfolg → Zähler zurück;
  unbekannte/archivierte Person = dieselbe Meldung wie falsches Passwort (einmal `password_verify` gegen `DUMMY_HASH`, Timing).
  `anmelden` (`regenerate(true)`, setzt `person_id` + `passwort_fingerabdruck` = sha256 des `passwort_hash`), `abmelden` (Session leeren),
  `person()` (nicht archiviert **und** Fingerabdruck passt zum aktuellen Hash, sonst Abmeldung → Filter leitet zu `login`), `rollen()`.
  Passwort-Reset durch den Admin beendet so alle offenen Sitzungen; eigener Wechsel (`konto/passwort`, `konto/einrichten`) ruft
  `fingerabdruckAktualisieren()` → nur die aktuelle Sitzung bleibt angemeldet. Tests: `alsAngemeldet`/`angemeldeteSitzung` setzen den Fingerabdruck.
- `app/Libraries/Versuchszaehler.php` — atomarer Fehlversuch-Zähler für Präfix `login`/`pin` (Spalten `<präfix>_fehlversuche`/`_gesperrt_bis`):
  `beanspruchen()` = ein UPDATE `… WHERE id = ? AND (gesperrt_bis IS NULL OR gesperrt_bis <= jetzt)` (SET-Reihenfolge: Sperre aus altem Zähler,
  dann Zähler; 5. Versuch → Sperre jetzt + 5 min, Zähler 0); 0 Zeilen = gesperrt (Zähler unverändert). `gesperrt()`, `erfolg()` (zurücksetzen).
  `PinSperre` liefert nur noch Schwelle/Dauer und `istGesperrt`.
- „Angemeldet bleiben“: Cookie `gl_merken` = `<selector 24 hex>:<validator 64 hex>` (90 Tage, httponly, Lax, `secure` explizit aus `Config\Cookie::$secure` wie `gl_geraet`),
  DB nur `sha256(validator)` (`AnmeldeTokenModel::erzeuge/rotiere/loescheCookie/loescheFuerPerson`). Filter `angemeldet` stellt
  ohne Session per `Anmeldung::ausCookieAnmelden` her (rotiert; archivierte Person → alle Tokens weg; falscher Validator → alle
  Tokens der Person weg). **Cookie-Änderungen wendet `AnmeldungFilter::after()` auf die gesendete Antwort an; `->withCookies()` nur für Redirects aus `before()` und ungefilterte Routen (login)** (RedirectResponse ist eine neue
  Response). Archivieren/Passwort-Reset müssen `loescheFuerPerson` + `merkCookieLoeschen` aufrufen. Tests setzen den Cookie über
  `service('superglobals')->setCookie(...)`, nicht `$_COOKIE`.
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
  (`recht:eigene_stornieren`). Laufender Zeitraum je aktivem Bereich ab `zeitraeume()->beginn` (`beginnInklusiv`), darunter „Frühere Zeiträume“ je Bereich (von – bis, eigene Summe über `summeImZeitraum`, ausgeblendet ohne Abschluss). `BuchungModel::fuerKonto`,
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
- `docker/entrypoint.sh` — ENTRYPOINT des Web-Images (als `/usr/local/bin/getraenkeliste-entrypoint`, `CMD apache2-foreground`
  explizit, weil ein eigener ENTRYPOINT das geerbte CMD zurücksetzt): `chown -R www-data` + `chmod -R u+rwX,g+rwX` auf `writable/`
  (Linux-Host/Pi: Bind-Mount behält Host-UID), Fehler nur als Hinweis (Docker Desktop), dann `exec docker-php-entrypoint "$@"`.
  Image-Änderung erst nach `docker compose up -d --build --force-recreate getraenkeliste-web` aktiv.
  `.dockerignore` hält `.env`, Override, `.git`, `vendor/`, `writable/`, `backups/` aus dem Build-Kontext (keine Geheimnisse im Image;
  `vendor/` entsteht im Build per `composer install --no-dev`). `.env` auf dem Pi: `chown <user>:33`, `chmod 640` (Apache liest sie über den Bind-Mount).
- Backup/Betrieb (Task 18, aus Stufe 2 vorgezogen): `scripts/backup.sh` (Host, bash; DB-Dump per `docker exec -e MYSQL_PWD … mysqldump`,
  `exporte_*.tar.gz` aus `writable/exporte/`, `konfig_*.tar.gz` mit `.env`/Override, `umask 077`, Monats-Promotion, Retention;
  Mount-Prüfung `BACKUP_MOUNT` (leer = aus), dann ist `DB_PASS` Pflicht; alles erst als `*.tmp`, geprüft, dann `mv`, `trap` räumt `*.tmp` weg),
  `scripts/restore.sh` (prüft „Dump completed“ der Quelle, Rückfrage „ja“/`--ja`, ohne TTY nur mit `--ja`, stoppt `WEB_CONTAINER` und startet ihn per `trap` wieder, Sicherheits-Dump
  nach `BACKUP_DIR/vor-restore/`, Export-Archiv nur mit `exporte/`-Pfaden, Konfig nie automatisch), `deploy/systemd/` (Timer 02:30,
  `EnvironmentFile=/etc/getraenkeliste-backup.env`, Vorlage `deploy/getraenkeliste-backup.env.example`). Doku: `docs/BACKUP.md`,
  `docs/DEPLOY-PI.md` (Pi-Installation, Update, Tablet, Fehlersuche). Skripte mit LF und Git-Modus 755 committen.

- Admin Personen (`/admin/*`, Filter `angemeldet` + `recht:admin`, Namespace `App\Controllers\Admin`): `PersonenController` (Liste mit Filter
  gruppe/archiviert, Anlegen, Bearbeiten + Rollen-Checkboxen, `passwort-reset`, `pin-reset`, `archivieren`, `einmalpasswoerter`),
  `PersonenImportController` (CSV: `vorschau` legt die geparsten Zeilen in die Session `import_zeilen`, `ausfuehren` prüft Benutzername/Name
  in der Transaktion erneut und überspringt inzwischen Vergebene). `CsvPersonenParser` rein (BOM, Windows-1252, CRLF, Leerzeilen).
  Sammelkonten sind in der Admin-Personenverwaltung 404. Admin kann sich nicht selbst archivieren, die Admin-Rolle entziehen oder das eigene Passwort zurücksetzen (Link zu `konto`; PIN-Reset für sich selbst bleibt erlaubt).
  **Einmal-Passwörter nur als Flash `einmalpasswoerter` (PRG), nie im Protokoll** (Protokoll kennt nur `passwort_reset` ohne Werte).
  `PersonModel::transaktion()` = Transaktion mit `transException(true)` (R12), `legeMitgliedAn`, `mitglieder`, `benutzernameVergeben`.
  Upload-Prüfung ohne `isValid()` (Tests setzen `service('superglobals')->setFilesArray`).

- Admin Kategorien/Artikel (`/admin/stammdaten`, Nav „Getränke & Preise“): `StammdatenController`. Nur **aktive Bereiche**; alles in einem
  inaktiven Bereich (Kiosk) ist 404 (Anlegen/Ändern/Verschieben/Archivieren). Archivierte nur mit `?archiviert=ja`; Archivierte sind nicht
  bearbeitbar, Entarchivieren gibt es nicht. Kategorie archivieren archiviert Artikel nicht (sind aber nicht buchbar). Artikel-Umzug nur in
  aktive, nicht archivierte Kategorien, danach ans Ende (`naechsteSortierung`). Preis: `betrag_in_cent` (max. 7 Euro-Stellen), Preis 0 erlaubt;
  Protokoll `preis_geaendert` (alt/neu `preis_cent`) getrennt von `geaendert`. **Sortieren (`verschiebe`) nicht protokolliert**; `Sortierung::tausche`
  + lückenlose Neunummerierung (robust gegen Gleichstände), Rand = No-op. `Transaktion`-Trait (`transaktion()` mit transException) in Person-/Kategorie-/Artikel-/BuchungModel u. a.

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
  PIN: `Versuchszaehler('pin')->beanspruchen()` **vor** `password_verify` (gesperrt → abgelehnt, Zähler unverändert; atomar, kein
  Lesen-und-Zurückschreiben), 4–6 Ziffern, falsch → „PIN falsch.“ bzw. Sperrmeldung, wenn dieser Versuch gesperrt hat;
  Erfolg → `erfolg()` mit Rückgabeprüfung (fail closed). `tablet_timeout_s` (Einstellung) ist auf höchstens 300 s begrenzt (= Sitzungsdauer). Buchen: `quelle='tablet'`, `geraet_id` aus Cookie, `gebucht_von_id` =
  Person bzw. NULL bei Sammelkonto, `konto` im Body wird ignoriert; Rückgängig nur für `tablet_letzter_vorgang` (sonst 403). Gemeinsame
  JSON-Logik (Positionsprüfung, Antwortformat) in Trait `Controllers\Concerns\BuchungsAntworten` (auch `BuchenController`).
  `buchen/index.php` hat Modus `web|tablet` (Tablet: `layouts/einfach` mit Section `seitenklasse` = `login-breit`, `data-fertig-url`,
  `data-timeout-s`); `buchen.js` geht bei Leerlauf (`timeout_s`) und nach Bestätigung (min(`timeout_s`,10) s) per Formular-POST `tablet/fertig`.
  **CSRF am Tablet:** `tablet/*`-POSTs sind vom globalen `csrf` ausgenommen (`Config\Filters`); `TabletCsrfFilter` leitet Formular-POSTs mit
  ungültigem Token zu `tablet` mit Flash (statt `redirect()->back()`), JSON/AJAX bleibt 403. Neue Tablet-Routen gehören in die Gruppe `tablet` in `Routes.php`.

- Admin Einstellungen/Protokoll (Task 16, `Admin\EinstellungenController`, `Admin\ProtokollController`): `admin/einstellungen` zeigt je Eintrag aus
  `EinstellungDefinition::aenderbar()` ein Feld (Label, Bereichs-Hinweis), `inbetriebnahme_at` nur als gesperrtes Anzeigefeld (`d.m.Y H:i`).
  POST validiert **alle** Felder zuerst (ein Fehler → nichts gespeichert, Formular mit Fehlern je Feld), dann `Einstellungen::setze` (unveränderte
  Werte werden übersprungen, nicht protokolliert); fehlende/nicht änderbare Felder werden ignoriert. Flash „Einstellungen gespeichert.“.
  `admin/protokoll`: Filter `person`, `tabelle`, `von`, `bis` (ungültige Daten werden ignoriert), neueste zuerst, 50 je Seite
  (`ProtokollModel::gefiltert()` + `paginate`; Seite wird explizit aus `?page=` gelesen (nur Ziffern, auf 1..letzte Seite begrenzt; Array-Parameter werden ignoriert); Pager-Template `bootstrap_full` in `Views/pagers`,
  `Config\Pager`), alt/neu als escapte Schlüssel-Wert-Liste, Person „System“ bei `person_id` NULL.
- `tests/feature/ZugriffsschutzTest` — Routenmatrix: **jede** Route (feste Liste `ROUTEN`, bei neuen Routen ergänzen — `test_routenliste_entspricht_den_registrierten_routen`
  gleicht sie mit `service('routes')->getRoutes()` aller Verben ab und wird sonst rot) × anonym/mitglied/admin/tablet;
  ein Test je Fall (Session-CSRF/Cookie leben nicht über mehrere Requests), `$refresh = false` + `uniqid`-Benutzernamen für Tempo (~20 s statt ~3 min).
  `php spark routes` zeigt für `verschieben/(hoch|runter)` fälschlich `<unknown>`-Filter (Anzeigefehler, die Filter greifen; der Test belegt es).

## Konventionen & Invarianten
- Geheimnisse und Infrastruktur nur in `.env`; `app.baseURL` und `cookie.secure` nur dort. `App::$baseURL` defaultet auf `http://localhost:8090/` (Dev); auf dem Pi muss `.env` `app.baseURL` setzen (Compose-Env erreicht CI nicht).
  `CI_ENVIRONMENT` defaultet in `docker-compose.yml` auf `development`; auf dem Pi muss `.env` `CI_ENVIRONMENT=production` setzen (ohne Leerzeichen, Compose liest die Datei mit; README).
  Compose ignoriert die CI-Zeilen mit Punkten (`app.baseURL = '…'`, `cookie.secure = true`) – mit `docker compose --env-file <tmp> config` geprüft. `cookie.secure = true` nur hinter HTTPS (sonst kein Login).
- **Backups (Task 18):** neue persistente Daten außerhalb der DB gehören in `writable/exporte/` oder müssen in `scripts/backup.sh` ergänzt werden; neue Geheimnisse nur in `.env` (landen im Konfig-Archiv).
- `Security::$regenerate = false` ist Pflicht (doppeltes Absenden mit demselben CSRF-Token
  muss idempotent bleiben; festgenagelt in `ZugriffsschutzTest::test_csrf_token_wird_nach_post_nicht_regeneriert`); `Security::$redirect = true` — Formular-POST ohne gültiges
  CSRF-Token wird zurückgeleitet statt 403 (JSON/AJAX bekommt 403).
- Session läuft nach 8 Stunden ab (`Session::$expiration = 28800`).
- `phpunit.xml.dist` trägt das Test-DB-Passwort als Literal (= Compose-Default
  `getraenkepass123`); phpunit expandiert keine `${DB_PASS}`.
- Alle Meldungen auf Deutsch.
- **Idempotenz:** jede Buchung trägt eine `vorgang_id` (UUIDv4, serverseitig erzeugt, wandert mit dem Formular); Wiederholung liefert das gespeicherte Ergebnis.
- **Zeit:** nie `new DateTime()`/`time()` in App-Code, immer `service('uhr')->jetzt()` (Tests fixieren sie mit `uhrStellen`).
- **Cookies (R10):** Cookie-Änderungen (`gl_merken`, `gl_geraet`) setzt der Filter in `after()` auf die gesendete Antwort; `->withCookies()` nur bei Redirects aus `before()` und ungefilterten Routen. Geräte-Cookie wird gleitend bei jeder Tablet-Antwort erneuert.
- **Transaktionen (R12):** mehrstufige Schreibvorgänge laufen in `transaktion()` (Trait `App\Models\Transaktion` mit `transException(true)`, stellt den vorherigen Wert wieder her); sonst committet CI4 Teilergebnisse still. **Einzige Implementierung** — keine eigene Kopie in Services (BuchungService nutzt `BuchungModel::transaktion()`).
- **Fehlversuche (Login/PIN):** nur über `Versuchszaehler` (atomares UPDATE, Versuch vor dem Hash-Vergleich beansprucht). Nie Zähler lesen, +1 rechnen und zurückschreiben — parallele Requests umgingen sonst die 5er-Sperre. Ein falsches aktuelles Passwort im Konto zählt als Login-Fehlversuch.
- **Sitzung ↔ Passwort:** Session trägt `passwort_fingerabdruck` (sha256 des `passwort_hash`); `Anmeldung::person()` meldet bei Abweichung ab. Wer `passwort_hash` der eigenen Sitzung ändert, ruft `fingerabdruckAktualisieren()`; Reset durch Admin beendet alle Sitzungen der Person.
- **Tablet-CSRF-Ausnahme:** `tablet/*`-POSTs sind vom globalen `csrf` ausgenommen (`Config\Filters`) und nur über `tablet` + `tablet_csrf` erreichbar; neue Tablet-Routen gehören in die Routengruppe `tablet`. Alle übrigen POSTs behalten `csrf` (+ `Security::$regenerate = false`).
- **Protokoll:** nie Hashes, Passwörter, PINs, Freischalt-/Einmalcodes (`Protokollierer` entfernt `*_hash`-Schlüssel; Klartext-Geheimnisse gehören nicht hinein). Sortieren und eigene Buchungen/Stornos werden nicht protokolliert.
- **Rechte:** Berechtigung nur über Filter (`angemeldet`, `recht:<aktion>`, `tablet`, `kein_tablet`) in `Routes.php`; neue Route ⇒ `ZugriffsschutzTest` erweitern.

## Bewusste Abweichungen vom Kassensystem (Spec Abschnitt 3)
- Rollen und Mehrbenutzerbetrieb sind der Zweck dieser App.
- Tabelle `einstellungen`, beschränkt auf vom Admin änderbare Betriebswerte.
- Beträge als Cent-INT statt DECIMAL; Komma-Eingabe über `normalisiere_betrag()`.
