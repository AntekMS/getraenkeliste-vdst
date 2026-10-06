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

Die Datei `.env` im Projektverzeichnis (nicht versioniert) **muss** setzen:

```
CI_ENVIRONMENT=production
app.baseURL = 'https://<hostname>/'
cookie.secure = true
```

`docker-compose.yml` setzt `CI_ENVIRONMENT` standardmäßig auf `development` (lokale Entwicklung:
Fehlerseiten mit Stacktrace, Debug-Toolbar). Auf dem Pi **muss** die `.env` `CI_ENVIRONMENT=production`
setzen (ohne Leerzeichen um `=`, weil Docker Compose dieselbe Datei für die Variablen-Ersetzung liest);
sonst sieht jeder Besucher bei Fehlern Stacktraces mit Pfaden und SQL. Nach einer Änderung
`docker compose up -d` (der Container übernimmt die Umgebung nur beim Neuerstellen).

Ohne `app.baseURL` zeigen Redirects auf `http://localhost:8090/`. Ohne `cookie.secure = true` laufen
Sitzungs-, „Angemeldet bleiben“- und Geräte-Cookie auch über unverschlüsselte Verbindungen.

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

1. Repo nach `/opt/getraenkeliste` klonen, `.env` anlegen (siehe oben), `docker compose up -d --build`
   (App auf Port 8090), `docker exec getraenkeliste-web composer install`.
2. `docker exec getraenkeliste-web php spark migrate` (legt Bereiche, Couleur/Bund und Einstellungen an),
   dann `docker exec -it getraenkeliste-web php spark admin:anlegen` (erster Admin; die PIN wird beim ersten
   Login abgefragt).
3. Anmelden, Personen per CSV importieren (Verwaltung → Personen → CSV-Import) und Rollen vergeben;
   die ausgegebenen Einmal-Passwörter drucken oder verteilen (sie werden nur einmal angezeigt).
   Danach Kategorien und Artikel unter „Getränke & Preise“ anlegen.
4. *(Stufe 2)* Start-Auszählung je Bereich.
5. Tablet freischalten: Verwaltung → Tablets → Freischaltcode erzeugen (8 Ziffern, 15 Minuten gültig),
   am Tablet `/tablet/freischalten` öffnen, Code und Gerätename eingeben.
6. *(Stufe 2)* Backup-Timer einrichten.

In Stufe 1 gibt es genau einen Abrechnungszeitraum ab dem Inbetriebnahme-Zeitpunkt (Einstellungen,
nicht änderbar). Start-Auszählung und Backup folgen in Stufe 2.

## Bekannte Grenzen (Stufe 1)

- Keine Drosselung der Brute-Force-Angriffe auf den Freischaltcode (10^8 Codes, 15 Minuten gültig) und auf den
  Login über die kontobezogene Sperre hinaus (nach mehreren Fehlversuchen wird nur das betroffene Konto
  gesperrt; Angriffe über viele Konten oder auf den Code werden nicht gebremst). Betrieb daher nur im
  Vereinsnetz bzw. hinter dem Reverse-Proxy mit eigener Begrenzung.
- Gesperrte Tablets lassen sich nicht wieder entsperren; stattdessen neu freischalten.
- Die App verschickt keine E-Mails; Passwörter werden vom Admin zurückgesetzt (Einmal-Passwort).

## Dokumentation

Design-Spec und Implementierungspläne: `docs/superpowers/`. Hinweise für die Entwicklung: `CLAUDE.md`.
