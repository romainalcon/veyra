#!/usr/bin/env bash
#
# Veyra — MariaDB backup on a Vito Deploy server (run from the site root).
# Complements Vito's own database backups; keeps BACKUP_KEEP dumps.
# Keep the site's .env (APP_KEY) in your secrets manager as well.

set -euo pipefail

BACKUP_DIR="${1:-${HOME}/backups/veyra}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
PHP_BIN="${PHP_BIN:-php8.4}"

mkdir -p "${BACKUP_DIR}"
umask 077

read_env() { "${PHP_BIN}" artisan tinker --execute "echo config('database.connections.mysql.$1');" 2>/dev/null; }

DB_NAME="$(read_env database)"
DB_USER="$(read_env username)"
DB_HOST="$(read_env host)"
export MYSQL_PWD="$(read_env password)"

TARGET="${BACKUP_DIR}/veyra-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

mariadb-dump --single-transaction --quick --routines --triggers --events \
    -h "${DB_HOST}" -u "${DB_USER}" "${DB_NAME}" | gzip -9 > "${TARGET}"

zcat "${TARGET}" | tail -n 1 | grep -q 'Dump completed' || { echo "Backup failed." >&2; rm -f "${TARGET}"; exit 1; }

ls -1t "${BACKUP_DIR}"/veyra-*.sql.gz | tail -n +"$((BACKUP_KEEP + 1))" | xargs -r rm -f
echo "Backup written: ${TARGET}"
