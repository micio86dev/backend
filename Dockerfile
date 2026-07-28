# syntax=docker/dockerfile:1
# BEAI API — Multi-stage Dockerfile
# D25 Version Catalog: PHP base image = php:8.5.8-fpm-alpine
# D17: non-root user, HEALTHCHECK, small final image, local == Railway image

# ─── Build stage ────────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS builder

# Robust PHP extension installer (handles system deps + PHP 8.5 build quirks)
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# Build tools + PHP extensions (pdo_pgsql required; zip for composer; opcache; pcov for coverage)
RUN apk add --no-cache unzip git \
    && install-php-extensions pdo_pgsql zip opcache pcov

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install production dependencies (no dev)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application source
COPY . .

# Generate optimized autoloader (skip scripts: package:discover needs a booted
# app + .env, unavailable at build time; Laravel rebuilds the manifest at runtime)
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ─── Runtime stage ──────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="BEAI API" \
      org.opencontainers.image.description="Behavioral Event AI — Laravel 13 API" \
      org.opencontainers.image.version="0.1.0"

# Robust extension installer (also pulls the correct runtime libs and cleans up build deps)
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# Runtime-only system deps + PHP extensions needed in production
RUN apk add --no-cache postgresql-client curl \
    && install-php-extensions pdo_pgsql zip opcache \
    && rm -rf /var/cache/apk/*

# Create non-root user (D17)
RUN addgroup -g 1001 -S appgroup && \
    adduser -u 1001 -S appuser -G appgroup

WORKDIR /var/www

# Copy application from build stage
COPY --from=builder --chown=appuser:appgroup /var/www .

# Set correct permissions on storage and cache
RUN mkdir -p storage/framework/{cache,sessions,views} \
             storage/logs \
             bootstrap/cache \
    && chown -R appuser:appgroup storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Switch to non-root user
USER appuser

EXPOSE 8000

# Health check hitting /api/health (D17)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:8000/api/health || exit 1

# Use PHP built-in server for the API (production would use PHP-FPM + nginx or Octane)
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
