# Getränkeliste VDSt – Design-Spec

- **Stand:** 05.10.2026
- **Status:** freigegeben (05.10.2026, mit Ergänzungen aus der Freigabe eingearbeitet)
- **Repo:** https://github.com/AntekMS/getraenkeliste-vdst
- **Bezug:** Kassensystem VDSt (https://github.com/AntekMS/kassensystem-vdst, Branch `small`)

## 1. Zweck und Ziel

Die Getränkeliste ersetzt Strichliste und Fremd-App („Getränkeliste“) des Vereins deutscher Studenten zu Erlangen durch eine eigene Web-App.

- Jedes Mitglied bucht **nur seinen eigenen Verbrauch** (Artikel und Menge), am Kühlschrank-Tablet oder am eigenen Handy.
- Ein **Admin** pflegt alles über die Oberfläche: Getränke, Preise, Kategorien, Personen, Rollen, Tablets und Einstellungen. Code-Änderungen sind nur noch für neue Funktionen nötig.
- Der **Getränkewart** sieht und pflegt den Bestand (Lieferungen, Schwund), macht zu frei gewählten Stichtagen eine **Auszählung** und erhält eine **maschinenlesbare Excel** mit Statistiken.
- Daneben gibt es eine **Spenden-Liste** (Geldspenden) und den **Fuxenkiosk** mit eigenen Artikeln, eigener Kasse und eigenem Verwalter (Kioskwart).

**Erfolgskriterien**
1. Strichliste und Fremd-App werden nicht mehr gebraucht.
2. Der Getränkewart kennt jederzeit den Bestand und sieht Schwund schwarz auf weiß.
3. Der Kassenwart bekommt je Zeitraum eine Excel, die er ohne Abtippen weiterverarbeiten und später automatisch ins Kassensystem importieren kann.

**Rahmen:** 30–100 Konten, Betrieb auf dem vorhandenen Raspberry Pi 4 (4 GB) neben dem Kassensystem. Zunächst nur im Haus-WLAN erreichbar, später übers Internet, ohne dafür umbauen zu müssen.

## 2. Abgrenzung (bewusst nicht enthalten)

- Bezahlung, Guthaben, Einzahlungen: Die App **zählt nur Verbrauch**. Rechnung, Zahlung und Schulden bleiben im Kassensystem.
- E-Mail-Versand, Push-Benachrichtigungen
- Gastkonten (Gäste bucht ein Mitglied auf sich oder Couleur/Bund)
- Offline-Modus bzw. Warteschlange am Tablet
- Ranglisten einzelner Personen
- Automatische Auszählung: Auszählungen löst **ausschließlich** der jeweilige Wart aus; die App erinnert nur.
- Oberfläche zum Anlegen weiterer Bereiche (der Code ist allgemein gehalten, angelegt sind `getraenke` und `kiosk`)
- Anpassung des Imports im Kassensystem: eigenes Folgeprojekt, das auf `format_version` (Abschnitt 8) aufsetzt. Das bisherige Getränkerechnung-Format (Sheets „Bundesbrüder & Gäste“ / „Coleur & Bund“) wird **nicht** nachgebaut.

## 3. Architektur und Betrieb

| | |
|---|---|
| Stack | CodeIgniter 4.7, PHP 8.3, MySQL 8, PhpSpreadsheet 5, Bootstrap 5 + Bootstrap Icons, Chart.js; etwas leichtgewichtiges JavaScript für die Buchungsseite (Buchen ohne Seitenneuladen) |
| Optik | angelehnt an das Design-System des Kassensystems (`public/css/app.css`, keine `<style>`-Blöcke in Views) |
| Deployment | eigenes Docker-Compose-Projekt `getraenkeliste` auf dem Pi: Web-Container (PHP/Apache) auf **Port 8090**, eigener MySQL-Container nur im internen Netz. Lokal läuft zusätzlich **phpMyAdmin auf Port 8091** (kein Mailpit, die App verschickt keine Mails); auf dem Pi wird phpMyAdmin wie beim Kassensystem über eine lokale, nicht versionierte Override-Datei geregelt |
| Backup | täglich per systemd-Timer auf den vorhandenen USB-Stick `/mnt/kasse-backup`, eigener Unterordner `getraenkeliste/`, gleiches Muster wie beim Kassensystem (DB-Dump, gespeicherte Exporte, Konfiguration) |
| Konfiguration | Geheimnisse und Infrastruktur nur in `.env`; `app.baseURL` und `cookie.secure` nur dort, damit später ein HTTPS-Tunnel oder Reverse-Proxy ohne Codeänderung davorgeschaltet werden kann. Die Tabelle `einstellungen` enthält **nur Betriebswerte, die der Admin ändern darf** (Abschnitt 6) |
| Zeitzone | Europe/Berlin; alle Zeitpunkte als Serverzeit gespeichert |

Die App ist **vollständig getrennt** vom Kassensystem (eigene DB, eigene Container). Mitglieder-Logins kommen so nie in die Nähe der Vereinskasse. Die Verbindung zum Kassensystem läuft ausschließlich über die Excel-Exporte.

**Bewusste Abweichungen vom Kassensystem** (dessen `CLAUDE.md` schließt sie für sich aus; hier sind sie gewollt):
- **Rollen und Mehrbenutzerbetrieb** sind der Zweck dieser App.
- **Tabelle `einstellungen`**, beschränkt auf vom Admin änderbare Betriebswerte (siehe oben).
- **Beträge als Cent-INT** statt DECIMAL. Die Komma-Eingabe wird wie dort mit `normalisiere_betrag()` normalisiert und danach in Cent umgerechnet.

## 4. Rollen und Rechte

Rollen sind pro Person kombinierbar und werden vom Admin in der Oberfläche vergeben.

| Rolle | Rechte |
|---|---|
| Mitglied | eigene Buchungen anlegen; eigene Buchungen innerhalb der Storno-Frist stornieren; eigene Übersicht, offenen Betrag und frühere Zeiträume sehen; eigenes Passwort und eigene PIN ändern |
| Getränkewart | im Bereich `getraenke`: Bestand, Lieferungen, Schwund/Korrekturen, Buchungen aller Konten ansehen/stornieren/korrigieren, Auszählungen, Excel, Statistik; Spenden-Liste |
| Kioskwart | dasselbe im Bereich `kiosk`; keine Spenden-Liste |
| Kassenwart | Auszählungen beider Bereiche ansehen und herunterladen; Spenden-Liste ansehen, pflegen und exportieren; keine Bestandsänderungen |
| Admin | alles aus allen Rollen, dazu Personen, Rollen, Kategorien, Artikel, Preise, Tablets, Einstellungen, Änderungsprotokoll |

Die Rechte werden zentral in einer reinen Klasse `Berechtigung` (Rolle × Bereich × Aktion) entschieden. Controller und Filter fragen nur diese Klasse.

## 5. Anmeldung

### 5.1 Eigenes Gerät (Handy/Laptop)
- Benutzername + Passwort; Option „angemeldet bleiben“ (langlebiges, rotierendes Remember-Token, Tabelle `anmelde_tokens`).
- **Nur hier** sind Wart-, Kassenwart- und Admin-Bereiche erreichbar.
- **Erster Login / nach Reset:** Wer sich mit einem Einmal-Passwort anmeldet (aus dem CSV-Import oder einem Passwort-Reset durch den Admin, `passwort_wechsel_erzwingen = 1`), muss vor allem anderen ein **neues Passwort und eine PIN** setzen. Bis dahin ist nur diese Seite und der Logout erreichbar.
- **PIN-Reset durch den Admin** löscht die PIN (`pin_hash = NULL`). Die Person setzt danach beim nächsten Login am eigenen Gerät eine neue PIN (gleiche Pflichtseite wie oben, nur PIN).

### 5.2 Kühlschrank-Tablet
- Ein Admin erzeugt am eigenen Gerät einen **einmaligen Freischaltcode** (gültig 15 Minuten). Wird er am Tablet eingegeben, setzt das ein dauerhaftes Geräte-Cookie (Token, in der DB nur als Hash gespeichert).
- Das Tablet zeigt **Namenskacheln**: vorne fest **Couleur** und **Bund**, danach die **12 Personen mit den jüngsten Buchungen**, dazu ein Suchfeld und die Filter Aktive/AHs/Alle (`sonstige` erscheinen nur unter „Alle“).
- Personen **ohne PIN** sind am Tablet nicht wählbar (Kachel ausgegraut mit Hinweis „Bitte zuerst am eigenen Gerät eine PIN setzen“); serverseitig wird eine PIN-Anmeldung ohne `pin_hash` abgelehnt.
- Person antippen → **PIN (4–6 Ziffern)** → Buchungsseite → Buchen → Bestätigung → automatisch zurück zur Namensauswahl. Spätestens nach 30 s ohne Eingabe (Einstellung) geht es ebenfalls zurück.
- **Couleur und Bund** sind am Tablet ohne PIN bebuchbar, für alle. Am eigenen Gerät siehe Abschnitt 7.1.
- Am Tablet gibt es **nur Buchen** und die Bestätigung mit Rückgängig, auch wenn ein Admin oder Wart seinen Namen wählt.
- Ein Admin kann Tablets jederzeit sperren.

### 5.3 Absicherung
- Passwörter und PINs nur gehasht (`password_hash`).
- PIN-Sperre: nach 5 Fehlversuchen ist die Person am Tablet 5 Minuten gesperrt (`pin_fehlversuche`, `pin_gesperrt_bis`). Login-Sperre analog für Benutzername/Passwort (`login_fehlversuche`, `login_gesperrt_bis`); dieselbe reine Klasse `PinSperre` entscheidet beides.
- Remember-Tokens werden nur als Hash gespeichert, bei jeder Nutzung rotiert und bei Logout, Passwortwechsel, Passwort-Reset und Archivierung der Person gelöscht.
- CSRF-Schutz auf allen schreibenden Anfragen; Session-Fixation-Schutz (neue Session-ID nach Login).
- Jede Änderung durch Wart, Kassenwart oder Admin wird ins `protokoll` geschrieben. **Nicht** protokolliert werden Buchungen und Stornos, die ein Mitglied für sich selbst (oder am Tablet) vornimmt: Die Buchung selbst hält das fest (`storniert_at`, `storniert_von_id`).

## 6. Datenmodell

**Konventionen:** Geldbeträge in **Cent (INT)**. Artikel und Personen werden **archiviert**, nicht gelöscht; Buchungen und Spenden werden **storniert**, nicht gelöscht. Alle Tabellen haben `created_at`/`updated_at`.

| Tabelle | Spalten (Auszug) |
|---|---|
| `bereiche` | `id`, `schluessel` (`getraenke`/`kiosk`), `name` („Getränke“, „Fuxenkiosk“), `verwalter_rolle`, `aktiv` (inaktive Bereiche sind überall ausgeblendet; `kiosk` startet inaktiv, Abschnitt 12) |
| `personen` | `id`, `vorname`, `nachname`, `anzeigename`, `gruppe` (`aktiv`/`ah`/`sonstige`), `typ` (`mitglied`/`sammelkonto`), `benutzername` (unique, nullable für Sammelkonten), `passwort_hash`, `passwort_wechsel_erzwingen` (bool), `login_fehlversuche`, `login_gesperrt_bis`, `pin_hash` (nullable: ohne PIN am Tablet nicht wählbar), `pin_fehlversuche`, `pin_gesperrt_bis`, `archiviert_at` |
| `anmelde_tokens` | `id`, `person_id`, `selector` (unique), `token_hash`, `gueltig_bis`, `zuletzt_genutzt_at` (Remember-Token „angemeldet bleiben“, Selector/Validator-Verfahren) |
| `person_rollen` | `person_id`, `rolle` (`getraenkewart`/`kioskwart`/`kassenwart`/`admin`); „Mitglied“ ergibt sich aus `typ = mitglied` |
| `kategorien` | `id`, `bereich_id`, `name`, `sortierung`, `archiviert_at` |
| `artikel` | `id`, `kategorie_id`, `name`, `preis_cent`, `einheit`, `gebinde_groesse` (Stück je Kiste, nullable), `mindestbestand`, `bestand_fuehren` (bool), `sortierung`, `archiviert_at` |
| `buchungen` | `id`, `vorgang_id` (UUID des Warenkorbs), `konto_id` → personen, `artikel_id`, `menge`, `einzelpreis_cent` (Preis zum Buchungszeitpunkt), `quelle` (`tablet`/`web`/`korrektur`), `gebucht_von_id` (nullable), `geraet_id` (nullable), `gebucht_at`, `storniert_at`, `storniert_von_id`, `storno_grund`, `bemerkung` (nullable, Grund einer Korrekturbuchung) |
| `bestandsbewegungen` | `id`, `artikel_id`, `art` (`lieferung`/`schwund`/`korrektur`), `menge` (±), `einkaufspreis_cent` (nullable), `bemerkung`, `person_id`, `erfolgt_at` |
| `auszaehlungen` | `id`, `bereich_id`, `art` (`start`/`regulaer`), `stichtag`, `zeitraum_von`, `status` (`entwurf`/`abgeschlossen`), `erstellt_von_id`, `abgeschlossen_at`, `datei_pfad`, `bemerkung` |
| `auszaehlung_positionen` | `auszaehlung_id`, `artikel_id`, `anfangsbestand`, `lieferungen`, `schwund_erfasst`, `korrekturen`, `verkauft`, `soll`, `ist`, `differenz`, `start` (bool: Artikel hatte keine frühere Position, Differenz zählt nicht als Schwund; `ist` ist im Entwurf NULL), `preis_cent` |
| `spenden` | `id`, `spender_person_id` (nullable), `spender_freitext` (nullable), `betrag_cent`, `datum`, `zweck`, `bemerkung`, `erfasst_von_id`, `storniert_at` |
| `geraete` | `id`, `name`, `token_hash`, `zuletzt_gesehen_at`, `gesperrt_at` |
| `freischaltcodes` | `id`, `code_hash`, `gueltig_bis`, `erstellt_von_id`, `eingeloest_at` |
| `einstellungen` | `schluessel`, `wert` – nur vom Admin änderbare Betriebswerte, z. B. `storno_frist_min` = 10, `tablet_timeout_s` = 30, `vereinsname`, `erinnerung_tage` = 31, `inbetriebnahme_at` (Zeitpunkt der Inbetriebnahme, von der Migration auf „jetzt“ gesetzt; Beginn des ersten Zeitraums, Abschnitt 6.1). Keine Geheimnisse, keine Infrastruktur (die bleiben in `.env`) |
| `protokoll` | `id`, `person_id`, `aktion`, `tabelle`, `datensatz_id`, `alt` (JSON), `neu` (JSON), `erfolgt_at` |

Indizes: unique (`vorgang_id`, `artikel_id`) in `buchungen` (Idempotenz); (`konto_id`, `gebucht_at`); (`artikel_id`, `gebucht_at`); (`artikel_id`, `erfolgt_at`).

Die Sammelkonten **Couleur** und **Bund** werden per Migration als `personen` mit `typ = sammelkonto` angelegt.

### 6.1 Zeiträume
- Ein Zeitraum eines Bereichs reicht vom Stichtag der letzten **abgeschlossenen** Auszählung (exklusiv) bis zum Stichtag der nächsten (inklusiv). Vor der ersten Auszählung beginnt er bei der Inbetriebnahme (`einstellungen.inbetriebnahme_at`).
- Buchungen werden ihrem Zeitraum über `gebucht_at` und den Bereich des Artikels zugeordnet.
- **Eingefrorene Zeiträume:** Buchungen, Bewegungen und Spenden mit Zeitpunkt ≤ letztem abgeschlossenem Stichtag sind unveränderlich (kein Storno, keine Änderung). Fehler daraus behebt der Wart mit einer **Korrekturbuchung** (`quelle = korrektur`, Menge auch negativ) im laufenden Zeitraum.

### 6.2 Bestand
Aktueller Bestand eines Artikels =
`ist` der letzten abgeschlossenen Auszählung (bzw. 0 ohne Auszählung)
\+ Summe `bestandsbewegungen` danach
− Summe `menge` nicht stornierter `buchungen` danach.

Der Bestand wird immer **berechnet**, nie gespeichert. Ein negativer Bestand ist erlaubt und wird dem Wart als Warnung angezeigt. Die erste Auszählung eines Bereichs (und die erste nach dem Anlegen neuer Artikel, für diese Artikel) legt den Anfangsbestand fest: Bei `art = start` gilt die Differenz nicht als Schwund und erscheint nicht in der Schwundstatistik. Für Artikel mit `bestand_fuehren = false` (z. B. Dienstleistungen im Kiosk) wird kein Bestand angezeigt.

### 6.3 Offener Betrag
Offener Betrag eines Kontos in einem Bereich = Summe (`menge` × `einzelpreis_cent`) der nicht stornierten Buchungen im laufenden Zeitraum. Das ist rein informativ; bezahlt wird übers Kassensystem.

## 7. Abläufe und Seiten

### 7.1 Buchungsseite (Tablet und eigenes Gerät)
- Oben die Reiter je aktivem Bereich (**Getränke | Fuxenkiosk**), darunter Kategorien als Reiter, dann Artikelkacheln (Name, Einheit, Preis, **+ / −**).
- **Am eigenen Gerät** steht über dem Warenkorb die Auswahl **„für mich / Couleur / Bund“** (Standard: für mich). Bei Couleur/Bund ist `konto_id` das Sammelkonto und `gebucht_von_id` die angemeldete Person, damit sichtbar bleibt, wer auf ein Sammelkonto gebucht hat. Bei „für mich“ ist `gebucht_von_id` ebenfalls die Person selbst. Am Tablet entfällt die Auswahl (die Kachel bestimmt das Konto); `gebucht_von_id` ist dort die per PIN angemeldete Person bzw. bei Couleur/Bund NULL, `geraet_id` ist gesetzt.
- Unten ein Warenkorb mit Summe und **Buchen**. Beim Öffnen bekommt der Warenkorb eine `vorgang_id` (UUID).
- Nach dem Buchen erscheint eine Bestätigung („3× Helles, 1× Spezi – 6,50 €“) mit **Rückgängig** (storniert den ganzen Vorgang, innerhalb der Storno-Frist).
- Bestand 0 oder negativ blockiert nicht: Es wird gebucht, der Wart sieht eine Warnung.
- Archivierte Artikel werden nicht angezeigt und serverseitig abgelehnt.

### 7.2 Eigener Bereich des Mitglieds (nur eigenes Gerät)
- **Meine Buchungen** im laufenden Zeitraum je Bereich, stornierbar innerhalb der Storno-Frist; dazu gesondert die Buchungen, die die Person selbst auf Couleur/Bund gebucht hat (`gebucht_von_id`), ebenfalls innerhalb der Frist stornierbar. Sie zählen nicht zum eigenen offenen Betrag.
- offener Betrag je Bereich; frühere Zeiträume mit Summen
- Passwort und PIN ändern

### 7.3 Wart-Bereich (Getränkewart für `getraenke`, Kioskwart für `kiosk`)
- **Bestand:** Tabelle je Kategorie mit aktuellem Bestand, Ampel unter `mindestbestand`, Hinweis bei negativem Bestand, „reicht noch ca. X Tage“ (Abschnitt 8.3).
- **Lieferung erfassen:** mehrere Artikel in einem Formular, Eingabe in Kisten (× `gebinde_groesse`) oder Stück, optional Einkaufspreis, Bemerkung.
- **Schwund / Korrektur:** Artikel, Menge, **Pflicht-Bemerkung**.
- **Buchungen:** Liste des laufenden Zeitraums, filterbar nach Person, Artikel und Tag; Storno mit Pflicht-Grund; Korrekturbuchung für ein Konto.
- **Auszählung:**
  1. Stichtag wählen (Standard: jetzt; muss nach dem letzten abgeschlossenen Stichtag und darf nicht in der Zukunft liegen).
  2. Formular mit vorausgefülltem Soll je Artikel; Ist eintragen; Differenz wird live angezeigt.
  3. Als **Entwurf** speichern (höchstens ein Entwurf je Bereich) oder **abschließen**.
  4. Abschluss (eine Transaktion): Positionen festschreiben, Status `abgeschlossen`, Zeitraum einfrieren, Excel erzeugen und unter `writable/exporte/` speichern.
- **Auszählungen:** Liste aller abgeschlossenen Auszählungen mit Download und „Datei neu erzeugen“.
- **Erinnerung:** Banner, wenn die letzte abgeschlossene Auszählung länger als `erinnerung_tage` zurückliegt.
- **Statistik:** Abschnitt 8.3.

### 7.4 Kassenwart
Liste der Auszählungen beider Bereiche mit Download; Spenden-Liste.

### 7.5 Spenden-Liste (Kassenwart, Getränkewart, Admin)
- Spende erfassen: Spender (Person aus Liste **oder** Freitext), Betrag, Datum, Zweck, Bemerkung.
- Liste mit Filter nach Zeitraum und Zweck, Summen je Zweck; Storno mit Grund (eingefrorene Zeiträume gelten hier nicht, da Spenden keinem Bereich angehören; jede Änderung landet im Protokoll).
- Excel-Export für einen frei gewählten Zeitraum (Abschnitt 8.2).

### 7.6 Admin
- **Personen:** anlegen, bearbeiten, Rollen vergeben, archivieren. **Passwort zurücksetzen** erzeugt ein Einmal-Passwort (einmalig angezeigt) und erzwingt beim nächsten Login neues Passwort und PIN, sofern keine PIN gesetzt ist. **PIN zurücksetzen** löscht die PIN (Abschnitt 5.1). **CSV-Import** (`vorname;nachname;gruppe;benutzername`) mit Vorschau und Dublettenprüfung; jede importierte Person bekommt ein Einmal-Passwort, das nach dem Import einmalig als Liste angezeigt wird.
- **Kategorien und Artikel** je Bereich: anlegen, umbenennen, sortieren (hoch/runter), Preis ändern (wirkt ab sofort, nie rückwirkend), archivieren.
- **Tablets:** Freischaltcode erzeugen, Geräte umbenennen, sperren.
- **Einstellungen:** Werte aus `einstellungen`.
- **Änderungsprotokoll:** filterbare Liste.
- Der erste Admin wird bei der Installation per `php spark admin:anlegen` erzeugt.

## 8. Excel-Exporte und Statistik

### 8.1 Regeln für Maschinenlesbarkeit (alle Datenblätter)
1. Zeile 1 = Kopfzeile mit **festen technischen Spaltennamen** (`snake_case`, ASCII), ab Zeile 2 genau ein Datensatz pro Zeile.
2. Keine verbundenen Zellen, keine Titel- oder Leerzeilen über den Daten, **keine Formeln**, nur Werte.
3. Beträge als Zahl in Euro mit 2 Nachkommastellen (`betrag_eur`), Mengen als Ganzzahl, Daten als echte Excel-Datumswerte im Format `JJJJ-MM-TT` bzw. `JJJJ-MM-TT hh:mm`.
4. Stabile IDs (`konto_id`, `artikel_id`, `buchung_id`) in jedem Datenblatt, damit ein Import über IDs und nicht über Namen zuordnet.
5. Datenblätter als formatierte Excel-Tabellen mit Filter (gut lesbar, ändert nichts an der Struktur).
6. Blattnamen ohne Umlaute und Leerzeichen.
7. Jede Änderung an Blättern oder Spalten erhöht `format_version`.

### 8.2 Aufbau

**Auszählung** (je Bereich), Dateiname `Auszaehlung_<bereich>_<von>_bis_<bis>.xlsx`:

| Blatt | Spalten / Inhalt |
|---|---|
| `Uebersicht` | für Menschen: Zeitraum, Umsatz, Summen Mitglieder/Couleur/Bund, Schwund in €, Top-5-Artikel, Hinweise (z. B. negative Bestände) |
| `Abrechnung` | `konto_id`, `konto_typ`, `vorname`, `nachname`, `anzeigename`, `gruppe`, `anzahl_artikel`, `betrag_eur` – eine Zeile je Konto mit Verbrauch; Hauptquelle für den Import ins Kassensystem |
| `Positionen` | `konto_id`, `anzeigename`, `artikel_id`, `artikel`, `kategorie`, `menge`, `einzelpreis_eur`, `summe_eur` – je Konto × Artikel × Preis |
| `Buchungen` | `buchung_id`, `vorgang_id`, `gebucht_am`, `konto_id`, `anzeigename`, `artikel_id`, `artikel`, `menge`, `einzelpreis_eur`, `summe_eur`, `quelle`, `gebucht_von`, `storniert`, `storno_grund` |
| `Bestand` | `artikel_id`, `artikel`, `kategorie`, `anfangsbestand`, `lieferungen`, `schwund_erfasst`, `korrekturen`, `verkauft`, `soll`, `ist`, `differenz`, `differenz_eur`, `verkaufspreis_eur`, `einkaufswert_eur` |
| `Bewegungen` | `bewegung_id`, `erfolgt_am`, `artikel_id`, `artikel`, `art`, `menge`, `einkaufspreis_eur`, `bemerkung`, `erfasst_von` |
| `Statistik` | Umsatz je Kategorie, je Kalenderwoche, je Wochentag, Vergleich zum Vorzeitraum, dazu Diagramme |
| `Erklaerungen` | `blatt`, `spalte`, `erklaerung` – jedes Blatt und jede Spalte in einem Satz |
| `Meta` | `schluessel`, `wert`: `format_version` (= `getraenkeliste-auszaehlung/1`), `bereich`, `auszaehlung_id`, `zeitraum_von`, `zeitraum_bis`, `erstellt_am`, `erstellt_von`, `app_version`, `summe_abrechnung_eur`, `anzahl_konten`, `anzahl_buchungen` |

**Spenden-Export**, Dateiname `Spenden_<von>_bis_<bis>.xlsx`: `Uebersicht`, `Spenden` (`spende_id`, `datum`, `spender_person_id`, `spender`, `betrag_eur`, `zweck`, `bemerkung`, `storniert`), `Erklaerungen`, `Meta` (`format_version` = `getraenkeliste-spenden/1`, Zeitraum, Summe, Anzahl).

Die Excel entsteht **nur aus gespeicherten Daten** und lässt sich daher jederzeit identisch neu erzeugen.

### 8.3 Statistikseite (Wart, je Bereich)
- Zeitraum frei wählbar (Standard: laufender Zeitraum).
- Kacheln: Umsatz, verkaufte Einheiten, Schwundquote (Differenz ÷ verkauft), Anteil Couleur/Bund am Umsatz.
- Diagramme: Umsatz pro Woche/Monat, Top-10-Artikel, Verbrauch je Kategorie, Verteilung nach Wochentag, Schwund je Artikel.
- **Bestellhilfe:** je Artikel „reicht noch ca. X Tage“ = aktueller Bestand ÷ durchschnittlicher Tagesverbrauch der letzten 28 Tage (kein Wert, wenn kein Verbrauch).
- Keine Auswertung einzelner Personen.

## 9. Fehlerbehandlung

- **Idempotenz:** Ein Buchungsvorgang trägt seine `vorgang_id`. Kommt er erneut an (doppeltes Antippen, WLAN-Wiederholung), liefert der Server das gespeicherte Ergebnis, ohne erneut zu buchen.
- **Fehlgeschlagene Buchung:** Meldung „Nicht gebucht – bitte erneut versuchen“, der Warenkorb bleibt erhalten.
- **Transaktionen:** Buchungsvorgang, Lieferung, Storno, Auszählungsabschluss und CSV-Import laufen jeweils komplett oder gar nicht.
- **Auszählung:** höchstens ein Entwurf je Bereich; der Stichtag muss nach dem letzten abgeschlossenen liegen und darf nicht in der Zukunft liegen. Schreibzugriffe auf eingefrorene Zeiträume lehnt der Server ab (nicht nur die Oberfläche).
- **Preisänderung während des Buchens:** Gebucht wird zum Preis zum Buchungszeitpunkt; die Bestätigung zeigt den tatsächlichen Betrag.
- **Excel-Erzeugung scheitert:** Die Auszählung bleibt abgeschlossen; Fehlermeldung und „Datei neu erzeugen“.
- **Archivierte Personen/Artikel und gesperrte Tablets** werden serverseitig abgewiesen.
- Alle Meldungen auf Deutsch.

## 10. Tests

PHPUnit im Web-Container (wie beim Kassensystem).

- **Reine Klassen ohne DB:** `BestandRechner`, `AuszaehlungRechner` (Soll/Ist/Differenz, Euro-Werte), `Berechtigung` (komplette Rollenmatrix), `PinSperre`, `StornoFrist`, `ZeitraumErmittler`, `Reichweite`.
- **Excel-Export:** Datei erzeugen, mit PhpSpreadsheet wieder einlesen und prüfen: Blattnamen, Kopfzeilen exakt wie in 8.2, `Meta`-Werte, Kontrollsummen = Summe `Abrechnung`, keine Formelzellen, keine verbundenen Zellen.
- **Feature-Tests:** Buchen inkl. doppeltem Absenden; Storno innerhalb/außerhalb der Frist; eingefrorener Zeitraum; Zugriffsschutz aller Wart-, Kassenwart- und Admin-Routen; Tablet-Sitzung erreicht nur die Buchungsroute; PIN-Sperre und Login-Sperre; Freischaltcode einmalig und befristet; erzwungener Passwort-/PIN-Wechsel sperrt alle anderen Routen; Person ohne PIN am Tablet abgelehnt; Buchung auf Couleur/Bund am eigenen Gerät speichert `gebucht_von_id`; Remember-Token rotiert und wird nach Passwortwechsel ungültig.

## 11. Inbetriebnahme

1. Repo nach `/opt/getraenkeliste` klonen, `.env` anlegen, `docker compose up -d --build` (Port 8090).
2. `php spark migrate` (legt Bereiche, Couleur und Bund an), `php spark admin:anlegen`.
3. Personen per CSV importieren, Rollen vergeben.
4. Kategorien und Artikel anlegen, dann je Bereich eine **Start-Auszählung**: Ist eintragen, abschließen (`art = start`). Das legt den Anfangsbestand fest.
5. Tablet freischalten.
6. Backup-Timer einrichten, Einträge in README und CLAUDE.md (Architektur-Landkarte, Befehle) wie beim Kassensystem.

## 12. Ausbaustufen

Eine Spec, Umsetzung Stufe für Stufe; jede Stufe ist für sich nutzbar.

| Stufe | Umfang |
|---|---|
| 1 – Kern | Projektgerüst, Docker, Migrationen, Login (Passwort und Tablet/PIN), Rollen/`Berechtigung`, Buchungsseite, Meine Buchungen, Storno, Admin (Personen inkl. CSV, Kategorien, Artikel, Tablets, Einstellungen, Protokoll), Couleur/Bund. **Migrationen nur für die Tabellen, die Stufe 1 braucht** (`bereiche`, `personen`, `person_rollen`, `anmelde_tokens`, `kategorien`, `artikel`, `buchungen`, `geraete`, `freischaltcodes`, `einstellungen`, `protokoll`); `bereiche` wird mit `getraenke` (aktiv) und `kiosk` (inaktiv) angelegt. Kiosk-Stammdaten sind bis Stufe 3 in der Oberfläche ausgeblendet. Ohne Auszählungen gibt es in Stufe 1 genau einen Zeitraum ab `inbetriebnahme_at` |
| 2 – Getränkewart | Bestand, Lieferungen, Schwund/Korrekturen, Buchungsverwaltung, Auszählung inkl. Einfrieren, Excel-Export der Auszählung, Erinnerung, Backup |
| 3 – Ausbau | Statistikseite und Bestellhilfe, Spenden-Liste und Spenden-Export, Kassenwart-Bereich, Freischaltung des Bereichs `kiosk` mit Kioskwart |

Das Datenmodell enthält von Anfang an Bereiche, sodass Stufe 3 den Fuxenkiosk nur noch freischaltet und keinen Umbau braucht.

Das Backup (Abschnitt 3) wurde aus Stufe 2 nach Stufe 1 vorgezogen, damit die App schon mit Stufe 1 auf dem Pi betrieben werden kann (`scripts/backup.sh`, `scripts/restore.sh`, systemd-Timer, `docs/BACKUP.md`, `docs/DEPLOY-PI.md`).
