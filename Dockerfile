FROM alpine:3.20

# Install PHP 8.3 and all extensions as instant pre-compiled binaries (0 compilation time, takes ~10s)
RUN apk add --no-cache \
    curl \
    ca-certificates \
    php83 \
    php83-cli \
    php83-common \
    php83-bcmath \
    php83-ctype \
    php83-curl \
    php83-dom \
    php83-exif \
    php83-fileinfo \
    php83-gd \
    php83-intl \
    php83-mbstring \
    php83-opcache \
    php83-openssl \
    php83-pcntl \
    php83-pdo \
    php83-pdo_pgsql \
    php83-pgsql \
    php83-phar \
    php83-session \
    php83-simplexml \
    php83-sodium \
    php83-tokenizer \
    php83-xml \
    php83-xmlwriter \
    php83-zip \
    && ln -sf /usr/bin/php83 /usr/bin/php \
    && addgroup -g 82 -S www-data 2>/dev/null || true \
    && adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true

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
