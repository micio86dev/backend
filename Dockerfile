# syntax=docker/dockerfile:1
# BEAI API — Multi-stage Dockerfile
# D25 Version Catalog: PHP base image = php:8.5.8-fpm-alpine
# D17: non-root user, HEALTHCHECK, small final image, local == Railway image

# ─── Build stage ────────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS builder

# Install system dependencies for PHP extensions
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    unzip \
    git

# Install PHP extensions: pdo_pgsql (required), pcov (test coverage), zip
RUN docker-php-ext-install pdo pdo_pgsql zip opcache

# Install PCOV for fast code coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install production dependencies (no dev)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application source
COPY . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize --no-dev

# ─── Runtime stage ──────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="BEAI API" \
      org.opencontainers.image.description="Business Evaluation AI — Laravel 13 API" \
      org.opencontainers.image.version="0.1.0"

# Install runtime-only system dependencies
RUN apk add --no-cache \
    postgresql-client \
    postgresql-libs \
    libzip \
    curl \
    && rm -rf /var/cache/apk/*

# Install PHP extensions needed at runtime
RUN apk add --no-cache postgresql-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip opcache \
    && apk del postgresql-dev libzip-dev \
    && rm -rf /var/cache/apk/*

# Copy PHP config
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d

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
