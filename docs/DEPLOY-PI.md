# Getränkeliste auf dem Raspberry Pi

Schritt-für-Schritt-Anleitung für den Vereins-Admin: Installation auf dem Raspberry Pi 4
(arm64) neben dem Kassensystem, Backup, Updates, Wiederherstellung und Fehlersuche.

## Voraussetzungen

- Raspberry Pi 4 mit 64-Bit-Raspberry-Pi-OS (`uname -m` → `aarch64`), Zeitzone Europe/Berlin
  (`timedatectl` prüfen, sonst `sudo timedatectl set-timezone Europe/Berlin` — der Backup-Timer
  richtet sich nach der Systemzeit).
- Docker mit **Compose-Plugin** (`docker compose version` → v2.x; das alte `docker-compose` v1
  wird nicht unterstützt). Der eigene Benutzer ist in der Gruppe `docker`, sonst `sudo` vor
  die `docker`-Befehle setzen.
- Das Kassensystem läuft bereits auf Port **8080** (App) / **8081**. Die Getränkeliste belegt
  Port **8090** (phpMyAdmin 8091 wird auf dem Pi abgeschaltet, siehe unten) — keine Überschneidung.
- Der Backup-USB-Stick ist unter **`/mnt/kasse-backup`** gemountet (wie für das Kassensystem;
  `mountpoint /mnt/kasse-backup` → „is a mountpoint“).
- `git` ist installiert.

Alle Images gibt es für arm64 (geprüft mit `docker manifest inspect`): `php:8.3-apache` (Basis
des Web-Images, wird auf dem Pi gebaut) und `mysql:8.0` sind Multi-Arch inklusive
`linux/arm64`. `phpmyadmin/phpmyadmin:latest` ist derzeit **nur amd64** — auf dem Pi wird
phpMyAdmin ohnehin per Override abgeschaltet und daher nicht geladen. Wer es dort doch braucht,
nimmt im Override das offizielle Multi-Arch-Image `phpmyadmin:latest`.

## Erstinstallation

### 1. Code holen

```bash
sudo git clone https://github.com/AntekMS/getraenkeliste-vdst.git /opt/getraenkeliste
cd /opt/getraenkeliste
```

### 2. `.env` anlegen

Zwei starke Passwörter erzeugen (nur Buchstaben/Ziffern — Docker Compose würde `$` ersetzen):

```bash
openssl rand -hex 16   # für DB_PASS
openssl rand -hex 16   # für MYSQL_ROOT_PASSWORD
```

`sudo nano /opt/getraenkeliste/.env`:

```ini
# --- Docker Compose (liest diese Datei mit; ohne Leerzeichen um "=") ---
CI_ENVIRONMENT=production
DB_PASS=HIER_PASSWORT_1
MYSQL_ROOT_PASSWORD=HIER_PASSWORT_2

# --- CodeIgniter ---
# Adresse, unter der Handys und Tablet die App aufrufen (mit / am Ende):
app.baseURL = 'http://getraenke-pi:8090/'
# NUR wenn die App über HTTPS (Reverse-Proxy/Tunnel) erreicht wird, zusätzlich:
# app.baseURL = 'https://getraenke.example.org/'
# cookie.secure = true
```

```bash
sudo chown "$USER":33 /opt/getraenkeliste/.env   # 33 = www-data im Container
sudo chmod 640 /opt/getraenkeliste/.env
```

**Nicht `chmod 600` als root:** Der Projektordner ist in den Container gemountet; Apache (`www-data`,
GID 33) muss die `.env` lesen können, sonst bricht jede Seite mit „The .env file is not readable“ ab.
Docker Compose (als Benutzer in der Gruppe `docker`) liest sie als Eigentümer. Ins Image gelangt die
`.env` nicht (`.dockerignore`).

Dieses Format ist geprüft: Docker Compose liest `CI_ENVIRONMENT`, `DB_PASS` und
`MYSQL_ROOT_PASSWORD` korrekt und ignoriert die CI-Zeilen mit Punkten (`app.baseURL`,
`cookie.secure`) ohne Warnung. Wichtig:

- `CI_ENVIRONMENT=production` ist Pflicht — sonst zeigt die App bei Fehlern Stacktraces und die
  Debug-Toolbar.
- **`cookie.secure = true` nur bei HTTPS.** Bei reinem `http://…:8090` im Haus-WLAN schickt der
  Browser die Cookies sonst nicht zurück — Anmelden und Tablet funktionieren dann nicht.
- `DB_PASS` und `MYSQL_ROOT_PASSWORD` wirken nur beim **ersten** Start der Datenbank (leeres
  Volume). Später ändern geht nur per SQL (`ALTER USER`).

### 3. phpMyAdmin abschalten

phpMyAdmin hat keinen Login und darf auf dem Pi nicht laufen. `sudo nano /opt/getraenkeliste/docker-compose.override.yml`:

```yaml
services:
  getraenkeliste-phpmyadmin:
    profiles: ["nie"]
```

### 4. Container bauen und starten

```bash
cd /opt/getraenkeliste
docker compose up -d --build --force-recreate     # erster Build auf dem Pi dauert einige Minuten
docker compose ps                                 # getraenkeliste-web und -db: running
docker exec getraenkeliste-web composer install --no-dev --optimize-autoloader
```

MySQL braucht beim ersten Start etwa eine Minute (`docker logs -f getraenkeliste-db` bis
„ready for connections“).

Der Web-Container macht beim Start `writable/` (Sessions, Cache, Logs, Exporte) für Apache
(`www-data`) beschreibbar (`docker/entrypoint.sh`). Auf dem Host gehört `writable/` danach der
UID 33 — das ist so gewollt. `spark`-Befehle deshalb als `www-data` ausführen (`-u www-data`),
damit keine root-eigenen Log-/Cache-Dateien entstehen.

### 5. Datenbank und ersten Admin anlegen

```bash
docker exec -u www-data getraenkeliste-web php spark migrate
docker exec -it -u www-data getraenkeliste-web php spark admin:anlegen
```

### 6. Aufrufen

Im Haus-WLAN: `http://<pi-hostname oder IP>:8090/` (muss zu `app.baseURL` passen). Port 8090 nur
im Vereinsnetz erreichbar machen, **nicht** im Router nach außen freigeben. Weiter mit der
Inbetriebnahme aus der `README.md` (Personen-Import, Getränke & Preise, Tablet freischalten).

## Backup einrichten

Details zu Inhalt und Aufbewahrung: `docs/BACKUP.md`.

```bash
cd /opt/getraenkeliste
# Konfiguration (DB_PASS = derselbe Wert wie in .env)
sudo install -m 600 deploy/getraenkeliste-backup.env.example /etc/getraenkeliste-backup.env
sudo nano /etc/getraenkeliste-backup.env

# systemd-Units
sudo install -m 644 deploy/systemd/getraenkeliste-backup.service deploy/systemd/getraenkeliste-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now getraenkeliste-backup.timer

# Testlauf und Kontrolle
sudo systemctl start getraenkeliste-backup.service
journalctl -u getraenkeliste-backup -n 20          # letzte Zeile: "Backup OK (…)"
ls -l /mnt/kasse-backup/getraenkeliste/daily/
systemctl list-timers getraenkeliste-backup.timer  # nächster Lauf 02:30
```

Der Timer läuft täglich um 02:30 (Kassensystem 02:00). Schlägt ein Lauf fehl (Stick nicht
gemountet, DB-Container aus, Dump unvollständig), steht `FEHLER: …` im Journal und
`systemctl status getraenkeliste-backup.service` zeigt `failed`.

## Update einspielen

```bash
cd /opt/getraenkeliste
sudo systemctl start getraenkeliste-backup.service      # 1. Backup vorher
sudo git pull                                            # 2. neuen Code holen
docker compose up -d --build --force-recreate            # 3. Image neu bauen, Container neu erstellen
docker exec getraenkeliste-web composer install --no-dev --optimize-autoloader   # 4. Abhängigkeiten
docker exec -u www-data getraenkeliste-web php spark migrate                     # 5. Migrationen
```

6. Kurztest: `http://<pi>:8090/login` lädt, anmelden, Buchungsseite und Tablet prüfen.

`composer install` ist nach jedem Update nötig: `vendor/` liegt in einem Docker-Volume, das ein
neues Image nicht automatisch aktualisiert. `--force-recreate` ist Absicht: ohne erstellt Compose den
Web-Container bei einem geänderten Image nicht immer neu (lokal beobachtet), ein geänderter
Entrypoint/Dockerfile würde dann nicht wirken.

## Wiederherstellen

Runbook in `docs/BACKUP.md` („Pi neu aufsetzen / Daten zurückholen“). Kurzform für einen
Datenstand vom Stick:

```bash
cd /opt/getraenkeliste
sudo bash -c 'set -a; . /etc/getraenkeliste-backup.env; set +a; \
  ./scripts/restore.sh /mnt/kasse-backup/getraenkeliste/daily/db_JJJJ-MM-TT.sql.gz \
                       /mnt/kasse-backup/getraenkeliste/daily/exporte_JJJJ-MM-TT.tar.gz'
docker exec -u www-data getraenkeliste-web php spark migrate
```

Das Skript fragt nach („ja“; ohne Terminal nur mit `--ja`), stoppt `getraenkeliste-web` während des
Einspielens (Neustart danach automatisch, auch bei Fehlern) und sichert den aktuellen Stand vorher
nach `/mnt/kasse-backup/getraenkeliste/vor-restore/`.

## Tablet einrichten

1. Am Tablet einen Browser im **Kiosk-/Vollbildmodus** verwenden (z. B. „Fully Kiosk Browser“
   oder Chrome mit „Zum Startbildschirm hinzufügen“), Bildschirm-Timeout lang, Startseite
   `http://<pi>:8090/tablet/freischalten`.
2. In der App: Verwaltung → Tablets → Freischaltcode erzeugen (8 Ziffern, 15 Minuten gültig).
3. Am Tablet Code und Gerätenamen eingeben. Danach zeigt das Tablet die Namenskacheln.
   Das Geräte-Cookie bleibt 400 Tage gültig und verlängert sich bei jeder Nutzung; Browserdaten
   des Tablets nicht löschen (sonst neu freischalten).

## Fehlersuche

| Problem | Prüfen |
|---|---|
| Seite lädt nicht | `docker compose ps`, `docker logs getraenkeliste-web` (Apache- und PHP-Fehler) |
| „The .env file is not readable“ | `ls -l /opt/getraenkeliste/.env` → muss `<benutzer> 33` mit `-rw-r-----` sein: `sudo chown "$USER":33 .env && sudo chmod 640 .env` |
| „Whoops“/500-Fehler | `writable/logs/log-JJJJ-MM-TT.log` (`sudo tail -50 /opt/getraenkeliste/writable/logs/log-*.log`) |
| Anmelden klappt nicht, man landet immer wieder auf dem Login | `cookie.secure = true` ohne HTTPS? `app.baseURL` passt nicht zur aufgerufenen Adresse? |
| Fehler „Session“/„Cache“ nicht beschreibbar | `docker compose restart getraenkeliste-web` (Entrypoint setzt die Rechte von `writable/` neu) |
| Datenbank nicht erreichbar | `docker logs getraenkeliste-db`; `DB_PASS` in `.env` geändert, nachdem das Volume schon existierte? |
| Backup fehlgeschlagen | `journalctl -u getraenkeliste-backup -n 50`; `mountpoint /mnt/kasse-backup`; `DB_PASS` in `/etc/getraenkeliste-backup.env` |
| Stacktrace oder Debug-Toolbar sichtbar | `CI_ENVIRONMENT=production` fehlt in `.env` → ergänzen, `docker compose up -d` |
