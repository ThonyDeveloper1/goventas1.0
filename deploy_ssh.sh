#!/usr/bin/env bash
set -euo pipefail

# One-shot deploy script for GO Sistema
# Usage:
#   bash deploy_ssh.sh
# Optional env vars:
#   PROJECT_ROOT=/var/www/gosistema
#   BACKEND_DIR=/var/www/gosistema/backend
#   FRONTEND_DIR=/var/www/gosistema/frontend
#   PHP_BIN=php
#   COMPOSER_BIN=composer
#   NPM_BIN=npm
#   INSTALL_CRON=true
#   RUN_MIGRATIONS=true
#   RESTART_QUEUE=true

PROJECT_ROOT="${PROJECT_ROOT:-/var/www/gosistema}"
BACKEND_DIR="${BACKEND_DIR:-$PROJECT_ROOT/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$PROJECT_ROOT/frontend}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
INSTALL_CRON="${INSTALL_CRON:-true}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-true}"
RESTART_QUEUE="${RESTART_QUEUE:-true}"

echo "==> Deploy started at $(date '+%Y-%m-%d %H:%M:%S')"

echo "==> Validating directories"
[[ -d "$PROJECT_ROOT" ]] || { echo "ERROR: PROJECT_ROOT not found: $PROJECT_ROOT"; exit 1; }
[[ -d "$BACKEND_DIR" ]] || { echo "ERROR: BACKEND_DIR not found: $BACKEND_DIR"; exit 1; }
[[ -d "$FRONTEND_DIR" ]] || { echo "ERROR: FRONTEND_DIR not found: $FRONTEND_DIR"; exit 1; }

if [[ ! -f "$BACKEND_DIR/.env" ]]; then
  echo "ERROR: Missing backend .env file at $BACKEND_DIR/.env"
  echo "Create it before deploying."
  exit 1
fi

echo "==> Checking required binaries"
command -v "$PHP_BIN" >/dev/null || { echo "ERROR: php not found"; exit 1; }
command -v "$COMPOSER_BIN" >/dev/null || { echo "ERROR: composer not found"; exit 1; }
command -v "$NPM_BIN" >/dev/null || { echo "ERROR: npm not found"; exit 1; }

echo "==> Backend dependencies"
cd "$BACKEND_DIR"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Laravel maintenance and cache reset"
"$PHP_BIN" artisan optimize:clear

if [[ "$RUN_MIGRATIONS" == "true" ]]; then
  echo "==> Running migrations"
  "$PHP_BIN" artisan migrate --force
fi

echo "==> Rebuilding Laravel caches"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache || true
"$PHP_BIN" artisan view:cache || true

# Keep APP_KEY untouched; only validate it exists.
APP_KEY_VALUE=$(grep -E '^APP_KEY=' .env | sed 's/^APP_KEY=//')
if [[ -z "$APP_KEY_VALUE" ]]; then
  echo "ERROR: APP_KEY is empty in .env."
  echo "Do not continue if you are reusing encrypted MikroTik passwords from DB."
  exit 1
fi

echo "==> Frontend build"
cd "$FRONTEND_DIR"
"$NPM_BIN" ci
"$NPM_BIN" run build

echo "==> Installing scheduler cron entry"
if [[ "$INSTALL_CRON" == "true" ]]; then
  CRON_LINE="* * * * * cd $BACKEND_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
  TMP_CRON=$(mktemp)
  crontab -l 2>/dev/null | grep -v "artisan schedule:run" > "$TMP_CRON" || true
  echo "$CRON_LINE" >> "$TMP_CRON"
  crontab "$TMP_CRON"
  rm -f "$TMP_CRON"
fi

echo "==> Restarting queue worker"
if [[ "$RESTART_QUEUE" == "true" ]]; then
  cd "$BACKEND_DIR"
  "$PHP_BIN" artisan queue:restart || true
fi

echo "==> Final health checks"
cd "$BACKEND_DIR"
"$PHP_BIN" artisan about
"$PHP_BIN" artisan schedule:list

echo "==> Deploy completed successfully at $(date '+%Y-%m-%d %H:%M:%S')"
