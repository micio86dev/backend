<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Detects an empty CORS allowlist outside `local`/`testing` (deploy incident
 * 2026-08-20: `CORS_ALLOWED_ORIGINS` was unset on Railway, config/cors.php
 * resolved to an empty allowlist, and every cross-origin request from the
 * backoffice and candidate frontend was silently blocked with no signal
 * anywhere CI, the test suite, or the plain "ok" health check could see).
 *
 * Deliberately NOT a check inside config/cors.php itself. Once
 * `php artisan config:cache` has run, config/cors.php is never evaluated
 * again — every later boot reads the frozen array straight out of
 * bootstrap/cache/config.php, so a throw written inside the config file only
 * ever fires at cache-build time and goes silent on every request after
 * that. `config('cors.allowed_origins')` is safe to call here on every boot
 * because it always reads the resolved value FROM the config repository —
 * cached or not — never re-executes the config file.
 *
 * Consumed by App\Http\Controllers\HealthController rather than thrown from
 * a ServiceProvider::boot(): boot() runs for every process that boots the
 * framework, including `queue:work` and `schedule:work`, and CORS is a
 * purely HTTP-layer concern — crashing evaluation workers over a browser
 * allowlist misconfiguration would be a strictly worse outage. The
 * `/api/health` endpoint is what Docker's HEALTHCHECK polls every 30s
 * (Dockerfile) and what Railway watches, which is exactly "a point an
 * operator will actually notice."
 */
final class CorsAllowlistStatus
{
    /**
     * True when the CORS allowlist is empty in an environment where that is
     * never legitimate. `local` and `testing` are exempt: config/cors.php's
     * default local value and phpunit.xml's fixture value may legitimately
     * be empty or localhost-only.
     */
    public static function isMisconfigured(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return false;
        }

        return config('cors.allowed_origins') === [];
    }
}
