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
Aufbau (Stufe 1 und 2 komplett, inkl. Artikelbilder):
- `app/Config/` — angepasst: `App` (Europe/Berlin, Locale `de`, kein `index.php` in URLs),
  `Security` (CSRF `session`), `Session`, `Cookie`, `Filters` (`csrf` global), `Database`
  (liest `DB_HOST/DB_NAME/DB_USER/DB_PASS` aus der Compose-Umgebung)
- `app/Database/Migrations/` — 000001 Grundtabellen (`bereiche`, `personen`, `person_rollen`,
  `anmelde_tokens`, `einstellungen`, `protokoll`), 000002 `kategorien`/`artikel`, 000003 `buchungen`,
  000004 `geraete`/`freischaltcodes`, 000005 Startdaten (Bereiche `getraenke` aktiv / `kiosk`
  inaktiv, Sammelkonten Couleur/Bund, Einstellungs-Defaults, `inbetriebnahme_at` = jetzt),
  Stufe 2: 2026-10-06-000001 Bestand (`buchungen.bemerkung`, `bestandsbewegungen`, `auszaehlungen`,
  `auszaehlung_positionen` mit `start`-Flag und `ist` NULL im Entwurf), 2026-10-07-000001 Artikelbild (`artikel.bild_datei` VARCHAR(64) NULL,
  `artikel.bild_version` INT UNSIGNED DEFAULT 0), 2026-10-08-000001 Bestandswirksam (`buchungen.bestandswirksam` TINYINT(1) NOT NULL DEFAULT 1).
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
- Statistik (Task 1): `StatistikRechner` (statisch, rein: `grundlageTage` = Kalendertage Inbetriebnahme→jetzt, 1..28; `tagesverbrauch`, `reichweiteTage`, `vorschlag` (⌈Verbrauch×Reichweite+Mindest−Bestand⌉, Kisten bei Gebinde > 0),
  `anteile`, `quote`, `isoWoche` (`o-\WW`), `letzteWochen` (ab Montag der laufenden Woche per `modify`, älteste zuerst)); Einstellung `reichweite_tage` (7–90, Default 30, Migration 2026-10-09-000001, in `EinstellungDefinition`).
- Statistik (Task 2): `Libraries/StatistikService` (Service `statistik()`), je Methode wenige Aggregatabfragen, keine Abfrage je Artikel. `einkauf(bereichId)` = `bestand()->fuerBereich`
  (Bestand/Ampel unverändert) + `gebinde_groesse`, `tagesverbrauch`, `reichweite`, `vorschlag`, `letzter_ek_cent` (jüngste Lieferung mit Preis, `ROW_NUMBER()`), dazu `grundlage_tage`/`reichweite_tage`.
  **Verbrauch** (28 Tage = (jetzt − 28 days, jetzt] per `modify`, und Wochen) = nicht stornierte Buchungen mit `bestandswirksam = 1`; **Anteile** (`anteile(bereich, von, vonInklusiv, bis)`,
  Menge + Cent mit `CAST … AS SIGNED`, Schlüssel `mitglieder`/`couleur`/`bund` über `sammelkontoId`) = alle nicht stornierten Buchungen inkl. nicht bestandswirksamer Korrekturen.
  `wochenverbrauch(bereich, ansicht, wochen)` bucketet in SQL mit `DATE_FORMAT(gebucht_at, '%x-W%v')` (= `isoWoche`, Test über den Jahreswechsel), fehlende Wochen 0; `ansicht` `alle` (je nicht archivierter
  Kategorie eine Reihe über alle ihre Artikel), `kategorie:<id>` (je Artikel eine Reihe „Name (Einheit)“, archivierte nur mit Verbrauch), `artikel:<id>` (eine Reihe); bereichsfremd/ungültig → `alle`,
  Ergebnis-Schlüssel `ansicht` = tatsächlich verwendete Ansicht. `lieferhistorie(bereich, anzahl)` gruppiert nach (`erfolgt_at`, `person_id`), neueste zuerst, `erfasst_von` = Anzeigename.
- Statistik (Task 3, Schwund): `schwundZeitraeume(bereich, anzahl=6)` (neueste zuerst), `schwundArtikel(bereich, zeitraeume=6, limit=10)` (nur Artikel mit Fehlmenge > 0, `gesamt_cent` absteigend, dann Name),
  `schwundArtikelVerlauf(bereich, artikel, zeitraeume=6)` (bereichsfremder Artikel → `[]`), `schwundLaufend(bereich)` (Schwund-Bewegungen ab `zeitraeume()->beginn`, aktueller Preis). Gemeinsam über
  `schwundDaten` mit festen vier Abfragen (Auszählungen, Positionen, Bewegungen, Artikel). Zeitraum einer abgeschlossenen Auszählung **nur** über `AuszaehlungModel::zeitraum(auszaehlung, ersteAbgeschlossene)`
  ((`zeitraum_von`, `stichtag`], Beginn inklusiv nur bei der ersten; nutzt auch der Excel-Export) bzw. `letzteMitZeitraum(bereich, n)` (lädt n+1). Erfasst = Σ |Schwund-Bewegung| × `preis_cent` der Position
  (ohne Position aktueller Preis); unerklärt/Überschuss = gespeicherte `differenz` × `preis_cent` der Positionen mit `start = 0`; Quote = (erfasst + unerklärt) ÷ Σ `verkauft` der Positionen.
  **Start-Auszählung (`art = start`, S3-R5):** alle Schwundwerte 0, Quote null, trägt auch zu `schwundArtikel`/`…Verlauf` nichts bei (weder Schwund noch verkauft). `anzahl ≤ 0` → `[]`.
  Artikelnamen „Name (Einheit)“ wie in den Wochenreihen.
- Statistik (Task 4, Seite „Einkauf“): `GET wart/<bereich>/einkauf` (`Wart\EinkaufController`, View `wart/einkauf`, Filter `recht:statistik_ansehen@<bereich>`) ersetzt die Bestandsseite;
  `GET …/bestand` leitet per 301 auf `einkauf` (gleicher Filter, inaktiver Bereich 404). Lieferung/Bewegung leiten nach `einkauf`, Auszählungsformular „Zurück zum Einkauf“.
  Abschnitte als `<section>` mit Klassen `bestellliste` (nur Artikel mit Vorschlag, Druck-Button `data-print data-print-bereich=".bestellliste"`), `bestand-tabelle` (+ „Ø Verbrauch/Tag“,
  „reicht noch ca.“: Bestand ≤ 0 → „leer“, kein Verbrauch → „—“), `anteile` (Kacheln „Letzte 28 Tage“ vs. 28 Tage davor und „Letzter abgeschlossener Zeitraum“ vs. vorherigen,
  Grenzen aus `letzteMitZeitraum(bereich, 2)`; Prozente über `StatistikRechner::anteile`, ohne Umsatz „—“), `verlauf` (GET-Formular `ansicht`, nur skalar über `WartEingaben::text`,
  Optionen aus `statistik()->ansichtOptionen(bereich)`; Tabelle Wochen × Reihen, laufende Woche „(bis heute)“; `<canvas class="js-diagramm" data-diagramm="…">` mit JSON `{labels, reihen}`
  via `esc(…, 'attr')` in einem `hidden`-Rahmen `.diagramm-rahmen` – Zeichnen erst Statistik Task 6), `lieferhistorie`. Feature-Tests: Testantworten sind DOM-serialisiert
  (Umlaute als Entities, Attribute ggf. in `'…'`) → Texte mit `html_entity_decode` vergleichen.
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
- Wart-Bereich (Stufe 2, Task 4): Routen `wart/<bereich>/…` (Schleife über `getraenke`/`kiosk` in `Routes.php`, Namespace `App\Controllers\Wart`, Filter
  `angemeldet` + `recht:<aktion>@<bereich>`; `RechtFilter` trennt am ersten `@`, leerer Bereich → 403). Der Controller bekommt den Schlüssel als Argument und
  liefert 404 für unbekannte/inaktive Bereiche (Kiosk bis Stufe 3; Getränkewart bekommt dort 403, Admin 404). Die Bestandstabelle steht seit der Statistik (Task 4) auf der Seite „Einkauf“ (`GET wart/<bereich>/bestand` → 301 auf `einkauf`,
  Ampel über `badge-status-*`). `Libraries/BestandService` (Service `bestand()`): `fuerBereich(bereichId)` (je Kategorie, nur `bestand_fuehren`
  und nicht archiviert) und `einzeln(artikelId)`; Bestand = Ist der letzten abgeschlossenen Position (sonst 0) + Bewegungen − nicht stornierte, **bestandswirksame** Buchungsmengen
  (`bestandswirksam = 1`) im laufenden Zeitraum (`zeitraeume()`), über Aggregatabfragen. Navigation „Getränkewart“ (Einkauf, Lieferung, Buchungen, Auszählung, Auszählungen) nur mit `darf(…, bestand_pflegen, getraenke)`.
- Bewegungen (Stufe 2, Task 5): `GET/POST wart/<bereich>/lieferung` und `…/bewegung` (`Wart\BewegungenController`, Views `wart/lieferung`, `wart/bewegung`,
  `public/js/lieferung.js` für weitere Zeilen aus `<template>`). `BestandService::liefere` (Kisten × Gebinde + Stück, optional EK je Stück, alles oder nichts,
  Feldfehler `zeilen.<i>.<feld>`) und `bucheBewegung` (`schwund` positiv eingegeben → negativ gespeichert, `korrektur` ±, nie 0, Bemerkung Pflicht). Beide laufen
  in `schreibend()`: Bereichssperre als erste Anweisung, Stichtag frisch, Einfrieren → „Dieser Zeitraum ist abgeschlossen.“, MySQL 1205/1213 → `BewegungAbgelehnt`
  „Gerade wird abgerechnet – bitte gleich erneut versuchen.“; Protokoll je Bewegung (`lieferung`/`schwund`/`korrektur`, Tabelle `bestandsbewegungen`).
  Fachliche Ablehnung = `BewegungAbgelehnt` (Message + `fehler`), der Controller leitet mit Flash `error`/`fehler` und `withInput()` zurück.
- Buchungsverwaltung (Stufe 2, Task 6): `GET wart/<bereich>/buchungen`, `POST …/buchungen/(:num)/storno`, `GET/POST …/korrektur` (`Wart\BuchungenController`, Views
  `wart/buchungen`, `wart/korrektur`, Recht `buchungen_verwalten@<bereich>`). Liste = laufender Zeitraum des Bereichs (`BuchungModel::imZeitraum`, Filter
  `person`/`artikel`/`tag` mit gehärteten Parametern, 50 je Seite). `BuchungService::storniereAlsWart` (Grund Pflicht, keine Storno-Frist, Einfrieren + Bereichssperre,
  setzt `storno_grund`, Protokoll `storniert`) und `bucheKorrektur` (`quelle = korrektur`, aktueller Preis, Menge ±1…99, Bemerkung Pflicht, archivierte Artikel erlaubt,
  Protokoll `korrektur`; `$bereichId` erzwingt den Bereich; Pflicht-Parameter `bool $bestandswirksam` → Spalte `bestandswirksam`, Formular-Checkbox `bestandswirksam`
  standardmäßig **aus** = Korrektur ändert nur den Betrag; web/tablet-Buchungen immer 1; steht im Protokoll). **Bestand/Soll/Verkauf** zählen nur `bestandswirksam = 1`
  (`BestandService::aggregat`), Geldbeträge (offener Betrag, Abrechnung, Positionen) alle nicht stornierten Buchungen. Beide sperren zuerst den Bereich (S2-R1). MySQL 1205/1213 → `BuchungAbgelehnt`/`BewegungAbgelehnt`
  „Gerade wird abgerechnet – bitte gleich erneut versuchen.“ über `BuchungService::sperrfehlerAbgelehnt`. Summen mit negativer Menge: `einzelpreis_cent` ist UNSIGNED →
  in SQL immer `CAST(einzelpreis_cent AS SIGNED)` vor der Multiplikation. Testhelfer `DbTestCase::beiGesperrtemBereich()` (zweite Verbindung hält die Bereichszeile).
  Lieferung: Menge je Zeile ≤ 1 000 000 (sonst Feldfehler „Menge zu groß.“).
- Auszählung Entwurf (Stufe 2, Task 7): `GET/POST wart/<bereich>/auszaehlung` (`Wart\AuszaehlungController`, View `wart/auszaehlung_formular`, `public/js/auszaehlung.js` (v=4),
  Recht `auszaehlung_durchfuehren@<bereich>`; POST `aktion=entwurf|abschliessen` über zwei Submit-Knöpfe, Entwurf zuerst im DOM). `Libraries/AuszaehlungService` (Service `auszaehlungen()`): `vorschlag(bereichId, stichtag)` = Soll je Artikel
  über **`BestandService::aggregat(bereichId, artikelIds, ?bis)`** (einzige Quelle für Anfangsbestand/Lieferungen/Schwund/Korrekturen/Verkauf; auch die Bestandsseite rechnet damit, `AuszaehlungServiceTest` pinnt Soll = Bestand)
  (Anfangsbestand = Ist der letzten abgeschlossenen Auszählung, Lieferungen/Schwund/Korrekturen/Verkauf im Fenster Beginn (inkl. nur ohne Abschluss) … Stichtag inklusive, `start` = Artikel hat keine Position in der
  **letzten** abgeschlossenen Auszählung (dann ist der Anfangsbestand 0 unbekannt); archivierte nur mit Aktivität), `speichereEntwurf` (höchstens ein Entwurf je Bereich, Positionen werden ersetzt, Soll als Momentaufnahme, `ist` NULL = ungezählt; Bereichssperre zuerst,
  Stichtag frisch geprüft, 1205/1213 → „Gerade wird abgerechnet …“; fachliche Fehler = `AuszaehlungAbgelehnt` mit Feldfehlern `stichtag`/`ist.<id>`). Stichtag-Eingabe `datetime-local` (Minutengenauigkeit),
  Bemerkung ≤ 1000 Zeichen, Array-Parameter zählen als leer. „Stichtag ändern“ = GET `?stichtag=` (lädt das Soll neu; getippte Ist-Werte gehen verloren – JS fragt per `confirm`, wenn ungespeicherte Zählwerte da sind –, gespeicherte Entwurfswerte bleiben sichtbar). Das versteckte Feld im POST-Formular gilt; JS zeigt nur einen Hinweis bei abweichendem Datum.
  **Formular (Task 12, Du-Form, Texte aus der UX-Freigabe):** „1. Stichtag“ / „2. Zählen“, je Kategorie eine `.table-stack`-Tabelle („Artikel · Laut System · Gezählt · Abweichung“, unter `lg` Karten über `data-label`), Zeilen `.js-zeile` mit
  `data-soll`/`data-preis`/`data-gebinde`. Artikel mit `gebinde_groesse` (Controller liest sie in **einer** Abfrage über `ArtikelModel`) haben `kisten[<id>]` + `einzeln[<id>]` statt `ist[<id>]`; der **Controller** rechnet
  `kisten × gebinde + einzeln` zu `ist[<id>]` um (beide leer → ungezählt; ungültig/negativ/> 999 999 → Feldfehler `ist.<id>` = `MELDUNG_IST` „Bitte eine Zahl ab 0 eintragen.“), Service-API unverändert; Wiederanzeige teilt Stück
  in ⌊n/gebinde⌋ Kisten + Rest (nach Fehler die getippten Rohwerte aus `old()`). Abweichung je Zeile als `badge-status-gruen/rot/amber` mit Icon („stimmt“, „N fehlen“, „N zu viel“), serverseitig vorgerendert und von JS
  identisch nachgerechnet. Feste Aktionsleiste `.auszaehlung-leiste` (`position: sticky; bottom: 0`): „n von m gezählt“, „Abweichung: −4,50 €“ (Σ Abweichung × Preis, nur gezählte und **ohne „neu“-Artikel** (`data-neu`), die auch nicht als „weicht ab“ zählen) bzw. „keine Abweichung“, „Entwurf speichern“
  (zuerst im DOM) und „Abschließen“ (einzige `.btn-vdst`; JS sperrt ihn, bis alles gezählt ist, und setzt `data-confirm` mit Anzahl/Summe dynamisch; ohne JS immer aktiv, der Server prüft). `MELDUNG_IST_FEHLT` =
  „Bitte für jeden Artikel eintragen, wie viel du gezählt hast.“ (Flash `error` beim Abschluss), am Feld `MELDUNG_IST_LEER` „Bitte eintragen.“. JS schreibt bei einer Eingabe nur die geänderte Zeile (Differenzen-Cache, aria-live nur Zeilenzellen + Leiste), > 6 Stellen/> 999 999 = ungültig (`is-invalid`); nach Validierungsfehler `data-ungespeichert="1"` am Formular (Stichtag-Rückfrage).
- Excel-Export (Stufe 2, Task 8): `Libraries/AuszaehlungExport` (Service `auszaehlungExport()`, Konstruktor-Argument = Basisverzeichnis, Standard `WRITEPATH`; Tests nutzen ein Temp-Verzeichnis):
  `erzeuge(auszaehlungId)` → relativer Pfad `exporte/Auszaehlung_<bereich>_<von>_bis_<bis>.xlsx` (Suffix `_<id>`, wenn **eine andere Auszählung** den Namen in `datei_pfad` hat), nur für
  abgeschlossene mit `abgeschlossen_at` (sonst `RuntimeException`), schreibt `datei_pfad` **nicht** (macht Task 9). Zeitraum = (`zeitraum_von`, `stichtag`], inklusiv nur ohne frühere abgeschlossene Auszählung des Bereichs.
  Liest nur gespeicherte Daten (Meta `erstellt_am` = `abgeschlossen_at`) → Neu-Erzeugen liefert identische Zellwerte; Namen/Gruppe/Kategorie aber aus den Stammdaten beim
  Erzeugen (IDs, Mengen, Beträge eingefroren; steht in `Erklaerungen`). `Abrechnung.betrag_eur` kann durch Korrekturen 0 oder negativ sein. Meta `zeitraum_von/bis`, `erstellt_am` sind Excel-Datumszellen.
  Übersicht ordnet Couleur/Bund über `PersonModel::sammelkontoId` zu. Blätter/Spalten/Meta-Schlüssel exakt Spec 8.2 (`format_version` = `getraenkeliste-auszaehlung/1`,
  bei Änderungen erhöhen). Alle Zellen per `setCellValueExplicit` (Text mit „=“ wird nie Formel), Datum als Excel-Seriennummer `yyyy-mm-dd hh:mm`, Beträge = Cent-Summe/100 mit `0.00`,
  Geldsummen in SQL mit `CAST(einzelpreis_cent AS SIGNED)`. Datenblatt = Excel-`Table` (`Tabelle_<Blatt>`); ohne Datenzeilen nur AutoFilter auf der Kopfzeile (Table braucht ≥ 1 Datenzeile).
  `Statistik` mit Säulendiagramm (Writer `setIncludeCharts(true)`); Schreiben in `.…tmp` im Zielordner, dann `rename`.
  `datei(relativ)` → absoluter Pfad nur, wenn die Datei existiert und per `realpath` unter `<basis>/exporte/` liegt (sonst null; Download nutzt nur das).
- Abschluss/Liste (Stufe 2, Task 9): `AuszaehlungService::schliesseAb(bereichId, wartId, stichtag, ist, bemerkung): int` – eine kurze Transaktion (gemeinsam mit
  `speichereEntwurf` über `schreibend()` + `kopf()`): Bereichssperre zuerst, `vergiss()`, Testnaht `nachDerSperre(bereichId)`, Stichtag frisch geprüft, Positionen **neu aus
  `vorschlag()`** (nie aus dem Entwurf), Ist für jeden Artikel Pflicht („Bitte für jeden Artikel einen Ist-Wert eintragen.“, Feldfehler `ist.<id>` = `MELDUNG_IST_FEHLT`), Entwurf wird
  zur abgeschlossenen Auszählung (sonst neu), `erstellt_von_id` = wer abschließt (Liste/Excel zeigen es als „abgeschlossen von“), Protokoll `abgeschlossen`. **Nach** dem Commit
  `vergiss()` und Export in `try/catch(Throwable)` → `datei_pfad`; Fehler → `log_message('error')`, `datei_pfad` bleibt NULL, Controller fragt `dateiFehlt(id)` und setzt zusätzlich
  Flash `error`. `dateiNeuErzeugen(id, ?personId)` (Protokoll `datei_erzeugt`; ändert sich der Pfad, wird die alte Datei nach dem DB-Update gelöscht, nur wenn `AuszaehlungExport::datei()` sie unter `exporte/` findet). Routen: `GET wart/<bereich>/auszaehlungen` (View `wart/auszaehlungen`, neueste zuerst, `AuszaehlungModel::liste`)
  und `GET …/auszaehlungen/(:num)/download` mit `recht:auszaehlung_ansehen@<bereich>`, `POST …/auszaehlungen/(:num)/neu-erzeugen` mit `auszaehlung_durchfuehren`; Entwurf/fremder
  Bereich/unbekannt → 404; fehlende Datei → Redirect zur Liste mit Flash. Download = `response->download($pfad, null, true)->setFileName(basename)`. Rückfragen über
  `data-confirm` an Submit-Knopf oder Formular (`public/js/app.js`, delegiert, keine Inline-Handler; Cache-Buster siehe Task 10).
- Wart-Bereich Überblick (Stufe 2 komplett): alle Routen `wart/<bereich>/…` — `einkauf` (+ `bestand` → 301), `lieferung`, `bewegung`, `buchungen` (+ `storno`, `korrektur`), `auszaehlung` (Entwurf/Abschluss),
  `auszaehlungen` (Liste, `…/(:num)/download`, `…/neu-erzeugen`). Rechte nur über Filter `recht:<aktion>@<bereich>`; Zeiträume (`Zeitraeume`), Einfrieren und Bereichssperre
  gelten für Buchen, Storno, Bewegungen, Korrektur und Abschluss gleich. Exporte (`.xlsx`, `format_version getraenkeliste-auszaehlung/1`) liegen in `writable/exporte/`
  (im Backup enthalten, `datei_pfad` relativ), Download nur aus diesem Verzeichnis (`realpath`-Prüfung). Neue Wart-Routen ⇒ `ZugriffsschutzTest::ROUTEN` ergänzen.
  Trait `Controllers\Concerns\WartEingaben` (in `Auszaehlung-`, `Bewegungen-`, `BuchungenController`): `bereich(schluessel)` (unbekannt/inaktiv → 404) und `text(wert)` –
  POST/GET-Werte immer darüber lesen, Array-Parameter (`bemerkung[]=x`) zählen als leer, nie `(string)`-Cast (sonst 500 „Array to string conversion“).
- Erinnerungsbanner (Stufe 2, Task 10): Partial `layouts/erinnerung.php` (in `layouts/main.php`, nicht in `einfach`/Tablet) ruft `Zeitraeume::erinnerungen(rollen)`:
  je **aktivem** Bereich mit Recht `auszaehlung_durchfuehren` und `tageSeitLetztemAbschluss` (volle Kalendertage ab Stichtag, sonst Inbetriebnahme, via `uhr`) > `erinnerung_tage`
  ein `.alert-warning` (ohne Auto-Dismiss) „Die letzte Auszählung ist <n> Tage her.“ bzw. „Es gab noch keine Auszählung.“ + Link `wart/<bereich>/auszaehlung`.
  Ohne das Recht (Mitglieder) entsteht keine DB-Abfrage. `public/js/app.js`: `data-confirm`: Rückfrage im Klick, Knopfsperre (Spinner) erst im `submit`-Ereignis (`event.submitter`, nur wenn das Absenden weiterläuft, also nach der Browser-Validierung), `pageshow` mit `persisted` gibt gesperrte Knöpfe wieder frei; Cache-Buster `app.js?v=6` (in `layouts/main.php` und `einfach.php`). `data-print` + optional `data-print-bereich="<Selektor>"`: setzt `.druck-auswahl` am `<html>` und `.druck-ziel` am Element (Druck-CSS in app.css druckt nur dieses), `afterprint` räumt auf; im Druck fällt alles außer Ziel/Vorfahren per `display: none` (`:has()`) weg. `app.css?v=7` (`layouts/kopf.php`); `.table-stack` stapelt nur am Bildschirm (`@media screen`), gedruckt bleibt es eine Tabelle.
- Artikelbilder (Stufe 2, Task 11): `Libraries/Artikelbild` (Service `artikelbild()`, Konstruktor-Argument = Basisverzeichnis, Standard `WRITEPATH`, Dateien in
  `<basis>/artikelbilder/`; Tests injizieren ein Temp-Verzeichnis per `Services::injectMock`). `pruefe` (≤ 5 MB „Das Bild ist zu groß (max. 5 MB).“, Typ per `finfo`
  nur JPEG/PNG/WebP, `getimagesize` ≤ 8000 × 8000 und MIME muss passen, sonst „Bitte ein JPG-, PNG- oder WebP-Bild hochladen.“), `schreibe` (GD laden, `memory_limit` bei
  Bedarf auf 512M, auf ≤ 600 px verkleinern (`zielgroesse`, nie vergrößern), JPEG-EXIF-Drehung 3/6/8 anwenden, **immer neu codiert**: JPEG q85, PNG nur bei echter
  Transparenz (Pixel-Scan nach dem Verkleinern), Name `bin2hex(random_bytes(16))`.jpg/.png, Temp-Datei + `rename`), `uebernehme(artikelId, ?datei, personId)` (in einer
  Transaktion: `SELECT … FOR UPDATE`, setzt `bild_datei`, `bild_version + 1`, Protokoll `bild_geaendert`/`bild_entfernt` mit `neu = {bild_version}`, liefert die alte Datei),
  `wechsle(neueDatei, arbeit)` (Artikel-Transaktion; Fehler → neue Datei löschen, alte erst nach dem Commit), `speichere`/`entferne` (Kurzformen), `pfad` (nur Namen
  `[0-9a-f]{32}.(jpg|png)` und vorhandene Datei), statisch `url(artikel)` → `artikelbild/<id>?v=<version>-<erste 8 Zeichen von bild_datei>` (eindeutig auch nach Restore) oder null. Admin-Formular (`StammdatenController`, `multipart/form-data`,
  Feld `bild`, Checkbox `bild_entfernen`, Vorschau): Felder zuerst prüfen, dann Bild schreiben, dann eine Transaktion für Felder + Bild; Bildfehler als Flash `fehler['bild']`;
  neues Bild schlägt „entfernen“. Route `GET artikelbild/(:num)` (`ArtikelbildController`, Filter **`bild`** = `BildFilter`: Session-Person **oder** gültiges, nicht gesperrtes
  Geräte-Cookie über `Geraete::istGueltigesToken` (nur lesend), sonst 403 ohne Redirect); Antwort mit `Cache-Control: private, max-age=31536000, immutable` (vorher
  `removeHeader`, sonst hängt CI an `no-store` an), `Content-Type` ohne charset (`setContentType($mime, '')`), `nosniff`, `inline`; fehlende Datei/kein Bild → 404; archivierte Artikel behalten ihr Bild. `ArtikelModel::buchbar` liefert
  `bild_url`, `buchen/_artikel.php` zeigt `<img class="artikel-bild" … alt="" loading="lazy">` (CSS 4:3, `object-fit: cover`; `app.css?v=4`). Dockerfile: GD mit
  `--with-webp` (`libwebp-dev`) und Erweiterung `exif` – Image-Änderung erst nach `docker compose up -d --build --force-recreate getraenkeliste-web` aktiv.
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
  `recht:<aktion>` (RechtFilter → 403 `errors/keine_berechtigung`), `bild` (BildFilter, Artikelbilder: Anmeldung oder Tablet, sonst 403); `csrf` bleibt global.
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
  `exporte_*.tar.gz` aus `writable/exporte/`, `artikelbilder_*.tar.gz` aus `writable/artikelbilder/` (Task 11), `konfig_*.tar.gz` mit `.env`/Override, `umask 077`, Monats-Promotion, Retention;
  Mount-Prüfung `BACKUP_MOUNT` (leer = aus), dann ist `DB_PASS` Pflicht; alles erst als `*.tmp`, geprüft, dann `mv`, `trap` räumt `*.tmp` weg),
  `scripts/restore.sh` (prüft „Dump completed“ der Quelle, Rückfrage „ja“/`--ja`, ohne TTY nur mit `--ja`, stoppt `WEB_CONTAINER` und startet ihn per `trap` wieder, Sicherheits-Dump
  nach `BACKUP_DIR/vor-restore/`, Export-Archiv nur mit `exporte/`-Pfaden, Bild-Archiv (am Namen `artikelbilder_*.tar.gz` erkannt) nur mit `artikelbilder/`-Pfaden, Konfig nie automatisch), `deploy/systemd/` (Timer 02:30,
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
  gleicht sie mit `service('routes')->getRoutes()` aller Verben ab und wird sonst rot) × anonym/mitglied/getraenkewart/admin/tablet;
  ein Test je Fall (Session-CSRF/Cookie leben nicht über mehrere Requests), `$refresh = false` + `uniqid`-Benutzernamen für Tempo (~20 s statt ~3 min).
  `php spark routes` zeigt für `verschieben/(hoch|runter)` fälschlich `<unknown>`-Filter (Anzeigefehler, die Filter greifen; der Test belegt es).

## Konventionen & Invarianten
- Geheimnisse und Infrastruktur nur in `.env`; `app.baseURL` und `cookie.secure` nur dort. `App::$baseURL` defaultet auf `http://localhost:8090/` (Dev); auf dem Pi muss `.env` `app.baseURL` setzen (Compose-Env erreicht CI nicht).
  `CI_ENVIRONMENT` defaultet in `docker-compose.yml` auf `development`; auf dem Pi muss `.env` `CI_ENVIRONMENT=production` setzen (ohne Leerzeichen, Compose liest die Datei mit; README).
  Compose ignoriert die CI-Zeilen mit Punkten (`app.baseURL = '…'`, `cookie.secure = true`) – mit `docker compose --env-file <tmp> config` geprüft. `cookie.secure = true` nur hinter HTTPS (sonst kein Login).
- **Backups (Task 18):** neue persistente Daten außerhalb der DB gehören in `writable/exporte/` bzw. `writable/artikelbilder/` oder müssen in `scripts/backup.sh` ergänzt werden; neue Geheimnisse nur in `.env` (landen im Konfig-Archiv).
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
- **Rechte:** Berechtigung nur über Filter (`angemeldet`, `recht:<aktion>`, `tablet`, `kein_tablet`, `bild`) in `Routes.php`; neue Route ⇒ `ZugriffsschutzTest` erweitern.

## Bewusste Abweichungen vom Kassensystem (Spec Abschnitt 3)
- Rollen und Mehrbenutzerbetrieb sind der Zweck dieser App.
- Tabelle `einstellungen`, beschränkt auf vom Admin änderbare Betriebswerte.
- Beträge als Cent-INT statt DECIMAL; Komma-Eingabe über `normalisiere_betrag()`.
