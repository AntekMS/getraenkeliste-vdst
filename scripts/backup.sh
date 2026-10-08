#!/usr/bin/env bash
#
# Tägliches Backup der VDSt-Getränkeliste (Docker-Setup), Muster wie beim Kassensystem:
#   1. MySQL-Dump aus dem DB-Container (mysqldump läuft dort, nicht im Web-Container)
#   2. Archiv der gespeicherten Exporte (writable/exporte/, Bind-Mount → direkt vom Host)
#   3. Archiv der Artikelbilder (writable/artikelbilder/)
#   4. Archiv der Konfiguration (.env, ggf. docker-compose.override.yml) – enthält Geheimnisse!
#   5. Monats-Promotion: erste Sicherung des Monats wird nach monthly/ kopiert
#   6. Retention: daily 30 Tage, monthly ~12 Monate
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
[ -d "$APP_DIR/writable/artikelbilder" ] || fehler "Bild-Verzeichnis $APP_DIR/writable/artikelbilder fehlt"
[ -f "$APP_DIR/.env" ] || [ -z "$BACKUP_MOUNT" ] || fehler "$APP_DIR/.env fehlt"

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/monthly"

# Alles wird zuerst als *.tmp geschrieben und geprüft; erst wenn ALLE Teile in Ordnung
# sind, ersetzt mv die Dateien des Tages. Ein fehlgeschlagener Lauf hinterlässt so keine
# halben Dateien und zerstört kein früheres, gutes Backup desselben Tages.
DB_DUMP="$BACKUP_DIR/daily/db_$HEUTE.sql.gz"
EXPORTE_ARCHIV="$BACKUP_DIR/daily/exporte_$HEUTE.tar.gz"
BILDER_ARCHIV="$BACKUP_DIR/daily/artikelbilder_$HEUTE.tar.gz"
KONFIG_ARCHIV="$BACKUP_DIR/daily/konfig_$HEUTE.tar.gz"

aufraeumen() {
    rm -f "$BACKUP_DIR"/daily/*.tmp "$BACKUP_DIR"/monthly/*.tmp
}
trap aufraeumen EXIT

# Kopie über *.tmp + mv: das Ziel ist nie halb geschrieben.
kopiere() {
    cp "$1" "$2.tmp"
    mv -f "$2.tmp" "$2"
}

# ---- 1. MySQL-Dump ----------------------------------------------------------
# --no-tablespaces ist Absicht: ohne das bräuchte getraenkeuser auf MySQL 8 das
# PROCESS-Privileg. MYSQL_PWD vermeidet das Passwort in der Prozessliste.
docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
    mysqldump --single-transaction --no-tablespaces --routines --triggers \
    -u "$DB_USER" "$DB_NAME" | gzip > "$DB_DUMP.tmp" \
    || fehler "mysqldump für '$DB_NAME' fehlgeschlagen"

[ -s "$DB_DUMP.tmp" ] || fehler "DB-Dump ist leer"
gzip -t "$DB_DUMP.tmp" || fehler "DB-Dump ist kein gültiges gzip"
gzip -dc "$DB_DUMP.tmp" | tail -1 | grep -q 'Dump completed' \
    || fehler "DB-Dump ist unvollständig (kein 'Dump completed')"

# ---- 2. Gespeicherte Exporte (darf leer sein) -------------------------------
tar -czf "$EXPORTE_ARCHIV.tmp" -C "$APP_DIR/writable" exporte \
    || fehler "Export-Archiv konnte nicht erstellt werden"
gzip -t "$EXPORTE_ARCHIV.tmp" || fehler "Export-Archiv ist kein gültiges gzip"

# ---- 3. Artikelbilder (darf leer sein) --------------------------------------
tar -czf "$BILDER_ARCHIV.tmp" -C "$APP_DIR/writable" artikelbilder \
    || fehler "Bild-Archiv konnte nicht erstellt werden"
gzip -t "$BILDER_ARCHIV.tmp" || fehler "Bild-Archiv ist kein gültiges gzip"

# ---- 4. Konfiguration -------------------------------------------------------
KONFIG_DATEIEN=()
for datei in .env docker-compose.override.yml; do
    if [ -f "$APP_DIR/$datei" ]; then
        KONFIG_DATEIEN+=("$datei")
    fi
done

if [ "${#KONFIG_DATEIEN[@]}" -gt 0 ]; then
    tar -czf "$KONFIG_ARCHIV.tmp" -C "$APP_DIR" "${KONFIG_DATEIEN[@]}" \
        || fehler "Konfig-Archiv konnte nicht erstellt werden"
    gzip -t "$KONFIG_ARCHIV.tmp" || fehler "Konfig-Archiv ist kein gültiges gzip"
fi

# ---- Alles geprüft: Dateien des Tages ersetzen ------------------------------
mv -f "$DB_DUMP.tmp" "$DB_DUMP"
mv -f "$EXPORTE_ARCHIV.tmp" "$EXPORTE_ARCHIV"
mv -f "$BILDER_ARCHIV.tmp" "$BILDER_ARCHIV"
if [ "${#KONFIG_DATEIEN[@]}" -gt 0 ]; then
    mv -f "$KONFIG_ARCHIV.tmp" "$KONFIG_ARCHIV"
    KONFIG_INFO="$(du -h "$KONFIG_ARCHIV" | cut -f1) Konfig"
else
    # Nur ohne Mount-Prüfung (lokaler Test) erreichbar, siehe Vorprüfungen.
    KONFIG_INFO="keine Konfig (.env fehlt)"
    echo "WARNUNG: keine .env in $APP_DIR – Konfig-Archiv übersprungen" >&2
fi

# ---- 5. Monats-Promotion (selbstheilend: greift beim ersten Lauf im Monat) --
if [ ! -f "$BACKUP_DIR/monthly/db_$MONAT.sql.gz" ]; then
    kopiere "$EXPORTE_ARCHIV" "$BACKUP_DIR/monthly/exporte_$MONAT.tar.gz"
    kopiere "$BILDER_ARCHIV" "$BACKUP_DIR/monthly/artikelbilder_$MONAT.tar.gz"
    if [ "${#KONFIG_DATEIEN[@]}" -gt 0 ]; then
        kopiere "$KONFIG_ARCHIV" "$BACKUP_DIR/monthly/konfig_$MONAT.tar.gz"
    fi
    # DB-Dump zuletzt: seine Existenz markiert die Promotion als erledigt.
    kopiere "$DB_DUMP" "$BACKUP_DIR/monthly/db_$MONAT.sql.gz"
    echo "Monats-Backup $MONAT angelegt"
fi

# ---- 6. Retention -----------------------------------------------------------
find "$BACKUP_DIR/daily" -name '*.gz' -mtime +"$RETENTION_DAILY" -delete
find "$BACKUP_DIR/monthly" -name '*.gz' -mtime +"$RETENTION_MONTHLY_TAGE" -delete

# ---- Zusammenfassung --------------------------------------------------------
echo "Backup OK ($HEUTE): $(du -h "$DB_DUMP" | cut -f1) DB, $(du -h "$EXPORTE_ARCHIV" | cut -f1) Exporte, $(du -h "$BILDER_ARCHIV" | cut -f1) Bilder, $KONFIG_INFO" \
     "| daily: $(find "$BACKUP_DIR/daily" -name '*.gz' | wc -l | tr -d ' ') Dateien," \
     "monthly: $(find "$BACKUP_DIR/monthly" -name '*.gz' | wc -l | tr -d ' ') Dateien"
