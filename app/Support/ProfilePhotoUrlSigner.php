<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * ProfilePhotoUrlSigner (user-avatar-image, design D2/D4).
 *
 * Mints a presigned, time-limited URL for a stored profile photo, memoised
 * per `profile.photo.url_window_seconds` bucket so the string is byte-stable
 * within that window — ordinary page loads reuse the browser's URL-keyed
 * HTTP cache instead of re-downloading identical bytes behind a rotating
 * query string.
 *
 * **The prefix guard is the security-critical line in this class.** It
 * refuses to sign ANY key that does not start with `profile-photos/` —
 * structurally, independent of whatever wrote the column. This is what
 * survives a controller refactor (design D2.2): even if
 * `users.profile_photo_path` were ever forced to hold a candidate
 * proctoring snapshot key (`{org}/{participant}/{session}/{uuid}.jpg`), this
 * guard refuses to presign it, so the read-side IDOR the column's
 * non-fillability prevents on the write side stays closed even if that
 * write-side layer is ever weakened.
 *
 * Called from exactly two places (design D4): ProfileResource (the
 * `/profile` contract) and AuthController::me() (the shell-identity
 * contract `useCurrentUser` caches once per page load) — never a third,
 * dedicated `GET /api/profile/photo` redirect, which would recreate the
 * object-reading surface ProfileNoIdParamArchTest exists to keep at zero.
 */
class ProfilePhotoUrlSigner
{
    private const REQUIRED_PREFIX = 'profile-photos/';

    /**
     * Returns null for: no key at all, or a key that does not start with
     * `profile-photos/` — the prefix guard, structural and unconditional.
     */
    public function urlFor(?string $key): ?string
    {
        if ($key === null || $key === '' || ! str_starts_with($key, self::REQUIRED_PREFIX)) {
            return null;
        }

        $window = (int) config('profile.photo.url_window_seconds');
        $ttlMinutes = (int) config('profile.photo.url_ttl_minutes');
        $bucket = intdiv(now()->getTimestamp(), $window);

        // design D4's snippet keys the cache on `{user_id}:sha1($key):bucket`.
        // Deviation, noted: the full key already encodes the user id as its
        // first path segment (`profile-photos/{user_id}/{uuid}.ext`), so
        // hashing it buys no extra collision resistance over using the exact
        // key string directly — the cache key below is the exact key plus
        // the same window bucket, which is byte-stable for the identical
        // reason design D4 requires.
        return Cache::remember(
            "profile_photo_url:{$key}:{$bucket}",
            $window,
            fn (): string => Storage::disk()->temporaryUrl($key, now()->addMinutes($ttlMinutes)),
        );
    }
}
