#!/bin/sh

cd /var/www/html

echo "[start] Setting up storage link..."
php artisan storage:link --force >/dev/null 2>&1 || true

if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "[start] Running migrations..."
    php artisan migrate --force || true

    echo "[start] Running seed-if-empty..."
    php artisan hris:seed-if-empty || true

    echo "[start] Setting admin password..."
    php artisan hris:set-admin-password || true

    echo "[start] Binding admin OTP..."
    php artisan hris:bind-admin-otp || true

    echo "[start] Trimming demo data to 2 sample employees..."
    php artisan hris:trim-to-two-employees || true
fi

echo "[start] Optimizing caches..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "[start] Starting server on port ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

