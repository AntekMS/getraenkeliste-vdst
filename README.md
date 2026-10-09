# Getränkeliste VDSt

Getränkeliste des Vereins deutscher Studenten zu Erlangen (CodeIgniter 4.7, PHP 8.3, MySQL 8).
Mitglieder buchen ihren Verbrauch am Kühlschrank-Tablet oder am eigenen Handy. Die App zählt nur
den Verbrauch; Rechnung und Zahlung bleiben im Kassensystem.

## Lokal starten

```bash
docker compose up -d --build
docker exec getraenkeliste-web composer install
docker exec getraenkeliste-web vendor/bin/phpunit
```

- App: http://localhost:8090/
- phpMyAdmin: http://localhost:8091/ (**meldet sich automatisch mit dem App-DB-Benutzer an, ohne Passwortabfrage**)
- Zugangsdaten und Basis-URL lassen sich über eine `.env` neben der `docker-compose.yml`
  überschreiben (`DB_PASS`, `MYSQL_ROOT_PASSWORD`, `CI_ENVIRONMENT`); Vorlage für die
  App-Konfiguration: Datei `env`.

## `.env` für den Betrieb (Raspberry Pi / HTTPS)

Vollständige, getestete Anleitung für den Pi (inkl. Passwörtern und Backup): **`docs/DEPLOY-PI.md`**.
Die Datei `.env` im Projektverzeichnis (nicht versioniert) setzt mindestens:

```ini
CI_ENVIRONMENT=production
DB_PASS=<starkes Passwort>
MYSQL_ROOT_PASSWORD=<starkes Passwort>
app.baseURL = 'http://<pi-hostname>:8090/'
# nur hinter HTTPS (Reverse-Proxy/Tunnel), dann auch app.baseURL = 'https://…/':
# cookie.secure = true
```

`docker-compose.yml` setzt `CI_ENVIRONMENT` standardmäßig auf `development` (lokale Entwicklung:
Fehlerseiten mit Stacktrace, Debug-Toolbar). Auf dem Pi **muss** die `.env` `CI_ENVIRONMENT=production`
setzen (ohne Leerzeichen um `=`, weil Docker Compose dieselbe Datei für die Variablen-Ersetzung liest);
sonst sieht jeder Besucher bei Fehlern Stacktraces mit Pfaden und SQL. Nach einer Änderung
`docker compose up -d` (der Container übernimmt die Umgebung nur beim Neuerstellen).

Ohne `app.baseURL` zeigen Redirects auf `http://localhost:8090/`. Ohne `cookie.secure = true` laufen
Sitzungs-, „Angemeldet bleiben“- und Geräte-Cookie auch über unverschlüsselte Verbindungen; **mit**
`cookie.secure = true` über reines HTTP funktioniert dagegen kein Login (der Browser schickt die Cookies
nicht zurück). Docker Compose ignoriert die CI-Zeilen mit Punkten (geprüft mit `docker compose config`).

### phpMyAdmin abschalten

phpMyAdmin hat keinen Login und darf auf dem Pi nicht erreichbar sein. Lokale, nicht versionierte
`docker-compose.override.yml` neben der `docker-compose.yml` anlegen:

```yaml
services:
  getraenkeliste-phpmyadmin:
    profiles: ["nie"]
```

Danach `docker compose up -d` (ein bereits laufender Container wird mit
`docker compose rm -sf getraenkeliste-phpmyadmin` entfernt).

## Inbetriebnahme

Auf dem Raspberry Pi Schritt für Schritt: `docs/DEPLOY-PI.md`.

1. Repo nach `/opt/getraenkeliste` klonen, `.env` anlegen (siehe oben; Rechte `chown "$USER":33`,
   `chmod 640` – Apache im Container muss sie lesen), `docker compose up -d --build --force-recreate`
   (App auf Port 8090), `docker exec getraenkeliste-web composer install --no-dev --optimize-autoloader`.
2. `docker exec -u www-data getraenkeliste-web php spark migrate` (legt Bereiche, Couleur/Bund und Einstellungen an),
   dann `docker exec -it -u www-data getraenkeliste-web php spark admin:anlegen` (erster Admin; die PIN wird beim ersten
   Login abgefragt).
3. Anmelden, Personen per CSV importieren (Verwaltung → Personen → CSV-Import) und Rollen vergeben;
   die ausgegebenen Einmal-Passwörter drucken oder verteilen (sie werden nur einmal angezeigt).
   Danach Kategorien und Artikel unter „Getränke & Preise“ anlegen.
4. Start-Auszählung je Bereich: Wart → Auszählung, Ist eintragen, Abschließen (legt den Startbestand fest;
   die Excel-Datei landet in `writable/exporte/` und ist im Backup enthalten).
5. Tablet freischalten: Verwaltung → Tablets → Freischaltcode erzeugen (8 Ziffern, 15 Minuten gültig),
   am Tablet `/tablet/freischalten` öffnen, Code und Gerätename eingeben.
6. Backup-Timer einrichten (täglich 02:30 auf den USB-Stick `/mnt/kasse-backup/getraenkeliste/`):
   `docs/DEPLOY-PI.md`, Inhalt und Wiederherstellung: `docs/BACKUP.md`.

Der erste Abrechnungszeitraum beginnt am Inbetriebnahme-Zeitpunkt (Einstellungen, nicht änderbar);
jede abgeschlossene Auszählung beendet einen Zeitraum und friert ihn ein.

## Getränkewart (Stufe 2)

Menü „Getränkewart“ (Rolle `getraenkewart` bzw. Admin):

- **Einkauf** (Bestand mit Ampel, Bestellvorschlag mit Druckansicht, Reichweite, Anteile Mitglieder/Couleur/Bund, Verbrauchsverlauf als Diagramm), **Lieferung** erfassen (Kisten × Gebinde + Stück,
  optional Einkaufspreis), **Schwund/Korrektur** als Bestandsbewegung mit Pflichtbemerkung; die Seite **Schwund** wertet den
  Schwund je Auszählungszeitraum aus (erfasst, unerklärt, Quote, Top-Artikel, Diagramm).
- **Buchungen** des laufenden Zeitraums einsehen, stornieren (Grund Pflicht, keine Frist) und Korrekturbuchungen anlegen.
  Eine Korrektur ändert standardmäßig nur den Betrag des Kontos; mit dem Haken „Ware wurde tatsächlich entnommen bzw.
  zurückgegeben“ zählt sie auch für Bestand und Auszählung.
- **Auszählung**: Ist-Werte eintragen (Entwurf speichern, später abschließen). Der Abschluss legt den Stichtag fest, friert
  den Zeitraum ein und erzeugt eine Excel-Datei fürs Kassensystem; **Auszählungen** listet alle Abschlüsse mit Download
  und „Datei neu erzeugen“.
- Ein Banner erinnert an die Auszählung, wenn die letzte (bzw. die Inbetriebnahme) länger als „Erinnerung nach (Tagen)“
  (Einstellungen, Standard 31) zurückliegt.

## Backup

- `scripts/backup.sh` — DB-Dump, gespeicherte Exporte, Konfiguration; Monats-Promotion, Retention
  (lokal testen: `BACKUP_MOUNT= BACKUP_DIR=./backups ./scripts/backup.sh`)
- `scripts/restore.sh <db_dump.sql.gz> [exporte.tar.gz] [--ja]` — mit Rückfrage und Sicherheits-Dump vorher
- systemd-Units und Vorlage der Backup-Konfiguration: `deploy/`

## Bekannte Grenzen (Stufe 1)

- Keine Drosselung der Brute-Force-Angriffe auf den Freischaltcode (10^8 Codes, 15 Minuten gültig) und auf den
  Login über die kontobezogene Sperre hinaus (nach mehreren Fehlversuchen wird nur das betroffene Konto
  gesperrt; Angriffe über viele Konten oder auf den Code werden nicht gebremst). Betrieb daher nur im
  Vereinsnetz bzw. hinter dem Reverse-Proxy mit eigener Begrenzung.
- Gesperrte Tablets lassen sich nicht wieder entsperren; stattdessen neu freischalten.
- Die App verschickt keine E-Mails; Passwörter werden vom Admin zurückgesetzt (Einmal-Passwort).

## Dokumentation

Design-Spec und Implementierungspläne: `docs/superpowers/`. Hinweise für die Entwicklung: `CLAUDE.md`.
Betrieb: `docs/DEPLOY-PI.md` (Raspberry Pi), `docs/BACKUP.md` (Backup & Wiederherstellung).
