# syntax=docker/dockerfile:1
# BEAI API — Multi-stage Dockerfile
# D25 Version Catalog: PHP base image = php:8.5.8-fpm-alpine
# D17: non-root user, HEALTHCHECK, small final image, local == Railway image

# ─── Build stage ────────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS builder

# Robust PHP extension installer (handles system deps + PHP 8.5 build quirks)
COPY --from=mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/

# Build tools + PHP extensions (pdo_pgsql required; zip for composer; opcache; pcov for coverage)
RUN apk add --no-cache unzip git \
    && install-php-extensions pdo_pgsql zip opcache pcov

# Install Composer
COPY --from=composer:2.10.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install production dependencies (no dev).
#
# NO `--prefer-dist`, AND BOUNDED RETRIES. Both are about the same failure, and
# it is not hypothetical: on 2026-09-14 `api.github.com` served HTTP/2 504 on
# package zipballs for over an hour, and this step failed three builds in a row
# with `exit code: 100`. Composer said exactly why it could not recover:
#
#     Failed to download resend/resend-php from dist: ... (HTTP/2 504)
#     Source fallback is disabled. Not trying alternative sources.
#
# `--prefer-dist` is what disables that fallback. Without the flag, composer
# still prefers dist for stable packages — the normal path is unchanged and no
# slower — but a dist that 504s falls back to a git clone instead of killing
# the build. `git` is installed above precisely so that path exists.
#
# The retry covers the case the fallback cannot: GitHub failing BOTH the API
# and the clone during the same incident. Four attempts with a widening pause,
# then a real failure — a build that retries forever is a build nobody can
# read.
#
# NOTHING HERE TOUCHES A VERSION. `composer.lock` still decides what is
# installed, exactly as D37's Dependency Resolution Policy requires: this
# changes only how a correctly-pinned package is FETCHED when the network
# misbehaves. A transport failure is not a dependency conflict, and retrying a
# download is not loosening a constraint.
RUN set -eu; \
    for attempt in 1 2 3 4; do \
      if composer install --no-dev --no-scripts --no-autoloader; then exit 0; fi; \
      echo "composer install failed (attempt ${attempt}/4); retrying in $((attempt * 20))s"; \
      sleep $((attempt * 20)); \
    done; \
    echo "composer install failed 4 times — see the errors above"; \
    exit 1

# Copy application source
COPY . .

# Generate optimized autoloader (skip scripts: package:discover needs a booted
# app + .env, unavailable at build time; Laravel rebuilds the manifest at runtime)
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ─── Runtime stage ──────────────────────────────────────────────────────────
FROM php:8.5.8-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="BEAI API" \
      org.opencontainers.image.description="Business Evaluation AI — Laravel 13 API" \
      org.opencontainers.image.version="0.1.0"

# Robust extension installer (also pulls the correct runtime libs and cleans up build deps)
COPY --from=mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/

# Runtime-only system deps + PHP extensions needed in production.
# pcntl + posix: required for graceful worker shutdown (SIGTERM handling,
# Worker::supportsAsyncSignals()). redis: phpredis client for the `redis`
# queue connection (predis/predis is not a dependency — see composer.lock).
RUN apk add --no-cache postgresql-client curl \
    && install-php-extensions pdo_pgsql zip opcache pcntl posix redis \
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

# nginx + supervisord. The comment that used to sit at the bottom of this file
# said "production would use PHP-FPM + nginx or Octane" — and then ran
# `php artisan serve`, Laravel's DEVELOPMENT server: one request at a time, and
# explicitly documented as unsuitable for production. The base image has always
# been php-fpm-alpine, so fpm was here and unused.
RUN apk add --no-cache nginx supervisor

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
# A pass-through that execs the start command, so `worker` and `scheduler` can
# run this same image with their own commands. Release steps (migrations, then
# the LLM registry sync) belong to `beai:deploy` in Railway's once-per-deploy
# preDeployCommand slot, NOT here — the script's own header records why.
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# php-fpm on a local TCP socket rather than the default Unix one: nginx and fpm
# are separate processes in this container, and a TCP socket needs no shared
# path with the right ownership on a filesystem the non-root user cannot chown.
#
# No user/group directives: FPM only honours them when it starts as root, and
# this image drops to appuser below. Leaving them in printed two "directive is
# ignored" notices on every boot and implied a privilege drop that is not
# happening here.
RUN printf '[global]\ndaemonize = no\nerror_log = /dev/stderr\n\n[www]\nlisten = 127.0.0.1:9000\npm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 8\ncatch_workers_output = yes\ndecorate_workers_output = no\naccess.log = /dev/null\nclear_env = no\n' > /usr/local/etc/php-fpm.conf \
    && mkdir -p /tmp/nginx-client-body /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi \
    && chown -R appuser:appgroup /tmp/nginx-* /etc/nginx

# X-Powered-By announced the exact PHP patch level to every caller, which tells
# an attacker which CVEs to try without them having to probe for it.
# `zend.exception_ignore_args=On` closes the leak at the SOURCE as well as at the
# sink. Without it PHP captures every function argument into the backtrace, the
# Sentry SDK reflects them into frame `vars`, and a method taking
# `string $transcript` or `string $token` puts that value on the wire. This image
# installs no php.ini — `php:8.5.8-fpm-alpine` ships none active and
# `php.ini-production` is never copied — so the built-in default applied and
# arguments WERE captured. SentryScrubber walks frame vars too; this is the
# belt to that pair of braces.
RUN printf 'expose_php = Off\nzend.exception_ignore_args = On\n' > /usr/local/etc/php/conf.d/99-hardening.ini

# Switch to non-root user
USER appuser

EXPOSE 8000

# Health check hitting /api/health (D17)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:8000/api/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
