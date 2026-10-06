#!/usr/bin/env bash
#
# Tägliches Backup der VDSt-Getränkeliste (Docker-Setup), Muster wie beim Kassensystem:
#   1. MySQL-Dump aus dem DB-Container (mysqldump läuft dort, nicht im Web-Container)
#   2. Archiv der gespeicherten Exporte (writable/exporte/, Bind-Mount → direkt vom Host)
#   3. Archiv der Konfiguration (.env, ggf. docker-compose.override.yml) – enthält Geheimnisse!
#   4. Monats-Promotion: erste Sicherung des Monats wird nach monthly/ kopiert
#   5. Retention: daily 30 Tage, monthly ~12 Monate
#
# Läuft auf dem Host (kein Host-PHP nötig), auf dem Pi per systemd-Timer
# (deploy/systemd/getraenkeliste-backup.*). Doku: docs/BACKUP.md
#
# Lokaler Test ohne USB-Stick:
#   BACKUP_MOUNT= BACKUP_DIR=./backups ./scripts/backup.sh

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

APP_DIR="${APP_DIR:-$REPO_DIR}"
BACKUP_DIR="${BACKUP_DIR:-/mnt/kasse-backup/getraenkeliste}"
# Bewusst "-" statt ":-": ein leer gesetztes BACKUP_MOUNT schaltet die Mount-Prüfung ab.
BACKUP_MOUNT="${BACKUP_MOUNT-/mnt/kasse-backup}"
DB_CONTAINER="${DB_CONTAINER:-getraenkeliste-db}"
DB_NAME="${DB_NAME:-getraenkeliste}"
DB_USER="${DB_USER:-getraenkeuser}"
RETENTION_DAILY="${RETENTION_DAILY:-30}"
RETENTION_MONTHLY_TAGE="${RETENTION_MONTHLY_TAGE:-366}"

HEUTE="$(date +%Y-%m-%d)"
MONAT="$(date +%Y-%m)"

# Konfigurationsarchiv enthält Passwörter → nur für den Eigentümer lesbar.
umask 077

fehler() {
    echo "FEHLER: $*" >&2
    exit 1
}

# ---- Vorprüfungen -----------------------------------------------------------
if [ -n "$BACKUP_MOUNT" ]; then
    # Ohne gemounteten Stick würde sonst still auf die SD-Karte geschrieben.
    command -v mountpoint >/dev/null || fehler "mountpoint nicht gefunden (util-linux)"
    mountpoint -q "$BACKUP_MOUNT" || fehler "$BACKUP_MOUNT ist nicht gemountet (USB-Stick fehlt?)"
    [ -n "${DB_PASS:-}" ] || fehler "DB_PASS ist nicht gesetzt (Produktion: /etc/getraenkeliste-backup.env)"
else
    DB_PASS="${DB_PASS:-getraenkepass123}"
fi

command -v docker >/dev/null || fehler "docker nicht gefunden"
# grep ohne -q: liest die Liste zu Ende, sonst könnte pipefail an SIGPIPE scheitern.
docker ps --format '{{.Names}}' | grep -x "$DB_CONTAINER" >/dev/null \
    || fehler "DB-Container '$DB_CONTAINER' läuft nicht"
[ -d "$APP_DIR/writable/exporte" ] || fehler "Export-Verzeichnis $APP_DIR/writable/exporte fehlt"
[ -f "$APP_DIR/.env" ] || [ -z "$BACKUP_MOUNT" ] || fehler "$APP_DIR/.env fehlt"

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/monthly"

# ---- 1. MySQL-Dump ----------------------------------------------------------
# --no-tablespaces ist Absicht: ohne das bräuchte getraenkeuser auf MySQL 8 das
# PROCESS-Privileg. MYSQL_PWD vermeidet das Passwort in der Prozessliste.
DB_DUMP="$BACKUP_DIR/daily/db_$HEUTE.sql.gz"

docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
    mysqldump --single-transaction --no-tablespaces --routines --triggers \
    -u "$DB_USER" "$DB_NAME" | gzip > "$DB_DUMP" \
    || { rm -f "$DB_DUMP"; fehler "mysqldump für '$DB_NAME' fehlgeschlagen"; }

[ -s "$DB_DUMP" ] || fehler "DB-Dump $DB_DUMP ist leer"
gzip -t "$DB_DUMP" || fehler "DB-Dump $DB_DUMP ist kein gültiges gzip"
gzip -dc "$DB_DUMP" | tail -1 | grep -q 'Dump completed' \
    || fehler "DB-Dump $DB_DUMP ist unvollständig (kein 'Dump completed')"

# ---- 2. Gespeicherte Exporte (darf leer sein) -------------------------------
EXPORTE_ARCHIV="$BACKUP_DIR/daily/exporte_$HEUTE.tar.gz"

tar -czf "$EXPORTE_ARCHIV" -C "$APP_DIR/writable" exporte
gzip -t "$EXPORTE_ARCHIV" || fehler "Export-Archiv $EXPORTE_ARCHIV ist kein gültiges gzip"

# ---- 3. Konfiguration -------------------------------------------------------
KONFIG_ARCHIV="$BACKUP_DIR/daily/konfig_$HEUTE.tar.gz"
KONFIG_DATEIEN=()
for datei in .env docker-compose.override.yml; do
    if [ -f "$APP_DIR/$datei" ]; then
        KONFIG_DATEIEN+=("$datei")
    fi
done

if [ "${#KONFIG_DATEIEN[@]}" -gt 0 ]; then
    tar -czf "$KONFIG_ARCHIV" -C "$APP_DIR" "${KONFIG_DATEIEN[@]}"
    gzip -t "$KONFIG_ARCHIV" || fehler "Konfig-Archiv $KONFIG_ARCHIV ist kein gültiges gzip"
    KONFIG_INFO="$(du -h "$KONFIG_ARCHIV" | cut -f1) Konfig"
else
    # Nur ohne Mount-Prüfung (lokaler Test) erreichbar, siehe Vorprüfungen.
    rm -f "$KONFIG_ARCHIV"
    KONFIG_INFO="keine Konfig (.env fehlt)"
    echo "WARNUNG: keine .env in $APP_DIR – Konfig-Archiv übersprungen" >&2
fi

# ---- 4. Monats-Promotion (selbstheilend: greift beim ersten Lauf im Monat) --
if [ ! -f "$BACKUP_DIR/monthly/db_$MONAT.sql.gz" ]; then
    cp "$EXPORTE_ARCHIV" "$BACKUP_DIR/monthly/exporte_$MONAT.tar.gz"
    if [ -f "$KONFIG_ARCHIV" ]; then
        cp "$KONFIG_ARCHIV" "$BACKUP_DIR/monthly/konfig_$MONAT.tar.gz"
    fi
    # DB-Dump zuletzt: seine Existenz markiert die Promotion als erledigt.
    cp "$DB_DUMP" "$BACKUP_DIR/monthly/db_$MONAT.sql.gz"
    echo "Monats-Backup $MONAT angelegt"
fi

# ---- 5. Retention -----------------------------------------------------------
find "$BACKUP_DIR/daily" -name '*.gz' -mtime +"$RETENTION_DAILY" -delete
find "$BACKUP_DIR/monthly" -name '*.gz' -mtime +"$RETENTION_MONTHLY_TAGE" -delete

# ---- Zusammenfassung --------------------------------------------------------
echo "Backup OK ($HEUTE): $(du -h "$DB_DUMP" | cut -f1) DB, $(du -h "$EXPORTE_ARCHIV" | cut -f1) Exporte, $KONFIG_INFO" \
     "| daily: $(find "$BACKUP_DIR/daily" -name '*.gz' | wc -l | tr -d ' ') Dateien," \
     "monthly: $(find "$BACKUP_DIR/monthly" -name '*.gz' | wc -l | tr -d ' ') Dateien"
