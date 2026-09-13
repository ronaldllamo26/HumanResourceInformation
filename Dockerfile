# ==========================================
# Stage 1: Build Frontend Assets (Vite / React)
# ==========================================
FROM node:20-alpine AS frontend-builder

WORKDIR /app/frontend

COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

# ==========================================
# Stage 2: PHP 8.3 Laravel Backend
# ==========================================
FROM php:8.4-cli-alpine

# Install system dependencies & PHP extensions
RUN apk add --no-cache git curl postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql

# Get Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy backend dependencies
COPY backend/composer*.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy backend source code
COPY backend/ ./

# Copy compiled frontend assets from Stage 1 into Laravel public directory
COPY --from=frontend-builder /app/frontend/dist/ ./public/

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
