FROM php:8.4-cli-alpine

# Install pre-compiled PHP extensions instantly via installer (0 compilation time)
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && install-php-extensions pdo_pgsql pgsql

# Get Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy backend dependencies including composer.lock
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# Copy backend source code & pre-compiled frontend assets
COPY backend/ ./
COPY frontend/dist/ ./public/

# Optimize autoloader
RUN composer dump-autoload --optimize --no-dev --ignore-platform-reqs

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

# Health check on Laravel's /up endpoint
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD curl -f http://127.0.0.1:${PORT:-8000}/up || exit 1

# Start command — generate .env from environment, create APP_KEY if missing, then serve
CMD ["sh", "-c", "env | grep -E '^(APP_|DB_|SESSION_|QUEUE_|CACHE_|MAIL_|TRUSTED_|LOG_|PORT)' > .env && (grep -q APP_KEY .env || php artisan key:generate --force) && php artisan config:cache && php artisan route:cache && php artisan migrate --force 2>/dev/null; php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
