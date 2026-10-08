# Backup & Wiederherstellung

Backup der Getränkeliste nach dem Muster des Kassensystems (Spec Abschnitt 3 „Backup“).
Die Skripte setzen das **Docker-Setup** voraus (Container `getraenkeliste-db` / `-web`) und
laufen auf dem **Host** — kein Host-PHP nötig, `mysqldump`/`mysql` werden per `docker exec`
im DB-Container ausgeführt. Einrichtung auf dem Raspberry Pi: `docs/DEPLOY-PI.md`.

## Was gesichert wird

| Datei (je Lauf in `daily/`) | Inhalt | Wie |
|---|---|---|
| `db_JJJJ-MM-TT.sql.gz` | MySQL-Datenbank `getraenkeliste` (Personen, Buchungen, Artikel, Protokoll …) | `mysqldump --single-transaction --no-tablespaces --routines --triggers`, gzip |
| `exporte_JJJJ-MM-TT.tar.gz` | gespeicherte Excel-Exporte (`writable/exporte/`, darf leer sein) | `tar -czf` vom Host (Bind-Mount) |
| `konfig_JJJJ-MM-TT.tar.gz` | `.env` und – falls vorhanden – `docker-compose.override.yml` | `tar -czf` vom Host |

**Das Konfig-Archiv enthält Geheimnisse** (DB-Passwörter aus der `.env`). Das Skript läuft mit
`umask 077`, die Dateien sind also nur für root lesbar (auf einem FAT/exFAT-Stick entscheiden die
Mount-Optionen). Stick und Archive nicht weitergeben.

`--no-tablespaces` ist Absicht: ohne die Option bräuchte `getraenkeuser` auf MySQL 8 das
PROCESS-Privileg. Jeder Dump wird geprüft: nicht leer, gültiges gzip, letzte Zeile
„Dump completed“. Bei jedem Fehler bricht das Skript mit `FEHLER: …` auf stderr und
Exit-Code ≠ 0 ab (im Journal sichtbar, Unit im Zustand `failed`). Alle Dateien entstehen zuerst
als `*.tmp`; erst wenn alle Teile geprüft sind, ersetzt `mv` die Dateien des Tages. Ein
fehlgeschlagener Lauf hinterlässt also keine halben Dateien und überschreibt kein gutes Backup
desselben Tages (`*.tmp`-Reste räumt ein `trap` weg).

## Ablage und Aufbewahrung

```
/mnt/kasse-backup/getraenkeliste/
├── daily/        db_JJJJ-MM-TT.sql.gz, exporte_JJJJ-MM-TT.tar.gz, konfig_JJJJ-MM-TT.tar.gz   (30 Tage)
├── monthly/      db_JJJJ-MM.sql.gz,    exporte_JJJJ-MM.tar.gz,    konfig_JJJJ-MM.tar.gz      (~12 Monate)
└── vor-restore/  Sicherheits-Dumps, die restore.sh vor jedem Einspielen anlegt (nicht automatisch gelöscht)
```

- Der Stick `/mnt/kasse-backup` ist derselbe wie beim Kassensystem (eigener Unterordner).
- **Mount-Prüfung:** Ist `/mnt/kasse-backup` nicht gemountet, bricht das Skript ab, statt
  unbemerkt auf die SD-Karte zu schreiben.
- **Monats-Promotion** (selbstheilend): Der erste Lauf eines Monats kopiert seine Dateien nach
  `monthly/` — egal an welchem Tag.
- **Retention:** `daily/` über `-mtime +30`, `monthly/` über `-mtime +366` (approximativ,
  kurzzeitig können 13 Monats-Backups liegen).
- Zeitplan: täglich **02:30** per systemd-Timer (Kassensystem 02:00 — versetzt), verpasste Läufe
  werden nach dem Hochfahren nachgeholt (`Persistent=true`).

## Konfiguration

Per Umgebungsvariablen; auf dem Pi in `/etc/getraenkeliste-backup.env`
(Vorlage `deploy/getraenkeliste-backup.env.example`, `install -m 600`). Eine eigene Datei statt der
App-`.env`, weil diese CI-Schlüssel mit Punkten enthält (`app.baseURL …`).

| Variable | Default | Hinweis |
|---|---|---|
| `DB_PASS` | — | **Pflicht**, sobald `BACKUP_MOUNT` gesetzt ist (Wert wie `DB_PASS` in der `.env`). Nur bei leerem `BACKUP_MOUNT` Default `getraenkepass123` |
| `BACKUP_DIR` | `/mnt/kasse-backup/getraenkeliste` | Zielordner |
| `BACKUP_MOUNT` | `/mnt/kasse-backup` | Muss ein Mountpoint sein; **leer** = keine Prüfung (lokale Tests) |
| `DB_CONTAINER` | `getraenkeliste-db` | |
| `DB_NAME` | `getraenkeliste` | Bei `restore.sh` die Ziel-DB |
| `DB_USER` | `getraenkeuser` | |
| `RETENTION_DAILY` | `30` | Tage |
| `RETENTION_MONTHLY_TAGE` | `366` | Tage |
| `APP_DIR` | Repo des Skripts | Projektverzeichnis (`.env`, `writable/exporte/`); nur für Tests umlenken |
| `WEB_CONTAINER` | `getraenkeliste-web` | nur `restore.sh`: wird während des Einspielens gestoppt; leer = laufen lassen |

## Manuell ausführen

```bash
# Pi (wie der Timer):
sudo systemctl start getraenkeliste-backup.service
journalctl -u getraenkeliste-backup -n 20

# Lokal (Entwicklung, ohne Stick):
BACKUP_MOUNT= BACKUP_DIR=./backups ./scripts/backup.sh
```

`backups/` ist in `.gitignore`. Lokal ohne `.env` wird das Konfig-Archiv mit einer Warnung
übersprungen; mit gesetztem `BACKUP_MOUNT` (Produktion) ist eine fehlende `.env` ein Fehler.

## Wiederherstellung

```
./scripts/restore.sh <db_dump.sql.gz> [exporte.tar.gz] [--ja]
```

1. prüft die Dateien (vorhanden, gültiges gzip, Dump endet mit „Dump completed“, Export-Archiv
   enthält nur `exporte/`),
2. fragt „Datenbank <name> wird überschrieben. Fortfahren? (ja/nein)“ (`--ja` überspringt; ohne
   Terminal, z. B. per Skript/Pipe, bricht es ohne `--ja` mit Fehlermeldung ab),
3. stoppt den Web-Container `getraenkeliste-web` (keine Buchungen während des Einspielens;
   `WEB_CONTAINER=` lässt ihn laufen) und startet ihn am Ende wieder — per `trap` auch bei Fehlern.
   Beim Start setzt der Entrypoint die Rechte von `writable/` (auch der eingespielten Exporte),
4. legt einen **Sicherheits-Dump** des aktuellen Stands nach `BACKUP_DIR/vor-restore/` (bei
   Export-Archiv auch die aktuellen Exporte),
5. spielt den Dump per `docker exec -i getraenkeliste-db mysql` ein,
6. ersetzt optional den Inhalt von `writable/exporte/`.

Die **Konfiguration wird bewusst nicht** automatisch zurückgespielt (Geheimnisse, und eine neue
Installation hat oft andere Passwörter/Hostnamen) — bei Bedarf von Hand auspacken.
Mit `DB_NAME=<andere_db>` lässt sich in eine andere Datenbank einspielen (Probe-Restore).

### Runbook: Pi neu aufsetzen / Daten zurückholen

1. Repo klonen wie in `docs/DEPLOY-PI.md` (Erstinstallation, Schritt 1).
2. Alte Konfiguration auspacken und übernehmen — **vor** dem ersten `docker compose up`, denn
   `DB_PASS`/`MYSQL_ROOT_PASSWORD` wirken nur beim ersten Start des MySQL-Volumes:
   ```bash
   sudo mkdir -m 700 /root/konfig-alt
   sudo tar -xzf /mnt/kasse-backup/getraenkeliste/daily/konfig_2026-10-06.tar.gz -C /root/konfig-alt
   sudo cp /root/konfig-alt/.env /opt/getraenkeliste/.env      # ggf. auch docker-compose.override.yml
   sudo chown "$USER":33 /opt/getraenkeliste/.env && sudo chmod 640 /opt/getraenkeliste/.env
   sudo rm -rf /root/konfig-alt
   ```
   (`640` mit Gruppe 33 ist Pflicht: Apache im Container muss die `.env` lesen.)
   Danach weiter nach `docs/DEPLOY-PI.md` bis einschließlich `docker compose up -d --build --force-recreate` und
   `composer install` (noch **kein** `migrate`/`admin:anlegen`) sowie Backup-Konfiguration
   `/etc/getraenkeliste-backup.env`.
3. Daten einspielen (Passwort aus der Backup-Konfiguration laden):
   ```bash
   cd /opt/getraenkeliste
   sudo bash -c 'set -a; . /etc/getraenkeliste-backup.env; set +a; \
     ./scripts/restore.sh /mnt/kasse-backup/getraenkeliste/daily/db_2026-10-06.sql.gz \
                          /mnt/kasse-backup/getraenkeliste/daily/exporte_2026-10-06.tar.gz'
   ```
4. Migrationen nachziehen (falls der Dump älter als der Code ist):
   ```bash
   docker exec -u www-data getraenkeliste-web php spark migrate
   ```
5. Kurztest: `http://<pi>:8090/login` → anmelden → „Meine Buchungen“.

Ist der Stick nicht gemountet (Backups z. B. auf einen Laptop kopiert):
`BACKUP_MOUNT= BACKUP_DIR=/pfad/zum/ordner DB_PASS=… ./scripts/restore.sh …`.

### Probe-Restore ohne Produktivdaten anzufassen

Wegwerf-DB anlegen, einspielen (Web-Container läuft weiter: `WEB_CONTAINER=`), prüfen, wieder
entfernen. Das Passwort kommt aus der Backup-Konfiguration, nicht von der Kommandozeile:

```bash
cd /opt/getraenkeliste
docker exec getraenkeliste-db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE getraenkeliste_restoretest; GRANT ALL ON getraenkeliste_restoretest.* TO \"getraenkeuser\"@\"%\";"'
sudo bash -c 'set -a; . /etc/getraenkeliste-backup.env; set +a; \
  BACKUP_MOUNT= BACKUP_DIR=/root/restore-probe WEB_CONTAINER= DB_NAME=getraenkeliste_restoretest \
  ./scripts/restore.sh /mnt/kasse-backup/getraenkeliste/daily/db_2026-10-06.sql.gz --ja && \
  docker exec -e MYSQL_PWD="$DB_PASS" getraenkeliste-db mysql -ugetraenkeuser getraenkeliste_restoretest \
    -e "SHOW TABLES; SELECT COUNT(*) FROM buchungen;"'
docker exec getraenkeliste-db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE getraenkeliste_restoretest; REVOKE ALL PRIVILEGES ON getraenkeliste_restoretest.* FROM \"getraenkeuser\"@\"%\";"'
sudo rm -rf /root/restore-probe
```

### Einzelnen Export zurückholen

```bash
tar -tzf /mnt/kasse-backup/getraenkeliste/daily/exporte_2026-10-06.tar.gz
tar -xzf /mnt/kasse-backup/getraenkeliste/daily/exporte_2026-10-06.tar.gz -C /tmp exporte/<datei>.xlsx
```

**RPO/RTO:** maximal ein Tag Datenverlust (tägliche Sicherung); Wiederherstellung in deutlich
unter einer Stunde.

**Wichtig:** Der Stick schützt vor SD-Karten-Defekt und Bedienfehlern, nicht vor Diebstahl/Brand
des Pi samt Stick — gelegentlich eine Kopie des Ordners an einen anderen Ort bringen.
