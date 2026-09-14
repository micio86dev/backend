<?php

declare(strict_types=1);

/**
 * Per-organization branding limits.
 *
 * A separate file from `profile.php` on purpose. The two uploads share a
 * verification routine but not a policy: a profile photo is one person's
 * avatar, while a logo is rendered at the top of every page every candidate of
 * an organization sees, and the two will not want the same ceilings forever.
 */
return [
    'logo' => [
        /*
         * Hard byte cap. Enforced against the REAL byte count in the
         * controller, not only by a FormRequest's `max:` — that rule reads a
         * hardcoded literal and is a fast shape check, not the policy.
         *
         * 1 MiB rather than the profile photo's 2: a logo is a small flat
         * graphic, and a file larger than this is almost always an
         * unoptimised export that would be shipped to every candidate on
         * every page load.
         */
        'max_bytes' => (int) env('BRANDING_LOGO_MAX_BYTES', 1_048_576),

        /*
         * Rejected when either dimension of the DECODED image exceeds this.
         * The byte cap alone does not stop a decompression bomb: a few
         * kilobytes of PNG can decode to hundreds of megabytes of pixels, and
         * the memory is spent before anything measures the result.
         */
        'max_dimension' => (int) env('BRANDING_LOGO_MAX_DIMENSION', 2048),

        /*
         * Lifetime of the presigned object URL the public logo endpoint
         * redirects to.
         *
         * Invisible to every caller: the URL in the API payload and in an
         * email is this API's own stable route, and the signature is minted
         * fresh on each redirect. So this is tuned for the download that
         * immediately follows a redirect, not for how long a link must keep
         * working — which is the reason the logo could not simply BE a
         * presigned URL in the first place.
         */
        'url_ttl_minutes' => (int) env('BRANDING_LOGO_URL_TTL_MINUTES', 60),

        /*
         * How long a client may cache the REDIRECT itself.
         *
         * A CEILING, not the value. `OrganizationLogoController::show()`
         * clamps it to half the signature's lifetime, because a redirect
         * cached longer than what it points at is a broken image served from
         * the browser's own cache — nothing on the wire, nothing in a log.
         * Both knobs are independently env-overridable, so the relationship
         * between them is enforced in code rather than asserted here.
         */
        'redirect_cache_seconds' => (int) env('BRANDING_LOGO_REDIRECT_CACHE_SECONDS', 600),
    ],
];
