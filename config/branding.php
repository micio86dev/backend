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
    ],
];
