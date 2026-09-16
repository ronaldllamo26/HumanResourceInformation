#!/bin/sh
set -e

cd /var/www/html

# Link storage for employee photos and uploads
php artisan storage:link --force >/dev/null 2>&1 || true

# Generate .env file from injected container environment variables if not present
if [ ! -f .env ]; then
    env | grep -E '^(APP_|DB_|DATABASE_|SESSION_|QUEUE_|CACHE_|MAIL_|TRUSTED_|LOG_|PORT|HRIS_|SANCTUM_)' > .env
fi

# Ensure APP_KEY exists
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    php artisan key:generate --force
fi

# Run migrations and seed if empty unless explicitly set to false
if [ "$RUN_MIGRATIONS" != "false" ]; then
    php artisan migrate --force || true
    php artisan hris:seed-if-empty || true
    php artisan hris:set-admin-password || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
