# Statistik für den Getränkewart – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zwei Wart-Seiten „Einkauf“ (Bestellliste, Bestand mit Reichweite, Anteil Couleur/Bund, Verlauf, Lieferhistorie) und „Schwund“ (je Auszählungszeitraum) mit belastbaren, getesteten Zahlen.

**Architecture:** Reine Rechenklasse `StatistikRechner` + Service `statistik()` mit Aggregatabfragen; Bestand ausschließlich über `bestand()`/`BestandService::aggregat`. Seiten serverseitig gerendert, Diagramme per Chart.js aus `data-*`-Werten, Tabellen funktionieren ohne JavaScript.

**Tech Stack:** wie Stufe 2; dazu Chart.js 4 per CDN (`cdn.jsdelivr.net`), nur auf den zwei Seiten.

**Spec:** `docs/superpowers/specs/2026-10-07-statistik-getraenkewart-design.md` (freigegeben). Haupt-Spec: `docs/superpowers/specs/2026-10-05-getraenkeliste-design.md`.

## Global Constraints

- Branch `feature/statistik-getraenkewart`, gestapelt auf `feature/stufe-2-getraenkewart` (nach dessen Task 11). Commits auf Deutsch.
- Alle Invarianten aus `CLAUDE.md` „Konventionen & Invarianten“ gelten (Zeit nur über `service('uhr')->jetzt()`, Rechte nur über Filter + `ZugriffsschutzTest::ROUTEN`, ein `.btn-vdst` pro Seite, `esc()`, keine Inline-Styles/-Handler, deutsche Texte, Cent-INT).
- Geldsummen in SQL immer `menge * CAST(einzelpreis_cent AS SIGNED)`; Anzeige über `formatiere_cent`.
- Verkauft = Summe `menge` **nicht stornierter** Buchungen **inkl.** `quelle = korrektur`.
- Fenster „letzte 28 Tage“ = (jetzt − 28 Tage, jetzt]; Grundlage-Tage = min(28, Tage seit `inbetriebnahme_at`), mindestens 1.
- Vorschlag = ⌈Tagesverbrauch × `reichweite_tage` + `mindestbestand` − Bestand⌉; ≤ 0 → kein Vorschlag; mit `gebinde_groesse` auf volle Kisten aufrunden.
- Unerklärte Differenz = Σ |`differenz_cent`| der Positionen mit `differenz < 0` und `start = 0`; bei `auszaehlungen.art = start` 0; Überschüsse getrennt.
- Wochen = ISO-Kalenderwochen (Mo–So) in Europe/Berlin; Datumsarithmetik über `DateTimeImmutable::modify`, nie Sekunden-Differenzen (Sommerzeit).
- Rechte: `angemeldet` + `recht:statistik_ansehen@<bereich>`; inaktiver Bereich → 404.

## Review Focus

1. **Neue Installation ohne Daten:** < 28 Tage seit Inbetriebnahme, keine Buchungen, keine Auszählung → beide Seiten rendern ohne Warnung/Division durch 0, zeigen „—“ bzw. den leeren Zustand und den Hinweis „Grundlage erst X Tage“. Tests in Task 1, 4, 5.
2. **Manipulierte Parameter:** `ansicht=artikel:<id eines Kiosk-Artikels>` oder `artikel=<fremde id>` über die Getränke-URL, Array-Parameter, `ansicht=kategorie:abc` → werden ignoriert (Standardansicht), nie Daten des anderen Bereichs, nie 500. Tests in Task 4 und 5.
3. **Sonderzeichen in Namen im Diagramm:** Artikel-/Kategorienamen mit `"`, `<`, `'` landen JSON-codiert und attribut-escapt in `data-*`; kein HTML-/Attribut-Ausbruch. Test in Task 6.
4. **Wochen über Jahreswechsel und Sommerzeit:** KW 52/53 → KW 1 und die Zeitumstellungswoche werden korrekt gezählt, keine doppelte/fehlende Woche. Tests in Task 1 und 2.
5. **Schwund mit Start-Positionen und Überschuss:** Neue Artikel (`start = 1`) und Überschüsse verfälschen weder unerklärte Differenz noch Quote. Test in Task 3.

---

## Dateistruktur

```
app/Database/Migrations/2026-10-07-000002_Reichweite.php      einstellungen-Zeile reichweite_tage = 30 (INSERT IGNORE)
app/Libraries/StatistikRechner.php                           rein, statisch
app/Libraries/StatistikService.php                           Service statistik()
app/Controllers/Wart/{EinkaufController,SchwundController}.php
app/Views/wart/{einkauf,schwund}.php                         (wart/bestand.php entfällt)
public/js/statistik.js
tests/unit/StatistikRechnerTest.php, tests/database/StatistikServiceTest.php, tests/feature/{WartEinkaufTest,WartSchwundTest}.php
```

---

### Task 1: StatistikRechner und Einstellung `reichweite_tage`

**Files:**
- Create: `app/Libraries/StatistikRechner.php`, `app/Database/Migrations/2026-10-07-000002_Reichweite.php`, `tests/unit/StatistikRechnerTest.php`
- Modify: `app/Libraries/EinstellungDefinition.php` (`reichweite_tage`: Label „Bestellreichweite (Tage)“, int, 7–90, Default `30`, änderbar), `tests/database/MigrationTest.php`, `tests/unit/EinstellungDefinitionTest.php`

**Interfaces (Produces, `final`, statisch):**
- `grundlageTage(DateTimeImmutable $inbetriebnahme, DateTimeImmutable $jetzt): int` = min(28, volle Kalendertage dazwischen), mindestens 1.
- `tagesverbrauch(int $verkauft, int $grundlageTage): float`.
- `reichweiteTage(int $bestand, float $tagesverbrauch): ?int` – null bei Verbrauch 0; 0 bei Bestand ≤ 0; sonst ⌊Bestand ÷ Verbrauch⌋.
- `vorschlag(float $tagesverbrauch, int $reichweiteTage, int $mindestbestand, int $bestand, ?int $gebinde): ?array{stueck:int, kisten:?int}` – null bei ≤ 0; mit Gebinde: `kisten` = ⌈Bedarf ÷ Gebinde⌉, `stueck` = kisten × Gebinde; ohne: `kisten` null, `stueck` = ⌈Bedarf⌉.
- `anteile(array $werte /* ['mitglieder'=>int,'couleur'=>int,'bund'=>int] */): array` – Prozent mit einer Nachkommastelle je Schlüssel, alle null bei Summe 0.
- `quote(int $fehlmenge, int $verkauft): ?float` – Prozent, eine Nachkommastelle, null bei verkauft 0.
- `isoWoche(DateTimeImmutable $tag): string` → `"2026-W53"`-Format (`o-\WW`); `letzteWochen(DateTimeImmutable $jetzt, int $anzahl): list<string>` (älteste zuerst, inkl. laufender Woche).

- [ ] **Step 1: Failing tests:** grundlageTage 5 Tage nach Inbetriebnahme → 5, 40 Tage → 28, gleicher Tag → 1; Reichweite: (29, 2.0) → 14, (0, 2.0) → 0, (5, 0.0) → null; Vorschlag: Verbrauch 2.0, Reichweite 30, Mindest 10, Bestand 25, Gebinde 20 → Bedarf 45 → 3 Kisten / 60 Stück; ohne Gebinde → 45 Stück; Bestand 100 → null; Anteile [60,30,10] → 60.0/30.0/10.0, [0,0,0] → nulls; Quote (3, 200) → 1.5, (3, 0) → null; isoWoche 2026-12-31 → `2026-W53`, 2027-01-04 → `2027-W01`; letzteWochen über Jahreswechsel liefert 12 eindeutige aufeinanderfolgende Wochen; Woche der Zeitumstellung (2026-10-25) wird genau einmal gezählt. EinstellungDefinition: 6 → Fehler, 7/90 ok, 91 Fehler. MigrationTest: Zeile `reichweite_tage` = `30` existiert.
- [ ] **Step 2:** `docker exec getraenkeliste-web vendor/bin/phpunit tests/unit/StatistikRechnerTest.php` → FAIL.
- [ ] **Step 3:** implementieren (Migration: `INSERT IGNORE INTO einstellungen …`, `down` löscht die Zeile).
- [ ] **Step 4:** PASS (Unit + MigrationTest + EinstellungDefinitionTest).
- [ ] **Step 5: Commit** – `"Statistik: Rechenklasse und Einstellung Bestellreichweite"`

---

### Task 2: StatistikService – Einkauf-Daten

**Files:**
- Create: `app/Libraries/StatistikService.php` (Service `statistik()`), `tests/database/StatistikServiceTest.php`
- Modify: `app/Config/Services.php`, `CLAUDE.md`

**Interfaces:**
- Consumes: `bestand()->fuerBereich/einzeln/aggregat`, `zeitraeume()`, `einstellungen()`, `StatistikRechner`.
- Produces:
  - `einkauf(int $bereichId): array{grundlage_tage:int, reichweite_tage:int, kategorien: list<array{kategorie_id, kategorie_name, artikel: list<array{artikel_id, name, einheit, mindestbestand, gebinde_groesse, bestand, ampel, tagesverbrauch:float, reichweite:?int, vorschlag:?array, letzter_ek_cent:?int}>}>}` – Bestand/Ampel unverändert aus `bestand()->fuerBereich`, Verkauf der letzten 28 Tage über **eine** Aggregatabfrage.
  - `anteile(int $bereichId, DateTimeImmutable $von, bool $vonInklusiv, DateTimeImmutable $bis): array{menge: array{mitglieder:int,couleur:int,bund:int}, cent: array{…}}` (Couleur/Bund über `PersonModel::sammelkontoId`).
  - `wochenverbrauch(int $bereichId, string $ansicht /* alle|kategorie:<id>|artikel:<id> */, int $wochen = 12): array{wochen: list<string>, reihen: list<array{name:string, werte: list<int>}>}` – Ansicht wird gegen den Bereich validiert, ungültig → `alle`; `alle` = je Kategorie eine Reihe.
  - `lieferhistorie(int $bereichId, int $anzahl = 20): list<array{erfolgt_at:string, erfasst_von:string, zeilen: list<array{artikel:string, menge:int, einkaufspreis_cent:?int}>}>` (gruppiert nach `erfolgt_at` + `person_id`).

- [ ] **Step 1: Failing tests:** Verbrauch 28 Tage zählt eine Buchung vor 27 Tagen, nicht vor 29 Tagen, keine stornierte, eine Korrektur −2 mindernd; Vorschlag im Ergebnis entspricht `StatistikRechner::vorschlag` mit diesen Werten; **Gleichheit:** `bestand` jedes Artikels in `einkauf()` === `bestand()->einzeln(id)`; letzter EK = jüngste Lieferung mit Preis; Anteile mit Buchungen auf Mitglied, Couleur, Bund inkl. negativer Korrektur (Cent-Summe korrekt, kein Überlauf); Wochenverbrauch über einen Jahreswechsel (`uhrStellen('2027-01-06 12:00:00')`), Buchung in KW 53 und KW 1 in den richtigen Buckets; `kategorie:<id>`/`artikel:<id>` eines Kiosk-Artikels über den Getränke-Bereich → Ansicht `alle` (Review Focus 2); Lieferhistorie gruppiert zwei Zeilen derselben Lieferung; leere Datenbank → keine Warnung, `tagesverbrauch` 0.0, `vorschlag` null (Review Focus 1).
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Statistik: Einkauf-Daten (Verbrauch, Vorschlag, Anteile, Verlauf, Lieferungen)"`

---

### Task 3: StatistikService – Schwund je Zeitraum

**Files:**
- Modify: `app/Libraries/StatistikService.php`, `tests/database/StatistikServiceTest.php`

**Interfaces:**
- Produces:
  - `schwundZeitraeume(int $bereichId, int $anzahl = 6): list<array{auszaehlung_id:int, von:string, bis:string, art:string, erfasst_menge:int, erfasst_cent:int, unerklaert_menge:int, unerklaert_cent:int, ueberschuss_menge:int, ueberschuss_cent:int, verkauft:int, quote:?float}>` (neueste zuerst; Euro-Basis `preis_cent` der Position, für Artikel ohne Position aktueller Preis).
  - `schwundArtikel(int $bereichId, int $zeitraeume = 6, int $limit = 10): list<array{artikel_id, name, erfasst_menge, unerklaert_menge, gesamt_cent, quote:?float}>`.
  - `schwundArtikelVerlauf(int $bereichId, int $artikelId, int $zeitraeume = 6): list<array{von, bis, erfasst_menge, unerklaert_menge, gesamt_cent}>` – Artikel muss zum Bereich gehören, sonst `[]`.
  - `schwundLaufend(int $bereichId): array{menge:int, cent:int}` (erfasster Schwund seit dem letzten Stichtag, aktueller Preis).

- [ ] **Step 1: Failing tests:** zwei abgeschlossene Auszählungen mit Schwund-Bewegungen und Differenzen: erfasst/unerklärt/Überschuss getrennt korrekt; Position mit `start = 1` und negativer Differenz zählt nicht als unerklärt; Auszählung `art = start` → unerklärt 0 (Review Focus 5); Quote = (erfasst + unerklärt Menge) ÷ verkauft; Top-Artikel nach `gesamt_cent` sortiert; Artikel-Verlauf eines Kiosk-Artikels über den Getränke-Bereich → `[]`; laufender Schwund nur nach letztem Stichtag; ohne Auszählung → `schwundZeitraeume` leer, `schwundLaufend` funktioniert.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Statistik: Schwund je Auszählungszeitraum"`

---

### Task 4: Seite „Einkauf“ (ersetzt Bestand)

**Files:**
- Create: `app/Controllers/Wart/EinkaufController.php`, `app/Views/wart/einkauf.php`, `tests/feature/WartEinkaufTest.php`
- Modify: `app/Config/Routes.php` (`GET wart/<bereich>/einkauf` mit `recht:statistik_ansehen@<bereich>`; `GET wart/<bereich>/bestand` → 301 auf `einkauf`), `app/Views/layouts/main.php` (Navigation „Getränkewart“: Einkauf, Lieferung, Schwund, Buchungen, Auszählung), Redirects aus Lieferung/Bewegung auf `einkauf` umstellen, `public/css/app.css` (Druck: nur `.bestellliste` drucken; Kachel-Layout für Anteile mit vorhandenen Tokens), `tests/feature/ZugriffsschutzTest.php`, `tests/feature/WartBestandTest.php` (auf `einkauf` umziehen oder löschen, Fälle übernehmen); Delete: `app/Controllers/Wart/BestandController.php`, `app/Views/wart/bestand.php`

**Interfaces:**
- Consumes: `statistik()->einkauf/anteile/wochenverbrauch/lieferhistorie` (Task 2).
- Abschnitte und Texte exakt nach Spec 2.1–2.4 (Bestellliste mit Druck-Button `data-print`, Hinweiszeile, Bestand mit „Ø Verbrauch/Tag“ und „reicht noch ca.“, Anteil-Kacheln für 28 Tage und letzten Zeitraum mit Vergleichswert, Verlauf als Tabelle mit Diagramm-Container `<canvas data-diagramm='…'>` – Zeichnen erst in Task 6, Lieferhistorie). GET-Parameter `ansicht` als skalarer String, Arrays → `alle`. Genau eine `.btn-vdst` („Lieferung erfassen“).

- [ ] **Step 1: Failing tests:** Getränkewart sieht Bestellliste mit erwartetem Vorschlag „3 Kisten“ für einen Testartikel; Artikel ohne Bedarf fehlt in der Bestellliste, steht aber in der Bestandstabelle; „reicht noch ca. 14 Tage“; Anteile zeigen Couleur/Bund-Prozente; Hinweis „Grundlage erst 5 Tage“ bei frischer Inbetriebnahme (Review Focus 1); `?ansicht[]=x` und `?ansicht=artikel:<kiosk-id>` → 200 mit Standardansicht (Review Focus 2); `wart/getraenke/bestand` → 301 auf `wart/getraenke/einkauf`; Mitglied 403, Kiosk 404; genau eine `.btn-vdst`; keine `style=`-Attribute.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren; `ZugriffsschutzTest::ROUTEN` anpassen (5 Akteure). **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Seite Einkauf mit Bestellliste, Reichweite und Anteilen"`

---

### Task 5: Seite „Schwund“

**Files:**
- Create: `app/Controllers/Wart/SchwundController.php`, `app/Views/wart/schwund.php`, `tests/feature/WartSchwundTest.php`
- Modify: `app/Config/Routes.php` (`GET wart/<bereich>/schwund`, `recht:statistik_ansehen@<bereich>`), `tests/feature/ZugriffsschutzTest.php`

**Interfaces:**
- Consumes: `statistik()->schwundZeitraeume/schwundArtikel/schwundArtikelVerlauf/schwundLaufend` (Task 3), `StatistikRechner::quote`.
- Abschnitte und Texte exakt nach Spec 3.1–3.5 (Kacheln mit Vergleich in Prozentpunkten und Pfeil-Icon `bi-arrow-up`/`bi-arrow-down`, Verlaufstabelle + Diagramm-Container, Top 10, `?artikel=<id>`-Verlauf, laufender Zeitraum, leerer Zustand mit exaktem Hinweistext). Die Seite hat keine `.btn-vdst` (reine Ansicht) – zulässig, höchstens eine.

- [ ] **Step 1: Failing tests:** ohne Auszählung → Hinweis „Noch keine abgeschlossene Auszählung – Schwund wird nach der ersten Auszählung ausgewertet.“ und Zeile „Seit dem letzten Abschluss bereits erfasst“ (Review Focus 1); mit zwei Zeiträumen → Kennzahlen, Pfeil, Tabelle mit Quote, Start-Hinweis; Top-10 sortiert; `?artikel=<id>` zeigt Verlauf, `?artikel=<kiosk-id>` und `?artikel[]=1` → kein Verlauf, 200 (Review Focus 2); Mitglied 403, Kiosk 404.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren. **Step 4:** PASS.
- [ ] **Step 5: Commit** – `"Seite Schwund je Auszählungszeitraum"`

---

### Task 6: Diagramme, Doku

**Files:**
- Create: `public/js/statistik.js`
- Modify: `app/Views/wart/{einkauf,schwund}.php` (Chart.js 4 per `<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js">` nur auf diesen Seiten, `statistik.js` mit Cache-Buster), `CLAUDE.md`, `README.md`, Haupt-Spec Abschnitt 8.3 (Verweis auf die Statistik-Spec)
- Test: `tests/feature/WartEinkaufTest.php`, `tests/feature/WartSchwundTest.php` (erweitern)

**Interfaces:**
- `data-diagramm` enthält JSON `{typ:'linie'|'saeulen-gestapelt', labels:[…], reihen:[{name, werte:[…]}]}`, erzeugt mit `json_encode(…, JSON_THROW_ON_ERROR)` und ausgegeben mit `esc(…, 'attr')`. `statistik.js` liest alle `canvas[data-diagramm]`, Farben aus CSS-Custom-Properties (`getComputedStyle(document.documentElement)`, vorhandene Tokens, je Reihe zyklisch), reagiert auf Theme-Wechsel (Neuzeichnen beim Toggle-Ereignis aus `app.js` oder `MutationObserver` auf `data-bs-theme`). Keine Inline-Handler.

- [ ] **Step 1: Failing tests:** Ein Artikel namens `Bier "Spezial" <b>` erscheint im `data-diagramm`-Attribut nur escapt (kein `<b>` roh, Attribut nicht aufgebrochen) und als gültiges JSON nach `html_entity_decode` (Review Focus 3); Chart.js-Skript nur auf Einkauf/Schwund, nicht auf `buchen`.
- [ ] **Step 2:** FAIL. **Step 3:** implementieren; `node --check public/js/statistik.js` (per `docker run --rm node:20-alpine`). **Step 4:** PASS, gesamte Suite (allein).
- [ ] **Step 5: Doku** CLAUDE.md (Statistik-Landkarte), README (Wart-Funktionen), Haupt-Spec 8.3 Verweis.
- [ ] **Step 6: Commit** – `"Diagramme für Einkauf und Schwund, Doku"`
