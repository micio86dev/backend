<?php

declare(strict_types=1);

/**
 * Profile photo upload configuration (user-avatar-image, D3/D4).
 *
 * Keys:
 *   photo.max_bytes         — Hard byte cap on an uploaded photo. 2 MiB
 *                              (2 097 152) is not arbitrary: there is no
 *                              `php.ini` in `api/docker/`, so PHP's compiled
 *                              default `upload_max_filesize=2M` is the real
 *                              ceiling (verified: `php -i` on the runtime
 *                              image reports `upload_max_filesize => 2M`,
 *                              `post_max_size => 8M`). nginx allows `8m`
 *                              (`docker/nginx.conf:39`). A cap above 2 MiB
 *                              would be silently unreachable — PHP would set
 *                              UPLOAD_ERR_INI_SIZE and Laravel would 422 with
 *                              a message about a rule that never ran.
 *                              Raising it requires a `php.ini` in the image,
 *                              not a config change.
 *   photo.max_dimension      — Reject when either dimension of the decoded
 *                              header exceeds this, via getimagesize()
 *                              (ext-standard, not ext-gd — header read only,
 *                              never a pixel decode).
 *   photo.url_ttl_minutes    — Presigned URL validity window. 60 minutes:
 *                              four times the candidate snapshot's 15, since
 *                              an operator's own face photo is neither
 *                              evidence nor another person's data.
 *   photo.url_window_seconds — Quantisation bucket for the memoised signed
 *                              URL (design D4): every request inside the
 *                              same 900s bucket reads the same cache entry,
 *                              so the string is byte-stable and the
 *                              browser's URL-keyed HTTP cache hits.
 */
return [

    'photo' => [
        'max_bytes' => (int) env('PROFILE_PHOTO_MAX_BYTES', 2_097_152),
        'max_dimension' => (int) env('PROFILE_PHOTO_MAX_DIMENSION', 4096),
        'url_ttl_minutes' => (int) env('PROFILE_PHOTO_URL_TTL_MINUTES', 60),
        'url_window_seconds' => (int) env('PROFILE_PHOTO_URL_WINDOW_SECONDS', 900),
    ],

];
