#!/usr/bin/env bash
#
# Veyra — deployment script for a Vito Deploy site (PHP-FPM + Nginx).
# Paste into the site's deployment script in Vito, or call it from there.
# It runs from the site's root directory after Vito has pulled the code.
# Adjust PHP_BIN to the PHP 8.4 binary configured for the site.

set -euo pipefail

PHP_BIN="${PHP_BIN:-php8.4}"

echo "Installing PHP dependencies…"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "Building front-end assets…"
npm ci --no-audit --no-fund
npm run build

echo "Running database migrations…"
"$PHP_BIN" artisan migrate --force

echo "Caching configuration…"
"$PHP_BIN" artisan optimize

echo "Restarting long-running processes…"
# Horizon workers are restarted by Supervisor once they exit gracefully.
"$PHP_BIN" artisan horizon:terminate
"$PHP_BIN" artisan reverb:restart
"$PHP_BIN" artisan queue:restart

echo "Deployment finished."
