#!/bin/sh
# Starts the HRIS inside the container. Runs at container start rather than
# at build, because config:cache has to read the environment variables
# Hostforge injects into the running container — they do not exist during
# the image build.
set -e

cd /var/www/html

# Employee photos are served through public/storage. The link is gitignored,
# so it never arrives with the code and has to be made here.
php artisan storage:link --force >/dev/null 2>&1 || true

# Off unless asked for. A first deploy with no database configured should
# still start and show a real error, not crash-loop on a failed migration.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force

    # First deploy only: a brand-new database has no accounts, and there is no
    # terminal here to run db:seed. The generated passwords are printed to the
    # container log once. Once any account exists this does nothing, so a
    # restart never resets anybody's password.
    php artisan hris:seed-if-empty || true

    # Does nothing unless HRIS_ADMIN_PASSWORD is set in the panel. The way back
    # in when nobody knows the admin password and there is no terminal.
    php artisan hris:set-admin-password || true

    # Automatically activate MFA on admin accounts with ADMIN_OTP_EMAIL
    php artisan hris:bind-admin-otp || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
