#!/usr/bin/env bash
#
# Veyra — restore a MariaDB dump into the Docker Compose stack.
#
#   deploy/docker/restore.sh backups/veyra-YYYYMMDDTHHMMSSZ.sql.gz [--yes]
#
# The current database content is REPLACED. Application services are stopped
# during the import, then migrations run (for dumps taken on an older
# version) and the stack is restarted. Use the APP_KEY of the instance that
# produced the dump.

set -euo pipefail

DUMP="${1:-}"
CONFIRM="${2:-}"
COMPOSE=(docker compose --env-file "${ENV_FILE:-.env.docker}")

if [[ -z "${DUMP}" || ! -f "${DUMP}" ]]; then
    echo "Usage: $0 <dump.sql.gz> [--yes]" >&2
    exit 1
fi

gzip -t "${DUMP}"

if [[ "${CONFIRM}" != "--yes" ]]; then
    read -r -p "This replaces the current Veyra database with ${DUMP}. Type 'restore' to continue: " answer
    [[ "${answer}" == "restore" ]] || { echo "Aborted."; exit 1; }
fi

echo "Stopping application services…"
"${COMPOSE[@]}" stop web app horizon scheduler reverb

echo "Importing ${DUMP}…"
"${COMPOSE[@]}" exec -T mariadb sh -c \
    'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    < <(zcat "${DUMP}")

echo "Running migrations…"
"${COMPOSE[@]}" run --rm --no-deps migrate

echo "Restarting the stack…"
"${COMPOSE[@]}" up -d

echo "Restore complete. Check: curl -fsS \"\${APP_URL:-http://localhost:8000}/health\""
