#!/usr/bin/env bash
set -e

cd /var/www/html

# Only the primary app container should bootstrap the application; the worker
# and reverb containers share the codebase and just need to start.
if [ "${JAMDIO_ROLE:-app}" = "app" ]; then
    if [ ! -f .env ]; then
        echo "[entrypoint] creating .env from .env.example"
        cp .env.example .env
    fi

    if [ ! -d vendor ]; then
        echo "[entrypoint] installing composer dependencies"
        composer install --no-interaction --prefer-dist --no-progress
    fi

    if ! grep -q '^APP_KEY=base64' .env; then
        php artisan key:generate --force
    fi

    echo "[entrypoint] waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}"
    until mysqladmin ping -h"${DB_HOST:-mysql}" -P"${DB_PORT:-3306}" --silent 2>/dev/null; do
        sleep 2
    done

    echo "[entrypoint] running migrations"
    php artisan migrate --force --seed || php artisan migrate --force

    php artisan storage:link || true
    php artisan config:clear || true
fi

exec "$@"
