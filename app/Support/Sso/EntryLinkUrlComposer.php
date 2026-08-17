<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use Illuminate\Support\Facades\Log;

/**
 * EntryLinkUrlComposer (operator-interview-link, design D3).
 *
 * Composes the absolute, locale-prefixed candidate entry URL from
 * `config('interview.candidate_app_url')` — never from `config('app.url')`,
 * which is this API's own origin. Fails loud, AT COMPOSE TIME, when the
 * configured origin is missing or malformed.
 *
 *   origin = rtrim(config('interview.candidate_app_url'), '/')
 *   lang === default locale        → {origin}/interview/{token}
 *   lang ∈ locales, non-default    → {origin}/{lang}/interview/{token}
 *   lang ∉ locales                 → {origin}/interview/{token} + Log::warning
 *
 * The unsupported-locale fallback is unprefixed, never a 422 — an unlisted
 * prefix 404s in `frontend/` and loses the interview's camera/microphone
 * permission grant, which is scoped to exactly `/interview/**` and
 * `/en/interview/**` (`frontend/nuxt.config.ts`).
 *
 * REQ: Entry URL Locale Prefixing Is Owned by the Minter,
 *      CANDIDATE_APP_URL Fails Loud When Unset
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */
final class EntryLinkUrlComposer
{
    public function compose(string $token, ?string $lang): string
    {
        $origin = $this->resolveOrigin();
        $prefix = $this->resolvePrefix($lang);

        return $prefix === null
            ? "{$origin}/interview/{$token}"
            : "{$origin}/{$prefix}/interview/{$token}";
    }

    /**
     * @throws EntryLinkUrlNotConfigured When unset, empty, or not an absolute http(s) URL.
     */
    private function resolveOrigin(): string
    {
        $configured = config('interview.candidate_app_url');

        if (! is_string($configured) || trim($configured) === '') {
            throw new EntryLinkUrlNotConfigured;
        }

        if (filter_var($configured, FILTER_VALIDATE_URL) === false
            || ! str_starts_with($configured, 'http://') && ! str_starts_with($configured, 'https://')) {
            throw new EntryLinkUrlNotConfigured;
        }

        return rtrim($configured, '/');
    }

    /**
     * Returns the locale prefix segment, or null when the URL must be
     * unprefixed (the default locale, or an unsupported one — logged).
     */
    private function resolvePrefix(?string $lang): ?string
    {
        $defaultLocale = config('interview.frontend_default_locale', 'it');
        $locales = config('interview.frontend_locales', ['it', 'en']);

        if ($lang === null || $lang === $defaultLocale) {
            return null;
        }

        if (! in_array($lang, $locales, true)) {
            Log::warning('EntryLinkUrlComposer: unsupported lang, falling back unprefixed.', [
                'lang' => $lang,
            ]);

            return null;
        }

        return $lang;
    }
}
