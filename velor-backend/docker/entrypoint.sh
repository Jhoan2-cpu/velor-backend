#!/bin/sh
set -e

echo "[entrypoint] Waiting for PostgreSQL..."
until PGPASSWORD="$DB_PASSWORD" pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null 2>&1; do
  sleep 2
done

echo "[entrypoint] Running migrations..."
php artisan migrate --force

if [ "$APP_ENV" = "production" ]; then
  echo "[entrypoint] Caching Laravel config/routes/views (production)..."
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
else
  echo "[entrypoint] Clearing Laravel cache (local)..."
  php artisan optimize:clear
fi

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
