FROM php:8.4-cli-alpine

# Install system dependencies & PHP extensions
RUN apk add --no-cache git curl postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql

# Get Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy backend dependencies including composer.lock
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# Copy backend source code
COPY backend/ ./

# Copy pre-compiled frontend assets directly into Laravel public directory
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

# Start command
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
