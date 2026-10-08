#!/usr/bin/env bash
#
# Wiederherstellung der VDSt-Getränkeliste aus einem Backup (Docker-Setup).
#
#   ./scripts/restore.sh <db_dump.sql.gz> [exporte_<datum>.tar.gz] [artikelbilder_<datum>.tar.gz] [--ja]
#
# Archive werden am Dateinamen erkannt: artikelbilder_*.tar.gz = Artikelbilder, jedes andere *.tar.gz = Exporte.
#
# Ablauf:
#   1. Prüfungen (Dateien vorhanden, gültiges gzip, Dump vollständig, Archive enthalten nur exporte/ bzw. artikelbilder/)
#   2. Sicherheitsabfrage (Eingabe "ja"; --ja überspringt sie; ohne Terminal ist --ja Pflicht)
#   3. Web-Container stoppen (WEB_CONTAINER, leer = nicht stoppen); Neustart am Ende, auch bei Fehlern
#   4. Sicherheits-Dump der AKTUELLEN Datenbank (und ggf. Exporte/Bilder) nach BACKUP_DIR/vor-restore/
#   5. DB-Dump einspielen
#   6. Optional: writable/exporte/ wiederherstellen
#   7. Optional: writable/artikelbilder/ wiederherstellen
#
# Die Konfiguration (konfig_*.tar.gz: .env, Override) wird bewusst NICHT
# automatisch zurückgespielt. Ziel-DB über DB_NAME umlenkbar. Doku: docs/BACKUP.md

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

APP_DIR="${APP_DIR:-$REPO_DIR}"
BACKUP_DIR="${BACKUP_DIR:-/mnt/kasse-backup/getraenkeliste}"
# Bewusst "-" statt ":-": ein leer gesetztes BACKUP_MOUNT schaltet die Mount-Prüfung ab.
BACKUP_MOUNT="${BACKUP_MOUNT-/mnt/kasse-backup}"
DB_CONTAINER="${DB_CONTAINER:-getraenkeliste-db}"
DB_NAME="${DB_NAME:-getraenkeliste}"
DB_USER="${DB_USER:-getraenkeuser}"
# Wird während des Einspielens gestoppt (keine Buchungen in eine halbe DB); leer = nicht stoppen.
WEB_CONTAINER="${WEB_CONTAINER-getraenkeliste-web}"

# Sicherheits-Dumps enthalten personenbezogene Daten.
umask 077

fehler() {
    echo "FEHLER: $*" >&2
    exit 1
}

AUFRUF="Aufruf: $0 <db_dump.sql.gz> [exporte_<datum>.tar.gz] [artikelbilder_<datum>.tar.gz] [--ja]"

DB_DUMP=""
EXPORTE_ARCHIV=""
BILDER_ARCHIV=""
JA=0
for arg in "$@"; do
    case "$arg" in
        --ja) JA=1 ;;
        *.sql.gz) DB_DUMP="$arg" ;;
        artikelbilder_*.tar.gz | */artikelbilder_*.tar.gz) BILDER_ARCHIV="$arg" ;;
        *.tar.gz) EXPORTE_ARCHIV="$arg" ;;
        *) fehler "Unbekanntes Argument: $arg ($AUFRUF)" ;;
    esac
done

# ---- 1. Prüfungen -----------------------------------------------------------
[ -n "$DB_DUMP" ] || fehler "Kein DB-Dump angegeben. $AUFRUF"
[ -f "$DB_DUMP" ] || fehler "DB-Dump $DB_DUMP nicht gefunden"
gzip -t "$DB_DUMP" || fehler "DB-Dump $DB_DUMP ist kein gültiges gzip"
gzip -dc "$DB_DUMP" | tail -1 | grep -q 'Dump completed' \
    || fehler "DB-Dump $DB_DUMP ist unvollständig (kein 'Dump completed')"
if [ -n "$EXPORTE_ARCHIV" ]; then
    [ -f "$EXPORTE_ARCHIV" ] || fehler "Export-Archiv $EXPORTE_ARCHIV nicht gefunden"
    gzip -t "$EXPORTE_ARCHIV" || fehler "Export-Archiv $EXPORTE_ARCHIV ist kein gültiges gzip"
    # Nur Archive aus backup.sh (alles unter exporte/) – nichts anderes nach writable/ entpacken.
    if tar -tzf "$EXPORTE_ARCHIV" | grep -v '^exporte/' >/dev/null; then
        fehler "Export-Archiv $EXPORTE_ARCHIV enthält Dateien außerhalb von exporte/"
    fi
    [ -d "$APP_DIR/writable" ] || fehler "Verzeichnis $APP_DIR/writable fehlt"
fi
if [ -n "$BILDER_ARCHIV" ]; then
    [ -f "$BILDER_ARCHIV" ] || fehler "Bild-Archiv $BILDER_ARCHIV nicht gefunden"
    gzip -t "$BILDER_ARCHIV" || fehler "Bild-Archiv $BILDER_ARCHIV ist kein gültiges gzip"
    # Nur Archive aus backup.sh (alles unter artikelbilder/) – nichts anderes nach writable/ entpacken.
    if tar -tzf "$BILDER_ARCHIV" | grep -v '^artikelbilder/' >/dev/null; then
        fehler "Bild-Archiv $BILDER_ARCHIV enthält Dateien außerhalb von artikelbilder/"
    fi
    [ -d "$APP_DIR/writable" ] || fehler "Verzeichnis $APP_DIR/writable fehlt"
fi

if [ -n "$BACKUP_MOUNT" ]; then
    command -v mountpoint >/dev/null || fehler "mountpoint nicht gefunden (util-linux)"
    mountpoint -q "$BACKUP_MOUNT" \
        || fehler "$BACKUP_MOUNT ist nicht gemountet (anderes Ziel: BACKUP_MOUNT= BACKUP_DIR=<pfad>)"
    [ -n "${DB_PASS:-}" ] || fehler "DB_PASS ist nicht gesetzt (Produktion: /etc/getraenkeliste-backup.env)"
else
    DB_PASS="${DB_PASS:-getraenkepass123}"
fi

command -v docker >/dev/null || fehler "docker nicht gefunden"
docker ps --format '{{.Names}}' | grep -x "$DB_CONTAINER" >/dev/null \
    || fehler "DB-Container '$DB_CONTAINER' läuft nicht (docker compose up -d)"

# ---- 2. Sicherheitsabfrage --------------------------------------------------
if [ "$JA" -ne 1 ]; then
    [ -t 0 ] || fehler "Keine Rückfrage möglich (kein Terminal) – zum Bestätigen --ja angeben"
    echo "Quelle: $DB_DUMP"
    if [ -n "$EXPORTE_ARCHIV" ]; then
        echo "        writable/exporte/ wird durch $EXPORTE_ARCHIV ersetzt."
    fi
    if [ -n "$BILDER_ARCHIV" ]; then
        echo "        writable/artikelbilder/ wird durch $BILDER_ARCHIV ersetzt."
    fi
    read -r -p "Datenbank $DB_NAME wird überschrieben. Fortfahren? (ja/nein) " ANTWORT
    [ "$ANTWORT" = "ja" ] || { echo "Abgebrochen."; exit 0; }
fi

# ---- 3. Web-Container stoppen (wird bei Ende/Fehler wieder gestartet) -------
WEB_GESTOPPT=0
web_wieder_starten() {
    if [ "$WEB_GESTOPPT" -eq 1 ]; then
        if docker start "$WEB_CONTAINER" >/dev/null; then
            echo "Web-Container $WEB_CONTAINER wieder gestartet."
        else
            echo "WARNUNG: $WEB_CONTAINER ließ sich nicht starten (docker compose up -d)" >&2
        fi
    fi
}
trap web_wieder_starten EXIT

if [ -n "$WEB_CONTAINER" ] && docker ps --format '{{.Names}}' | grep -x "$WEB_CONTAINER" >/dev/null; then
    docker stop "$WEB_CONTAINER" >/dev/null || fehler "Web-Container $WEB_CONTAINER ließ sich nicht stoppen"
    WEB_GESTOPPT=1
    echo "Web-Container $WEB_CONTAINER gestoppt."
fi

# ---- 4. Sicherheits-Dump des aktuellen Stands -------------------------------
ZEITSTEMPEL="$(date +%Y-%m-%d_%H%M%S)"
mkdir -p "$BACKUP_DIR/vor-restore"
SICHERHEITS_DUMP="$BACKUP_DIR/vor-restore/db_${DB_NAME}_$ZEITSTEMPEL.sql.gz"

docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
    mysqldump --single-transaction --no-tablespaces --routines --triggers \
    -u "$DB_USER" "$DB_NAME" | gzip > "$SICHERHEITS_DUMP" \
    || { rm -f "$SICHERHEITS_DUMP"; fehler "Sicherheits-Dump fehlgeschlagen – Abbruch VOR der Wiederherstellung"; }
gzip -dc "$SICHERHEITS_DUMP" | tail -1 | grep -q 'Dump completed' \
    || { rm -f "$SICHERHEITS_DUMP"; fehler "Sicherheits-Dump unvollständig – Abbruch VOR der Wiederherstellung"; }
echo "Sicherheits-Dump: $SICHERHEITS_DUMP"

if [ -n "$EXPORTE_ARCHIV" ]; then
    SICHERHEITS_EXPORTE="$BACKUP_DIR/vor-restore/exporte_$ZEITSTEMPEL.tar.gz"
    if [ -d "$APP_DIR/writable/exporte" ]; then
        tar -czf "$SICHERHEITS_EXPORTE" -C "$APP_DIR/writable" exporte
        echo "Sicherung der Exporte: $SICHERHEITS_EXPORTE"
    fi
fi

if [ -n "$BILDER_ARCHIV" ] && [ -d "$APP_DIR/writable/artikelbilder" ]; then
    SICHERHEITS_BILDER="$BACKUP_DIR/vor-restore/artikelbilder_$ZEITSTEMPEL.tar.gz"
    tar -czf "$SICHERHEITS_BILDER" -C "$APP_DIR/writable" artikelbilder
    echo "Sicherung der Artikelbilder: $SICHERHEITS_BILDER"
fi

# ---- 5. DB einspielen -------------------------------------------------------
gzip -dc "$DB_DUMP" | docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
    mysql -u "$DB_USER" "$DB_NAME" \
    || fehler "Einspielen fehlgeschlagen – Stand vorher: $SICHERHEITS_DUMP"
echo "Datenbank $DB_NAME wiederhergestellt aus $DB_DUMP"

# ---- 6. Exporte einspielen --------------------------------------------------
if [ -n "$EXPORTE_ARCHIV" ]; then
    mkdir -p "$APP_DIR/writable/exporte"
    find "$APP_DIR/writable/exporte" -mindepth 1 -delete
    tar -xzf "$EXPORTE_ARCHIV" -C "$APP_DIR/writable"
    echo "writable/exporte/ wiederhergestellt aus $EXPORTE_ARCHIV"
fi

# ---- 7. Artikelbilder einspielen --------------------------------------------
if [ -n "$BILDER_ARCHIV" ]; then
    mkdir -p "$APP_DIR/writable/artikelbilder"
    find "$APP_DIR/writable/artikelbilder" -mindepth 1 -delete
    tar -xzf "$BILDER_ARCHIV" -C "$APP_DIR/writable"
    echo "writable/artikelbilder/ wiederhergestellt aus $BILDER_ARCHIV"
fi

echo
echo "Fertig. Hinweise:"
echo "  - Die Konfiguration (.env, docker-compose.override.yml) wird NICHT automatisch"
echo "    zurückgespielt. Bei Bedarf von Hand aus konfig_<datum>.tar.gz holen:"
echo "    tar -xzf konfig_<datum>.tar.gz -C <verzeichnis>   (enthält Geheimnisse!)"
echo "    Danach .env: sudo chown \"\$USER\":33 .env && sudo chmod 640 .env"
echo "  - Falls der Dump älter als der Code ist: docker exec -u www-data getraenkeliste-web php spark migrate"
echo "  - Der Web-Container setzt beim Start die Rechte von writable/ (auch der eingespielten Exporte und Bilder)."
echo "  - Artikelbilder gehören zum Dump desselben Tages (Dateinamen stehen in der DB)."
