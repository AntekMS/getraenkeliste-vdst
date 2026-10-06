#!/bin/sh
#
# Entrypoint des Web-Containers.
#
# Auf einem Linux-Host (Raspberry Pi) behält der Bind-Mount ./:/var/www/html die
# UID des Host-Benutzers; Apache (www-data) könnte writable/ (Sessions, Cache,
# Logs, Exporte) dann nicht beschreiben. Deshalb wird writable/ bei jedem Start
# www-data übereignet. Unter Docker Desktop (Windows/macOS) greifen chown/chmod
# auf dem Bind-Mount ggf. nicht – das ist dort unkritisch und bricht den Start nicht ab.
#
# Danach übernimmt der Entrypoint des php-Images (docker-php-entrypoint) mit dem
# ursprünglichen Befehl (CMD apache2-foreground).

set -e

WRITABLE=/var/www/html/writable

if [ -d "$WRITABLE" ]; then
    chown -R www-data:www-data "$WRITABLE" 2>/dev/null \
        || echo "Hinweis: chown auf $WRITABLE nicht möglich (Docker Desktop?) – übersprungen" >&2
    chmod -R u+rwX,g+rwX "$WRITABLE" 2>/dev/null \
        || echo "Hinweis: chmod auf $WRITABLE nicht möglich – übersprungen" >&2
fi

exec docker-php-entrypoint "$@"
