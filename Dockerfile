FROM php:8.3-cli-alpine

# Get pre-built extension installer from Docker Hub (prevents cache-busting on every commit)
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    bcmath \
    exif \
    gd \
    intl \
    pcntl \
    pdo_pgsql \
    pgsql \
    zip

# Get Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy backend dependencies including composer.lock
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# Copy backend source code & pre-compiled frontend assets in public/build
COPY backend/ ./

# Optimize autoloader
RUN composer dump-autoload --optimize --no-dev --ignore-platform-reqs

# Set permissions
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000
ENV PHP_CLI_SERVER_WORKERS=4

# Health check on Laravel's /up endpoint
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:${PORT:-8000}/up || exit 1

COPY docker/start.sh /usr/local/bin/start-hris
RUN sed -i 's/\r$//' /usr/local/bin/start-hris && chmod +x /usr/local/bin/start-hris

CMD ["start-hris"]
