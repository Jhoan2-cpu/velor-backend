#!/bin/sh
set -e

echo "[entrypoint] Waiting for DB to be ready..."
# PHP app waits for MySQL via docker-compose healthcheck (depends_on condition),
# but we add a small extra safety sleep for variable startup times.
sleep 1

echo "[entrypoint] Running migrations..."
php artisan migrate --force

echo "[entrypoint] Caching config & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] Starting PHP-FPM..."
exec "$@"
