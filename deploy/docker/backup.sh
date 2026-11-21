#!/usr/bin/env bash
#
# Veyra — MariaDB backup for the Docker Compose stack.
#
#   deploy/docker/backup.sh [backup-directory]
#
# Creates a consistent, compressed dump (single transaction, no table locks)
# and keeps the latest BACKUP_KEEP files. Run it from the repository root,
# e.g. daily from cron. Redis only holds transient queues and cache.
#
# IMPORTANT: also keep a copy of .env.docker (APP_KEY). Without the same
# APP_KEY, encrypted monitor headers and notification channels are lost.

set -euo pipefail

BACKUP_DIR="${1:-./backups}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
COMPOSE=(docker compose --env-file "${ENV_FILE:-.env.docker}")
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/veyra-${STAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"
umask 077

echo "Dumping database to ${TARGET}…"
"${COMPOSE[@]}" exec -T mariadb sh -c \
    'exec mariadb-dump --single-transaction --quick --routines --triggers --events \
        -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip -9 > "${TARGET}.partial"

# Refuse empty or truncated dumps.
if ! gzip -t "${TARGET}.partial" || ! zcat "${TARGET}.partial" | tail -n 1 | grep -q 'Dump completed'; then
    echo "Backup failed: the dump is incomplete." >&2
    rm -f "${TARGET}.partial"
    exit 1
fi

mv "${TARGET}.partial" "${TARGET}"
echo "Backup written ($(du -h "${TARGET}" | cut -f1))."

# Rotation: keep the newest BACKUP_KEEP dumps.
ls -1t "${BACKUP_DIR}"/veyra-*.sql.gz 2>/dev/null | tail -n +"$((BACKUP_KEEP + 1))" | xargs -r rm -f

echo "Reminder: store .env.docker (APP_KEY) securely, separately from the dumps."
