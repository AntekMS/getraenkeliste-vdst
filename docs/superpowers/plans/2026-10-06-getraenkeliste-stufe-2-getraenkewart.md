# Getränkeliste VDSt – Stufe 2 (Getränkewart) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Getränkewart pflegt den Bestand (Lieferungen, Schwund/Korrekturen), verwaltet Buchungen (Storno mit Grund, Korrekturbuchung) und schließt Zeiträume per Auszählung ab; jeder Abschluss friert den Zeitraum ein und erzeugt die maschinenlesbare Excel für den Kassenwart.

**Architecture:** Baut auf Stufe 1 auf (Branch `feature/stufe-2-getraenkewart` von `feature/stufe-1-kern`). Rechenlogik in reinen Klassen (`BestandRechner`, `AuszaehlungRechner`, `Lieferumrechnung`), Zeitraum-Logik zentral im Service `zeitraeume()` (letzter Stichtag je Bereich, Einfrieren, Bereichssperre), Excel ausschließlich aus gespeicherten Daten in `AuszaehlungExport`. Wart-Routen liegen unter `wart/<bereich>/…` und werden über `recht:<aktion>@<bereich>` geschützt.

**Tech Stack:** wie Stufe 1, dazu PhpOffice/PhpSpreadsheet ^5 (PHP-Extensions `gd`, `zip` im Image).

**Spec:** `docs/superpowers/specs/2026-10-05-getraenkeliste-design.md` — Abschnitte 6, 6.1, 6.2, 6.3, 7.2 (frühere Zeiträume), 7.3 (ohne „reicht noch“ und Statistikseite), 8.1, 8.2 (nur Auszählung), 9, 10, 12 (Stufe 2).

## Global Constraints

- Alle Constraints, Invarianten und Rulings aus Stufe 1 gelten weiter (`CLAUDE.md` „Konventionen & Invarianten“, insbesondere: Zeit nur über `service('uhr')->jetzt()`, Transaktionen nur über den Trait `Transaktion` (R12), Cookies im Filter-`after()` (R10), Berechtigung nur über Filter in `Routes.php`, jede neue Route in `ZugriffsschutzTest::ROUTEN`, ein `.btn-vdst` pro Seite, `esc()`, deutsche Meldungen, Cent-INT).
- Geldbeträge in Cent (INT); in der Excel als Euro-Zahl mit 2 Nachkommastellen, **berechnet aus Cent-Summen** (erst summieren, dann durch 100 teilen).
- Mengen: Lieferung positiv, Schwund negativ gespeichert, Korrektur ±; Korrekturbuchungen (`quelle = korrektur`) dürfen negative Menge haben, nie 0.
- Spec 6.1: Ein Zeitraum eines Bereichs reicht vom letzten **abgeschlossenen** Stichtag (exklusiv) bis zum nächsten (inklusiv); vorher ab `inbetriebnahme_at` (inklusiv). Alles mit Zeitpunkt ≤ letztem Stichtag des Bereichs ist eingefroren – **serverseitig** abgewiesen.
- Spec 8.1 (alle Datenblätter): Zeile 1 feste `snake_case`-ASCII-Kopfzeile, ab Zeile 2 ein Datensatz je Zeile, keine verbundenen Zellen, keine Formeln, Beträge als Zahl (2 Nachkommastellen), Mengen als Ganzzahl, Daten als echte Excel-Datumswerte (`JJJJ-MM-TT` bzw. `JJJJ-MM-TT hh:mm`), IDs in jedem Datenblatt, formatierte Tabelle mit Filter, Blattnamen ohne Umlaute/Leerzeichen, `format_version = getraenkeliste-auszaehlung/1`.
- Excel nur aus gespeicherten Daten → zweimaliges Erzeugen liefert identische Zellwerte (`erstellt_am` = `abgeschlossen_at`, nicht „jetzt“).
- Exporte liegen in `writable/exporte/` (Backup sichert den Ordner bereits).
- Commits auf Deutsch; Branch `feature/stufe-2-getraenkewart`; am Ende PR gegen `main` (nach Merge von PR #1) bzw. vorher gegen `feature/stufe-1-kern`.

## Entscheidungen über die Spec hinaus

1. **Bereichssperre gegen den Abschluss-Wettlauf:** Buchen, Storno, Bewegungen und Auszählungs-Abschluss sperren in ihrer Transaktion zuerst die betroffene(n) Zeile(n) in `bereiche` (`SELECT … FOR UPDATE`) und prüfen erst danach den Stichtag. So kann nach einem Abschluss nichts mehr mit Zeitpunkt ≤ Stichtag in den eingefrorenen Zeitraum rutschen.
2. **Stichtag-Genauigkeit:** Minute (`JJJJ-MM-TT hh:mm:00`); Standard = aktuelle Minute.
3. **Neue Artikel:** `auszaehlung_positionen.start` (bool) = Artikel hatte keine Position in einer früheren abgeschlossenen Auszählung des Bereichs. Bei `start = 1` (und immer bei `auszaehlungen.art = start`) zählt die Differenz nicht als Schwund.
4. **Korrekturbuchung braucht einen Grund:** neue Spalte `buchungen.bemerkung` (nullable, VARCHAR 255).
5. **Lieferung:** Zeitpunkt = jetzt; Eingabe je Zeile Kisten + Stück (Menge = Kisten × `gebinde_groesse` + Stück), optional Einkaufspreis **je Stück** (Komma-Eingabe). Keine Stornos von Bewegungen – Fehler korrigiert man mit einer Korrektur-Bewegung.
6. **Wart-Storno** ist frei von der Storno-Frist (nur Einfrieren zählt) und braucht einen Grund; wird protokolliert. Korrekturbuchungen werden protokolliert.
7. **Artikelauswahl der Auszählung:** alle Artikel des Bereichs mit `bestand_fuehren = 1`, nicht archiviert **oder** archiviert mit Soll ≠ 0 bzw. Aktivität im Zeitraum.
8. **`einkaufswert_eur`** = Ist × zuletzt bekannter Einkaufspreis (letzte Lieferung mit Preis bis Stichtag), leer wenn unbekannt.
9. **Excel-Diagramme:** Blatt `Statistik` enthält Wertetabellen (Kategorie, Kalenderwoche, Wochentag, Vorzeitraum) und ein Säulendiagramm „Umsatz je Kategorie“; weitere Diagramme folgen mit der Statistikseite (Stufe 3).
10. **App-Version** für `Meta.app_version`: Konstante `GETRAENKELISTE_VERSION` in `app/Config/Constants.php` (`'2.0.0'`).
11. **Dateiname:** `Auszaehlung_<bereich>_<von JJJJ-MM-TT>_bis_<bis JJJJ-MM-TT>.xlsx`; existiert er bereits für eine andere Auszählung, Suffix `_<id>`.
12. Nicht in Stufe 2: Statistikseite, „reicht noch ca. X Tage“, Kassenwart-Bereich, Spenden, Kiosk-Freischaltung (Stufe 3).

## Review Focus

1. **Abschluss parallel zu Buchungen:** Eine Buchung/Storno/Bewegung, die während des Abschlusses läuft, landet nie mit Zeitpunkt ≤ Stichtag in einem schon abgeschlossenen Zeitraum, und das Soll der Auszählung zählt sie entweder ganz oder gar nicht (Bereichssperre). Test in Task 3 und Task 9.
2. **Excel-Erzeugung scheitert:** Die Auszählung bleibt abgeschlossen, `datei_pfad` bleibt NULL, die Liste zeigt „Datei neu erzeugen“, und das Neu-Erzeugen liefert dieselben Werte. Test in Task 9.
3. **Kontrollsummen und Rundung:** `Meta.summe_abrechnung_eur` = Summe der `Abrechnung.betrag_eur` exakt (Cent-Summen), auch bei negativen Korrekturbuchungen und stornierten Buchungen (storniert zählt nicht, steht aber im Blatt `Buchungen` mit `storniert = 1`). Test in Task 8.
4. **Direkt-POST auf eingefrorene Daten:** Wart-Storno, Mitglieds-Storno, Tablet-Rückgängig und Bewegungen mit Zeitpunkt ≤ Stichtag werden serverseitig abgelehnt, auch wenn die Oberfläche den Button nicht zeigt. Test in Task 3 und Task 6.
5. **Entwurf trifft neue Buchungen:** Zwischen Entwurf und Abschluss gebuchte Mengen (vor dem Stichtag) gehen ins Soll ein – das Soll wird beim Abschluss neu berechnet, nicht aus dem Entwurf übernommen; eingetragene Ist-Werte bleiben erhalten. Test in Task 9.

---

## Dateistruktur

```
Dockerfile (gd), composer.json/.lock (phpoffice/phpspreadsheet ^5)
app/Database/Migrations/2026-10-06-000001_Bestand.php        bestandsbewegungen, auszaehlungen, auszaehlung_positionen, buchungen.bemerkung
app/Models/{BestandsbewegungModel,AuszaehlungModel,AuszaehlungPositionModel}.php
app/Libraries/ rein:  BestandRechner, AuszaehlungRechner, Lieferumrechnung
                DB:    Zeitraeume (Service zeitraeume), BestandService (Service bestand), AuszaehlungService (Service auszaehlungen),
                       AuszaehlungExport (Service auszaehlungExport)
app/Controllers/Wart/{BestandController,BewegungenController,BuchungenController,AuszaehlungController}.php
app/Views/wart/{bestand,lieferung,bewegung,buchungen,korrektur,auszaehlung_formular,auszaehlungen}.php, app/Views/layouts/erinnerung.php
public/js/auszaehlung.js
tests/unit/{BestandRechnerTest,AuszaehlungRechnerTest,LieferumrechnungTest}.php
tests/database/{ZeitraeumeTest,BestandServiceTest,AuszaehlungServiceTest,AuszaehlungExportTest}.php
tests/feature/{WartBestandTest,WartBewegungenTest,WartBuchungenTest,WartAuszaehlungTest,MeineBuchungenZeitraumTest}.php
```

---

### Task 1: PhpSpreadsheet, Migration, Models

**Files:**
- Modify: `Dockerfile` (`libpng-dev`, `libjpeg62-turbo-dev`, `libfreetype6-dev` + `docker-php-ext-configure gd --with-freetype --with-jpeg` + `gd` in `docker-php-ext-install`), `composer.json`/`composer.lock` (`phpoffice/phpspreadsheet:^5`), `app/Config/Constants.php` (`GETRAENKELISTE_VERSION = '2.0.0'`), Spec Abschnitt 6 (Spalten `buchungen.bemerkung`, `auszaehlung_positionen.start` ergänzen), `CLAUDE.md`
- Create: `app/Database/Migrations/2026-10-06-000001_Bestand.php`, `app/Models/{BestandsbewegungModel,AuszaehlungModel,AuszaehlungPositionModel}.php`
- Test: `tests/database/MigrationTest.php` (erweitern)

**Interfaces:**
- Tabellen exakt nach Spec Abschnitt 6 plus Entscheidung 3/4: `bestandsbewegungen` (`menge` INT signed, `art` ENUM lieferung/schwund/korrektur, `einkaufspreis_cent` INT NULL, `bemerkung` VARCHAR(255) NULL, `person_id` FK, `erfolgt_at` DATETIME, Index (`artikel_id`,`erfolgt_at`)); `auszaehlungen` (`art` ENUM start/regulaer, `stichtag` DATETIME, `zeitraum_von` DATETIME, `status` ENUM entwurf/abgeschlossen, `datei_pfad` VARCHAR(255) NULL, `bemerkung` TEXT NULL, Index (`bereich_id`,`status`,`stichtag`)); `auszaehlung_positionen` (PK (`auszaehlung_id`,`artikel_id`), alle Mengen INT signed, `ist` INT NULL (im Entwurf leer erlaubt), `start` TINYINT(1), `preis_cent` INT); `buchungen.bemerkung` VARCHAR(255) NULL. Alles mit `created_at`/`updated_at`, FKs `ON DELETE RESTRICT`.
- Produces: `AuszaehlungModel::letzteAbgeschlossene(int $bereichId): ?array`, `entwurf(int $bereichId): ?array`, `abgeschlossene(int $bereichId): array` (neueste zuerst); `AuszaehlungPositionModel::fuer(int $auszaehlungId): array` (nach Kategorie-/Artikel-Sortierung); `BestandsbewegungModel` schlank; alle Models mit Trait `Transaktion`.

- [ ] **Step 1: Failing tests:** `MigrationTest::test_bestandstabellen_angelegt()` (Spalten/Indizes per `getFieldNames`/`getIndexData`), `test_buchungen_haben_bemerkung()`, `test_eine_position_je_artikel_und_auszaehlung()` (PK-Verletzung wirft).
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/database/MigrationTest.php` → FAIL.
- [ ] **Step 3:** Dockerfile + `docker compose up -d --build --force-recreate getraenkeliste-web`, `docker exec getraenkeliste-web composer require phpoffice/phpspreadsheet:^5`, Migration + Models.
- [ ] **Step 4:** Tests → PASS; `php -r "new PhpOffice\PhpSpreadsheet\Spreadsheet();"` im Container läuft; `php spark migrate` gegen die Dev-DB ohne Fehler.
- [ ] **Step 5: Commit** – `"Stufe 2: PhpSpreadsheet, Tabellen für Bestand und Auszählung"`

---

### Task 2: Reine Rechenklassen

**Files:**
- Create: `app/Libraries/{BestandRechner,AuszaehlungRechner,Lieferumrechnung}.php`
- Test: `tests/unit/{BestandRechnerTest,AuszaehlungRechnerTest,LieferumrechnungTest}.php`

**Interfaces (Produces, `final`, statisch):**
- `BestandRechner::bestand(int $letzterIst, int $bewegungenSeither, int $verkauftSeither): int` = Ist + Bewegungen − Verkauft. `ampel(int $bestand, int $mindestbestand): string` → `'negativ'` (< 0), `'leer'` (= 0), `'niedrig'` (< mindestbestand), `'ok'`.
- `AuszaehlungRechner::position(int $anfangsbestand, int $lieferungen, int $schwundErfasst, int $korrekturen, int $verkauft, ?int $ist, int $preisCent, bool $start): array{anfangsbestand, lieferungen, schwund_erfasst, korrekturen, verkauft, soll, ist, differenz, differenz_cent, start}` mit `soll = anfang + lieferungen + schwund_erfasst + korrekturen − verkauft` (`schwund_erfasst` ist bereits negativ), `differenz = ist − soll` (NULL wenn `ist` NULL), `differenz_cent = differenz × preis`.
  `schwundCent(array $positionen, string $art): int` = Summe `differenz_cent` aller Positionen mit `start = false` und Differenz < 0, bei `$art === 'start'` immer 0 (positiv gemeldet als Betrag ≥ 0).
  `pruefeStichtag(DateTimeImmutable $stichtag, ?DateTimeImmutable $letzterStichtag, DateTimeImmutable $jetzt): ?string` → `"Der Stichtag muss nach dem letzten Abschluss liegen."` / `"Der Stichtag darf nicht in der Zukunft liegen."` / null.
- `Lieferumrechnung::menge(int $kisten, int $stueck, ?int $gebinde): int` – wirft `InvalidArgumentException` bei negativen Werten oder Kisten > 0 ohne Gebinde.

- [ ] **Step 1: Failing tests** (Datenanbieter): Bestand 10 + 24 − 5 = 29; Ampel-Grenzen; Position mit Schwund (Soll 20, Ist 18 → −2, −300 Cent bei 150), Überschuss (Ist > Soll), `ist = null`, Start-Position (Differenz bleibt sichtbar, `schwundCent` ignoriert sie), `art = start` → `schwundCent = 0`; Stichtag gleich letztem → Fehler, eine Minute danach ok, Zukunft +1 min → Fehler; Lieferung 2 Kisten × 20 + 3 = 43, Kisten ohne Gebinde → Exception.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Rechenklassen für Bestand, Auszählung und Lieferung"`

---

### Task 3: Zeiträume je Bereich, Einfrieren und Bereichssperre

**Files:**
- Create: `app/Libraries/Zeitraeume.php` (Service `zeitraeume()`)
- Modify: `app/Libraries/BuchungService.php`, `app/Models/BereichModel.php`, `app/Controllers/MeineBuchungenController.php`, `app/Models/BuchungModel.php`, `app/Views/meine_buchungen/index.php`, `app/Config/Services.php`
- Test: `tests/database/ZeitraeumeTest.php`, `tests/database/BuchungServiceTest.php` (erweitern), `tests/feature/MeineBuchungenZeitraumTest.php`

**Interfaces:**
- Produces: `Zeitraeume::letzterStichtag(int $bereichId): ?DateTimeImmutable` (letzte **abgeschlossene** Auszählung), `beginn(int $bereichId): DateTimeImmutable` (letzter Stichtag oder `inbetriebnahme`), `beginnInklusiv(int $bereichId): bool` (true nur ohne Auszählung), `istEingefroren(DateTimeImmutable $zeitpunkt, int $bereichId): bool`, `fruehere(int $bereichId): array` (Liste `[von, bis, auszaehlung_id]` neueste zuerst). Je Request gecacht; `vergiss()` leert den Cache (nach Abschluss).
- `BereichModel::sperre(array $bereichIds): void` – `SELECT id FROM bereiche WHERE id IN (…) ORDER BY id FOR UPDATE` (nur innerhalb einer Transaktion aufrufen, sortiert gegen Deadlocks).
- `BuchungService`: in `bucheVorgang` (nach Replay-Prüfung, innerhalb der Transaktion) Bereiche der Artikel sperren; in `storniereVorgang/storniereBuchung` Bereich sperren und `istEingefroren(gebucht_at, bereich)` prüfen → `BuchungAbgelehnt("Dieser Zeitraum ist abgeschlossen.")` (vor der Fristprüfung).
- `BuchungModel::offenerBetrag(int $kontoId, string $bereichSchluessel, DateTimeImmutable $ab, bool $abInklusiv)` und `fuerKonto(...)` respektieren den Zeitraum je Bereich; neu `summeImZeitraum(int $kontoId, int $bereichId, DateTimeImmutable $von, DateTimeImmutable $bis): int` (von exklusiv, bis inklusiv).
- Meine Buchungen: laufender Zeitraum je Bereich ab `Zeitraeume::beginn`; darunter „Frühere Zeiträume“ je Bereich: `von – bis` und eigene Summe (keine Einzelbuchungen).

- [ ] **Step 1: Failing tests.** `ZeitraeumeTest`: ohne Auszählung Beginn = Inbetriebnahme (inklusiv); nach einer abgeschlossenen Auszählung Beginn = Stichtag (exklusiv), Entwurf zählt nicht; `istEingefroren` an der Grenzsekunde; zwei Bereiche unabhängig. `BuchungServiceTest`: `test_storno_im_eingefrorenen_zeitraum_abgelehnt()` (auch innerhalb der Storno-Frist; Review Focus 4), `test_rueckgaengig_nach_abschluss_abgelehnt()`, `test_buchung_sperrt_bereich()` (zweite DB-Verbindung `db_connect('tests', false)` mit `innodb_lock_wait_timeout = 1` versucht `SELECT … FOR UPDATE` auf den Bereich, während ein Test-Hook `vorDemSchreiben` läuft → Lock-Timeout-Exception; Review Focus 1). `MeineBuchungenZeitraumTest`: offener Betrag zählt nur nach dem Stichtag; frühere Zeiträume zeigen die eigene Summe; Storno-Button fehlt bei eingefrorenen Buchungen.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS (+ bestehende Buchungs-/MeineBuchungen-Tests grün).
- [ ] **Step 5: Commit** – `"Zeiträume je Bereich, Einfrieren und Bereichssperre"`

---

### Task 4: Wart-Bereich: Routen, Recht je Bereich, Navigation, Bestandsseite

**Files:**
- Create: `app/Libraries/BestandService.php` (Service `bestand()`), `app/Controllers/Wart/BestandController.php`, `app/Views/wart/bestand.php`
- Modify: `app/Filters/RechtFilter.php`, `app/Config/Routes.php`, `app/Views/layouts/main.php`, `tests/feature/ZugriffsschutzTest.php`, `public/css/app.css` (Ampel-Badges über vorhandene `badge-status-*`-Klassen wenn möglich)
- Test: `tests/feature/RechtFilterTest.php` (erweitern), `tests/database/BestandServiceTest.php`, `tests/feature/WartBestandTest.php`

**Interfaces:**
- `RechtFilter`: Argument `aktion@bereich` → `Berechtigung::darf($rollen, $aktion, $bereich)`; Argument ohne `@` wie bisher. Routen in `Routes.php` als Schleife über `['getraenke','kiosk']`: Gruppe `wart/<bereich>` mit `angemeldet` und je Route `recht:<aktion>@<bereich>`. Controller laden den Bereich über den Schlüssel und liefern **404**, wenn er inaktiv ist (Kiosk bis Stufe 3).
- `BestandService::fuerBereich(int $bereichId): array` – je Kategorie (Sortierung) die Artikel mit `bestand_fuehren = 1` (nicht archiviert): `artikel_id, name, einheit, mindestbestand, bestand, ampel`; Grundlage: Ist der letzten abgeschlossenen Position (sonst 0) + Bewegungen nach Stichtag − nicht stornierte Buchungsmengen nach Stichtag (inkl. Korrekturbuchungen). `einzeln(int $artikelId): int`.
- `GET wart/<bereich>/bestand` (`recht:bestand_pflegen@<bereich>`): Tabelle je Kategorie, Ampel, Hinweis-Banner bei negativem Bestand, Links zu Lieferung/Schwund (Task 5) – die eine `.btn-vdst` ist „Lieferung erfassen“.
- Navigation: Gruppe „Getränkewart“ (Bestand, Lieferung, Buchungen, Auszählung) nur bei `darf(…, 'bestand_pflegen', 'getraenke')`.

- [ ] **Step 1: Failing tests.** `RechtFilterTest`: `recht:bestand_pflegen@getraenke` lässt Getränkewart durch, Kioskwart/Mitglied 403, Admin durch; unbekannter Bereich 403. `BestandServiceTest`: ohne Auszählung = Bewegungen − Verkäufe; nach Auszählung = Ist + spätere; stornierte Buchungen zählen nicht; Korrekturbuchung −2 erhöht den Bestand um 2; Ampel. `WartBestandTest`: Getränkewart sieht Bestand, Mitglied 403, `wart/kiosk/bestand` → 404 auch für Admin; negativer Bestand → Warnhinweis.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren, `ZugriffsschutzTest::ROUTEN` um alle neuen Routen und einen Akteur „getraenkewart“ erweitern (Erwartung je Route dokumentieren). **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Wart-Bereich mit Recht je Bereich und Bestandsseite"`

---

### Task 5: Lieferung, Schwund, Korrektur

**Files:**
- Create: `app/Controllers/Wart/BewegungenController.php`, `app/Views/wart/{lieferung,bewegung}.php`, `public/js/lieferung.js` (Zeile hinzufügen, keine Inline-Handler)
- Modify: `app/Libraries/BestandService.php`, `app/Config/Routes.php`, `tests/feature/ZugriffsschutzTest.php`
- Test: `tests/feature/WartBewegungenTest.php`

**Interfaces:**
- `BestandService::liefere(int $bereichId, int $personId, array $zeilen, ?string $bemerkung): int` (Anzahl Bewegungen) – `$zeilen = [['artikel_id','kisten','stueck','einkaufspreis'(String|null)]]`; leere Zeilen (0/0) übersprungen; mindestens eine Zeile; Artikel muss zum Bereich gehören, nicht archiviert, `bestand_fuehren = 1`; Menge über `Lieferumrechnung`, Preis über `betrag_in_cent`. **Eine Transaktion** mit Bereichssperre; Protokoll je Bewegung (`lieferung`, tabelle `bestandsbewegungen`, neu = Zeile).
- `BestandService::bucheBewegung(int $bereichId, int $personId, int $artikelId, string $art /* schwund|korrektur */, int $menge, string $bemerkung): int` – Bemerkung Pflicht (`"Bitte eine Bemerkung angeben."`), Menge ≠ 0; Schwund wird mit positiver Eingabe als negative Menge gespeichert; Korrektur ±. Transaktion + Bereichssperre + Protokoll.
- Routen: `GET/POST wart/<bereich>/lieferung`, `GET/POST wart/<bereich>/bewegung` (`recht:bestand_pflegen@<bereich>`); nach Erfolg Redirect auf Bestand mit Flash.

- [ ] **Step 1: Failing tests:** Lieferung mit 2 Kisten + 3 Stück und EK `0,85` → Bewegung +43, 85 Cent, Bestand steigt; Kisten bei Artikel ohne Gebinde → Feldfehler, nichts gespeichert (alles-oder-nichts bei mehreren Zeilen); Artikel aus anderem Bereich/archiviert → abgelehnt; Schwund 3 → −3 mit Bemerkung, ohne Bemerkung abgelehnt; Korrektur −0 abgelehnt; Protokoll-Einträge vorhanden; Mitglied 403.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Lieferung, Schwund und Korrektur erfassen"`

---

### Task 6: Buchungsverwaltung: Liste, Storno mit Grund, Korrekturbuchung

**Files:**
- Create: `app/Controllers/Wart/BuchungenController.php`, `app/Views/wart/{buchungen,korrektur}.php`
- Modify: `app/Libraries/BuchungService.php`, `app/Models/BuchungModel.php`, `app/Config/Routes.php`, `tests/feature/ZugriffsschutzTest.php`
- Test: `tests/feature/WartBuchungenTest.php`, `tests/database/BuchungServiceTest.php` (erweitern)

**Interfaces:**
- `BuchungModel::imZeitraum(int $bereichId, DateTimeImmutable $von, bool $vonInklusiv, array $filter): BuchungModel` (Query-Builder für `paginate(50)`; Filter `person` int, `artikel` int, `tag` `JJJJ-MM-TT`; nicht-skalare Parameter ignorieren wie im Protokoll).
- `BuchungService::storniereAlsWart(int $buchungId, int $wartId, string $grund): void` – Grund Pflicht (`"Bitte einen Grund angeben."`), keine Frist, aber Einfrieren + Bereichssperre; setzt `storno_grund`; Protokoll `storniert`.
- `BuchungService::bucheKorrektur(int $kontoId, int $artikelId, int $menge, string $bemerkung, int $wartId): array` – `quelle = korrektur`, `gebucht_von_id = wart`, neue `vorgang_id`, aktueller Preis, Menge ≠ 0 (auch negativ, |Menge| ≤ 99), Bemerkung Pflicht, Konto aktiv (Mitglied oder Sammelkonto), Artikel des Bereichs (archivierte erlaubt – Korrekturen alter Fehler); Transaktion + Bereichssperre; Protokoll `korrektur`.
- Routen: `GET wart/<bereich>/buchungen`, `POST wart/<bereich>/buchungen/(:num)/storno`, `GET/POST wart/<bereich>/korrektur` (`recht:buchungen_verwalten@<bereich>`). Liste zeigt gebucht am, Konto, gebucht von, Artikel, Menge, Summe, Quelle, Status; Storno-Formular mit Grund je Zeile (nur nicht eingefroren & nicht storniert).

- [ ] **Step 1: Failing tests:** Liste filtert nach Person/Artikel/Tag; Wart-Storno nach Ablauf der Mitglieds-Frist möglich, ohne Grund abgelehnt, im eingefrorenen Zeitraum abgelehnt (Review Focus 4, direkter POST); Korrekturbuchung −2 auf Couleur mit Bemerkung → Zeile mit `quelle = korrektur`, `bemerkung`, offener Betrag sinkt; Menge 0 abgelehnt; Protokoll für Storno und Korrektur; Kioskwart/Mitglied 403.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Buchungsverwaltung: Storno mit Grund und Korrekturbuchung"`

---

### Task 7: Auszählung – Entwurf

**Files:**
- Create: `app/Libraries/AuszaehlungService.php` (Service `auszaehlungen()`), `app/Controllers/Wart/AuszaehlungController.php`, `app/Views/wart/auszaehlung_formular.php`, `public/js/auszaehlung.js`
- Modify: `app/Config/Routes.php`, `tests/feature/ZugriffsschutzTest.php`
- Test: `tests/database/AuszaehlungServiceTest.php`, `tests/feature/WartAuszaehlungTest.php`

**Interfaces:**
- `AuszaehlungService::vorschlag(int $bereichId, DateTimeImmutable $stichtag): array` – je Artikel (Entscheidung 7) die Werte aus `AuszaehlungRechner::position` mit `ist = null`, berechnet für den Zeitraum (Beginn exklusiv/inklusiv, bis Stichtag inklusiv); `start` nach Entscheidung 3; `preis_cent` = aktueller Verkaufspreis.
- `AuszaehlungService::speichereEntwurf(int $bereichId, int $wartId, DateTimeImmutable $stichtag, array $ist /* artikel_id => ?int */, ?string $bemerkung): int` – höchstens ein Entwurf je Bereich (vorhandenen aktualisieren), Stichtag über `AuszaehlungRechner::pruefeStichtag`, Ist ≥ 0 oder leer; speichert Kopf + Positionen (Soll-Werte als Momentaufnahme), `art` = `start` falls keine abgeschlossene Auszählung existiert.
- `GET wart/<bereich>/auszaehlung` (Formular mit Stichtag `datetime-local`, Standard aktuelle Minute bzw. Entwurfswert; Tabelle Artikel | Soll | Ist | Differenz; Differenz live per `auszaehlung.js` aus `data-soll`), `POST wart/<bereich>/auszaehlung` mit Aktion `entwurf` (Abschluss in Task 9). Stichtag-Änderung im Formular lädt das Soll neu (GET mit `?stichtag=`). Recht `auszaehlung_durchfuehren@<bereich>`.

- [ ] **Step 1: Failing tests:** Vorschlag berechnet Soll aus Anfangsbestand, Lieferungen, Schwund, Korrekturen und Verkäufen bis zum Stichtag (Buchung nach dem Stichtag zählt nicht); neuer Artikel → `start = true`; zweiter Entwurf aktualisiert den ersten (genau ein Entwurf); Stichtag in der Zukunft/vor letztem Abschluss → Fehler; Ist negativ → Fehler; Formular zeigt Soll und eine `.btn-vdst`.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Auszählung: Vorschlag und Entwurf"`

---

### Task 8: Excel-Export der Auszählung

**Files:**
- Create: `app/Libraries/AuszaehlungExport.php` (Service `auszaehlungExport()`)
- Test: `tests/database/AuszaehlungExportTest.php`

**Interfaces:**
- `AuszaehlungExport::erzeuge(int $auszaehlungId): string` – liest **nur** gespeicherte Daten (Auszählung, Positionen, Buchungen des Zeitraums inkl. stornierter, Bewegungen des Zeitraums, Personen, Artikel/Kategorien, vorherige Auszählung für den Vergleich) und schreibt `writable/exporte/<Dateiname nach Entscheidung 11>`; gibt den relativen Pfad (`exporte/…`) zurück. Wirft bei Fehlern (kein stilles Teilergebnis: erst in Temp-Datei, dann `rename`).
- Blätter und Spalten **exakt** nach Spec 8.2 (Reihenfolge `Uebersicht`, `Abrechnung`, `Positionen`, `Buchungen`, `Bestand`, `Bewegungen`, `Statistik`, `Erklaerungen`, `Meta`). Datenblätter (`Abrechnung`, `Positionen`, `Buchungen`, `Bestand`, `Bewegungen`, `Erklaerungen`, `Meta`) erfüllen 8.1 vollständig (Excel-Tabelle mit Filter über `Table`/AutoFilter, Datumszellen als Excel-Datum mit Format `yyyy-mm-dd hh:mm`, Beträge mit Format `0.00`). `Abrechnung`: eine Zeile je Konto mit nicht stornierten Buchungen im Zeitraum, `betrag_eur` = Cent-Summe/100, `konto_typ` = `mitglied|sammelkonto`. `Buchungen.storniert` = 0/1, `gebucht_von` = Anzeigename oder leer. `Bestand` aus den Positionen (`differenz_eur`, `verkaufspreis_eur`, `einkaufswert_eur` nach Entscheidung 8). `Erklaerungen` enthält für **jede** Spalte jedes Datenblatts eine Zeile. `Meta`-Schlüssel exakt nach Spec, `erstellt_am` = `abgeschlossen_at`, `app_version` = `GETRAENKELISTE_VERSION`.
- `Uebersicht` (für Menschen, darf frei gestaltet sein): Zeitraum, Umsatz, Summen Mitglieder/Couleur/Bund, Schwund in € (`AuszaehlungRechner::schwundCent`), Top-5-Artikel, Hinweise (negative Ist-/Soll-Werte, Positionen ohne Ist).
- `Statistik` nach Entscheidung 9.

- [ ] **Step 1: Failing tests** (Datei erzeugen, mit `IOFactory::load` einlesen): Blattnamen und Reihenfolge; Kopfzeilen jedes Datenblatts **exakt** wie Spec 8.2 (als Konstanten-Array im Test, nicht aus der Klasse gelesen); keine Formelzelle (`getDataType() !== 'f'` über alle Zellen), keine verbundenen Zellen; `Meta`-Werte; `summe_abrechnung_eur` = Summe `Abrechnung.betrag_eur` bei Beträgen 1,50 × 3 und Korrektur −1,50 (Review Focus 3); stornierte Buchung steht in `Buchungen` mit 1, fehlt in `Abrechnung`; Datumszelle ist numerisch (Excel-Datum); zweites Erzeugen → identische Zellwerte; jede Datenspalte hat eine Erklärung.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Excel-Export der Auszählung (format_version getraenkeliste-auszaehlung/1)"`

---

### Task 9: Auszählung abschließen, Liste, Download, Datei neu erzeugen

**Files:**
- Create: `app/Views/wart/auszaehlungen.php`
- Modify: `app/Libraries/AuszaehlungService.php`, `app/Controllers/Wart/AuszaehlungController.php`, `app/Config/Routes.php`, `tests/feature/ZugriffsschutzTest.php`
- Test: `tests/database/AuszaehlungServiceTest.php`, `tests/feature/WartAuszaehlungTest.php` (erweitern)

**Interfaces:**
- `AuszaehlungService::schliesseAb(int $bereichId, int $wartId, DateTimeImmutable $stichtag, array $ist, ?string $bemerkung): int` – **eine Transaktion**: Bereich sperren, Stichtag erneut prüfen, Ist für **jeden** Artikel Pflicht (`"Bitte für jeden Artikel einen Ist-Wert eintragen."`), Positionen **neu berechnen** (nicht aus dem Entwurf übernehmen; Review Focus 5), Entwurf wird zur abgeschlossenen Auszählung (bzw. neu angelegt), `zeitraum_von` = vorheriger Beginn, `status = abgeschlossen`, `abgeschlossen_at = jetzt`, Protokoll `abgeschlossen`. **Nach** dem Commit: `zeitraeume()->vergiss()`, dann `auszaehlungExport()->erzeuge()` in try/catch → Pfad speichern; Fehler → `datei_pfad` bleibt NULL, Rückgabe enthält Hinweis (Review Focus 2).
- `AuszaehlungService::dateiNeuErzeugen(int $auszaehlungId): string`.
- Routen: `POST wart/<bereich>/auszaehlung` Aktion `abschliessen` (JS-Bestätigung über `data-confirm` in `app.js`, keine Inline-Handler), `GET wart/<bereich>/auszaehlungen` (Liste: Zeitraum, Art, abgeschlossen am/von, Download oder „Datei neu erzeugen“) mit `recht:auszaehlung_ansehen@<bereich>`, `GET wart/<bereich>/auszaehlungen/(:num)/download` (`auszaehlung_ansehen`; nur Dateien unter `writable/exporte/`, Pfad aus der DB, nie aus dem Request), `POST wart/<bereich>/auszaehlungen/(:num)/neu-erzeugen` (`auszaehlung_durchfuehren`).

- [ ] **Step 1: Failing tests:** Abschluss friert ein (danach Mitglieds-Storno einer Buchung vor dem Stichtag abgelehnt), Soll wird beim Abschluss neu berechnet (Buchung zwischen Entwurf und Abschluss vor dem Stichtag ist enthalten, Review Focus 5), Ist-Werte bleiben; zweite Auszählung nur mit späterem Stichtag; `art = start` bei der ersten, `regulaer` danach; Excel-Fehler (Export-Service per `injectMock` wirft) → Auszählung abgeschlossen, `datei_pfad` NULL, Liste zeigt „Datei neu erzeugen“, danach Datei vorhanden (Review Focus 2); Download liefert `.xlsx` mit richtigem Dateinamen; Abschluss sperrt den Bereich (gleiches Verfahren wie Task 3, Review Focus 1); Mitglied/Kioskwart 403.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Auszählung abschließen, Liste, Download und Datei neu erzeugen"`

---

### Task 10: Erinnerung, Doku, Abschluss

**Files:**
- Create: `app/Views/layouts/erinnerung.php`
- Modify: `app/Views/layouts/main.php`, `README.md`, `CLAUDE.md`, `docs/DEPLOY-PI.md` (Abschnitt „Erste Start-Auszählung“), Spec Abschnitt 11 Schritt 4 unverändert prüfen
- Test: `tests/feature/WartErinnerungTest.php`, `tests/feature/ZugriffsschutzTest.php` (Vollständigkeit)

**Interfaces:**
- Banner „Die letzte Auszählung ist <n> Tage her.“ (bzw. „Es gab noch keine Auszählung.“ seit Inbetriebnahme) mit Link zur Auszählung, für Personen mit `auszaehlung_durchfuehren` im jeweiligen aktiven Bereich, wenn der letzte Stichtag (sonst Inbetriebnahme) länger als `erinnerung_tage` zurückliegt; als `.alert` (kein Auto-Dismiss).

- [ ] **Step 1: Failing tests:** Banner erscheint für den Getränkewart nach 32 Tagen (`uhrStellen`), nicht für Mitglieder, nicht nach frischer Auszählung.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** gesamte Suite grün (allein laufen lassen).
- [ ] **Step 5: Doku:** CLAUDE.md-Landkarte (Wart-Bereich, Zeiträume/Einfrieren/Bereichssperre, Export-Regeln), README (Stufe 2 Funktionen, erste Start-Auszählung als Inbetriebnahme-Schritt 4), DEPLOY-PI.md.
- [ ] **Step 6: Commit** – `"Erinnerung an Auszählung, Doku Stufe 2"`
