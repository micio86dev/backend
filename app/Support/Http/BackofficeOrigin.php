<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Facades\Log;

/**
 * Resolves and validates `services.backoffice_origin` (env `BACKOFFICE_ORIGIN`)
 * — the ONE place that decides whether that value is usable.
 *
 * Extracted from `SecurityHeaders` (self-service-password-reset AD-5) because a
 * second consumer arrived: the password-reset link builder. The rules a reset
 * link needs are exactly the rules the CSP already needed — non-empty,
 * non-wildcard, explicit `http(s)://` origin — and two copies of them would
 * eventually disagree, shipping a deployment with a working CSP and broken
 * emails, or the reverse.
 *
 * `BACKOFFICE_URL` does not exist and must not be introduced: a second variable
 * naming the same URL is a second source of truth for it.
 *
 * Reads `config()`, never `env()`, so the value survives a cached config.
 *
 * The behaviour on an unusable value is to return `null` and let the caller
 * choose its safe default — `SecurityHeaders` falls back to a block-all CSP,
 * and the reset flow refuses to send. Neither guesses.
 */
final class BackofficeOrigin
{
    /**
     * The validated origin with any trailing slash removed, or `null` when it
     * is absent, wildcard, or not an origin at all.
     *
     * @param  string  $context  Prefixes the warning so a log reader can tell
     *                           which consumer refused — the CSP and the mail
     *                           flow fail very differently.
     */
    public static function resolve(string $context): ?string
    {
        $raw = (string) config('services.backoffice_origin', '');

        if ($raw === '') {
            return null;
        }

        if ($raw === '*') {
            Log::warning("{$context}: BACKOFFICE_ORIGIN is set to wildcard \"*\" — refusing to use it.");

            return null;
        }

        if (preg_match('#^https?://#', $raw) !== 1) {
            Log::warning("{$context}: BACKOFFICE_ORIGIN \"{$raw}\" is not a valid origin — refusing to use it.");

            return null;
        }

        return rtrim($raw, '/');
    }
}
