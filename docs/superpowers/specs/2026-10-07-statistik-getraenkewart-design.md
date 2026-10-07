# Statistik für den Getränkewart – Design-Spec

- **Stand:** 07.10.2026
- **Status:** Entwurf zur Freigabe
- **Bezug:** Haupt-Spec `docs/superpowers/specs/2026-10-05-getraenkeliste-design.md`, Abschnitt 8.3 (Statistikseite) und 12 (Stufe 3). Diese Spec **ersetzt** Abschnitt 8.3 für den Wart-Bereich und wird direkt nach Stufe 2 umgesetzt; die übrigen Stufe-3-Punkte (Spenden, Kassenwart, Kiosk-Freischaltung) bleiben unverändert.
- **Baut auf:** Stufe 2 (Bestand, Lieferungen, Schwund/Korrektur, Auszählungen, gemeinsamer Bestandsbaustein `BestandService::aggregat`).

## 1. Zweck

Der Getränkewart soll zwei Entscheidungen schnell und mit belastbaren Zahlen treffen können – in dieser Reihenfolge:

1. **Einkauf planen:** Was muss bei der nächsten Lieferung kommen, und wie viel?
2. **Schwund in den Griff bekommen:** Wo verschwindet Ware, bei welchen Artikeln, und wird es besser oder schlechter?

Dazu kommt der **Anteil von Couleur und Bund** am Verbrauch (Menge und Umsatz).

Bewusst **nicht** enthalten: Umsatzberichte für Kassenwart/Convent, Verteilung nach Wochentag, Vergleich zum Vorjahr, Auswertungen einzelner Personen, eigene Erfassung von Bestellungen (Bestellen und Liefern liegen so nah beieinander, dass die Lieferhistorie genügt).

## 2. Seite „Einkauf“ (`GET wart/<bereich>/einkauf`)

Ersetzt die Bestandsseite aus Stufe 2. `GET wart/<bereich>/bestand` leitet dauerhaft (301) auf `einkauf` um.

### 2.1 Bestellliste für die nächste Lieferung (oben)
- Nur Artikel mit `bestand_fuehren = 1`, nicht archiviert, mit einem Vorschlag > 0; gruppiert nach Kategorie (Sortierung wie überall).
- Spalten: Artikel, Bestand, reicht noch (Tage), Vorschlag, letzter Einkaufspreis je Stück.
- **Tagesverbrauch** = verkaufte Menge der letzten 28 Tage ÷ Grundlage-Tage. Verkauft = Summe `menge` nicht stornierter Buchungen inkl. `quelle = korrektur`. Grundlage-Tage = 28, aber höchstens die Tage seit `inbetriebnahme_at` (mindestens 1); ist sie < 28, erscheint der Hinweis „Grundlage erst X Tage“.
- **Vorschlag** = ⌈Tagesverbrauch × `reichweite_tage` + `mindestbestand` − Bestand⌉. Bei ≤ 0 kein Vorschlag. Mit `gebinde_groesse` wird auf volle Kisten aufgerundet und als „N Kisten (= M Stück)“ angezeigt, sonst in Stück.
- **Letzter Einkaufspreis** = `einkaufspreis_cent` der jüngsten Lieferung mit Preis, sonst „—“.
- Button „Bestellliste drucken“ (vorhandenes `data-print`), druckt nur diesen Block (Druck-CSS).
- Hinweiszeile: „Grundlage: Verbrauch der letzten 28 Tage, Reichweite <n> Tage (änderbar in den Einstellungen).“

### 2.2 Bestand aller Artikel (Mitte)
- Die Bestandstabelle aus Stufe 2 je Kategorie (Ampel, Warnung bei negativem Bestand) plus zwei Spalten:
  - „Ø Verbrauch/Tag“ (eine Nachkommastelle),
  - „reicht noch ca.“: ⌊Bestand ÷ Tagesverbrauch⌋ Tage; „—“ ohne Verbrauch; „leer“ bei Bestand ≤ 0.
- Die Buttons „Lieferung erfassen“ (die eine `.btn-vdst`) und „Schwund/Korrektur“ bleiben.

### 2.3 Anteil Couleur / Bund
- Kachelreihe für **letzte 28 Tage** und **letzten abgeschlossenen Zeitraum** (falls vorhanden): Anteil Mitglieder / Couleur / Bund an der verkauften **Menge** und am **Umsatz** (€), jeweils mit dem Wert des Zeitraums davor (28 Tage davor bzw. vorheriger abgeschlossener Zeitraum) als Vergleich.
- Grundlage: nicht stornierte Buchungen inkl. Korrekturen; Umsatz = Σ `menge × CAST(einzelpreis_cent AS SIGNED)`; Couleur/Bund über `personen.typ = sammelkonto` und deren Anzeigename (`sammelkontoId`).
- Ohne Verbrauch: Anzeige „—“.

### 2.4 Verlauf (unten)
- **Verbrauch je Woche, letzte 12 Wochen** (ISO-Kalenderwochen Mo–So, die laufende Woche als „bis heute“ markiert): Liniendiagramm; Auswahl per GET-Parameter `ansicht` = `alle` (je Kategorie eine Linie), `kategorie:<id>` oder `artikel:<id>` (ungültige Werte → `alle`). Darunter dieselben Zahlen als Tabelle (funktioniert ohne JavaScript).
- **Lieferhistorie:** letzte 20 Lieferungen (Bewegungen `art = lieferung`, gruppiert nach `erfolgt_at` + erfasst von): Datum, Artikel mit Menge, Einkaufspreis je Stück, erfasst von.

## 3. Seite „Schwund“ (`GET wart/<bereich>/schwund`)

Grundlage sind **abgeschlossene Auszählungen**; nur dort steht die Differenz fest.

### 3.1 Kennzahlen des letzten abgeschlossenen Zeitraums
- **Erfasster Schwund** (€): Σ |`menge`| der Bewegungen `art = schwund` im Zeitraum × `preis_cent` der Position des Artikels in dieser Auszählung (Artikel ohne Position: aktueller Preis).
- **Unerklärte Differenz** (€): Σ |`differenz_cent`| der Positionen mit `differenz < 0` und `start = 0`; bei `auszaehlungen.art = start` immer 0. Überschüsse (`differenz > 0`) werden getrennt als „Überschuss“ angezeigt und nicht verrechnet.
- **Schwundquote** = (erfasste Menge + unerklärte Fehlmenge) ÷ verkaufte Menge des Zeitraums, in Prozent mit einer Nachkommastelle; „—“ bei 0 verkauft.
- **Vergleich** zum vorherigen abgeschlossenen Zeitraum: Differenz der Quote in Prozentpunkten mit Pfeil.

### 3.2 Verlauf der letzten 6 Zeiträume
- Gestapeltes Säulendiagramm (€): erfasster Schwund + unerklärte Differenz je Zeitraum, beschriftet „von – bis“ (Datum). Start-Auszählungen erscheinen mit 0 und dem Hinweis „Start“.
- Tabelle mit denselben Werten und der Quote.

### 3.3 Artikel mit dem meisten Schwund
- Top 10 über die letzten 6 Zeiträume nach € gesamt: Artikel, erfasst (Menge), unerklärt (Menge), gesamt (€), Quote.
- GET-Parameter `artikel=<id>` zeigt darunter die Werte dieses Artikels je Zeitraum (ungültige Werte werden ignoriert).

### 3.4 Laufender Zeitraum
- Zeile „Seit dem letzten Abschluss bereits erfasst: X Stück / Y €“ (nur erfasster Schwund, aktueller Preis).

### 3.5 Leerer Zustand
- Vor der ersten abgeschlossenen Auszählung: Hinweis „Noch keine abgeschlossene Auszählung – Schwund wird nach der ersten Auszählung ausgewertet.“ plus Zeile 3.4.

## 4. Aufbau

| Baustein | Aufgabe |
|---|---|
| `Libraries/StatistikRechner` (rein, statisch) | Tagesverbrauch, Reichweite in Tagen, Bestellvorschlag (Kisten/Stück), Anteile, Schwundquote, Vergleich, Wochen-Buckets (ISO-Woche aus Datum) |
| `Libraries/StatistikService` (Service `statistik()`) | Rohwerte per Aggregatabfragen: Verbrauch letzte 28 Tage und je Woche, Anteile je Konto-Art, Lieferhistorie, Schwund je Zeitraum. Bestand ausschließlich über `bestand()` / `BestandService::aggregat` (keine zweite Bestandsrechnung) |
| `Controllers/Wart/EinkaufController`, `Controllers/Wart/SchwundController` | dünn; Parameter gehärtet (Arrays/ungültige IDs → Standard) |
| Views `wart/einkauf.php`, `wart/schwund.php` | Tabellen + Diagramm-Container mit `data-*`-Werten (JSON, `esc(…, 'attr')`) |
| `public/js/statistik.js` + Chart.js (CDN `cdn.jsdelivr.net`, wie Bootstrap; nur auf diesen Seiten) | zeichnet aus `data-*`; Farben aus CSS-Custom-Properties (Darkmode); ohne JavaScript bleiben die Tabellen |
| Einstellung `reichweite_tage` | Standard 30, erlaubt 7–90, in `EinstellungDefinition` + Migration (Default-Zeile) |

- **Rechte:** beide Seiten `angemeldet` + `recht:statistik_ansehen@<bereich>` (Getränkewart/Kioskwart je Bereich, Admin). Inaktiver Bereich → 404.
- **Navigation „Getränkewart“:** Einkauf, Lieferung, Schwund, Buchungen, Auszählung.
- **Zeit:** „jetzt“ nur über `service('uhr')`; Fenster der letzten 28 Tage = (jetzt − 28 Tage, jetzt].
- **Geld:** Cent-Summen, SQL mit `CAST(einzelpreis_cent AS SIGNED)`; Anzeige über `formatiere_cent`.
- **Leistung:** wenige Aggregatabfragen je Seite, keine Schleifen-Abfragen je Artikel.

## 5. Tests

- **Unit (`StatistikRechner`):** Tagesverbrauch mit < 28 Grundlage-Tagen; Reichweite bei 0 Verbrauch / Bestand ≤ 0; Vorschlag mit und ohne Kistengröße, Aufrunden, ≤ 0 → kein Vorschlag; Anteile bei 0 Gesamt; Quote bei 0 verkauft; ISO-Woche über Jahreswechsel.
- **DB (`StatistikService`):** Verbrauch 28 Tage und je Woche inkl. stornierter (zählt nicht) und negativer Korrekturbuchungen; Anteile Couleur/Bund/Mitglieder; Lieferhistorie-Gruppierung; Schwund je Zeitraum (erfasst, unerklärt, Überschuss getrennt, Start = 0); **Gleichheit:** Bestand auf „Einkauf“ = `bestand()->einzeln`.
- **Feature:** beide Seiten rendern (genau eine `.btn-vdst`), Bestellliste enthält den erwarteten Vorschlag, `ansicht`/`artikel`-Parameter inkl. Arrays/ungültiger Werte ohne 500, Umleitung `bestand` → `einkauf` (301), Mitglied 403, Kiosk 404, `ZugriffsschutzTest` erweitert, leerer Zustand „Schwund“.
