<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * UpdateProfilePhotoRequest (user-avatar-image, design D3/D3b).
 *
 * Validates POST /api/profile/photo. `max:2048` (KB) is the SHAPE check —
 * `ValidatePostSize` already returns 413 for anything past nginx's
 * `client_max_body_size 8m` / PHP's `post_max_size=8M` before this even
 * runs. The REAL enforcement is `config('profile.photo.max_bytes')`
 * (2 MiB), checked in ProfilePhotoController against the real byte count,
 * never this literal.
 *
 * What this rule does NOT do, stated plainly, per design D3 — the residual
 * is documented in code, not just in design.md:
 *
 * - No re-encode: EXIF (including GPS coordinates, camera serial, capture
 *   timestamp, and any embedded thumbnail) survives verbatim into every
 *   presigned URL served from the stored object.
 * - Polyglots and trailing payloads pass every check here — bytes appended
 *   after valid image data are never inspected, so the object is
 *   effectively a byte-capped arbitrary-content store keyed to a user id.
 * - A 4096×4096 PNG (the dimension cap's own ceiling) is roughly 64 MB
 *   decompressed once a browser renders it — the cap bounds the spike, it
 *   does not remove it.
 * - Nothing decodes server-side, so a header-valid file crafted to crash a
 *   specific image decoder is not detected.
 *
 * The only route to closing any of the above is re-encoding, which needs
 * `ext-gd` or `ext-imagick` — neither is installed in either Dockerfile
 * stage, and adding one is explicitly out of scope for this change.
 * Client-side canvas re-encoding is a UX convenience, never a control: it
 * makes honest uploads fit the cap and incidentally drops EXIF, but an
 * attacker posts the multipart request directly and skips it entirely.
 *
 * REQ: Upload Is Validated By Content, Not By Declared Type
 * (openspec/changes/user-avatar-image/specs/user-self-service/spec.md)
 */
class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'max:2048'],
        ];
    }

    /**
     * Machine CODES here too, matching the controller (profile-photo-error-codes).
     *
     * This layer runs FIRST, and its `max:2048` is 2048 KB — exactly the
     * `profile.photo.max_bytes` default of 2 097 152. So at default config the
     * controller's own `photo_too_large` is unreachable: every oversized file
     * is already rejected here, and it was rejected with Laravel's English
     * prose. Renaming only the controller's messages left the path an operator
     * actually hits still answering in the wrong language.
     *
     * The controller's checks stay: they read the CONFIG rather than this
     * literal, so they remain the enforcement the moment the two diverge.
     *
     * `photo.file` answers TWO different questions, so its code is resolved
     * from the upload error rather than fixed. `config/profile.php` records
     * why: there is no `php.ini` in `api/docker/`, so PHP's compiled
     * `upload_max_filesize=2M` is the REAL ceiling and fires before Laravel
     * sees the size at all. `UploadedFile::isValid()` is then false, the
     * `file` rule fails, and a fixed `photo_invalid_image` would tell an
     * operator their perfectly good photo was corrupt when it was merely
     * large — the most common oversize path in production, answered wrongly.
     *
     * The key is `photo.uploaded`, not `photo.file`: Laravel's `file` rule
     * delegates to `uploaded`, and that is the message a PHP-level failure
     * resolves against. And only the SIZE errors map to too_large — a partial
     * transfer or a missing tmp directory is a genuine upload failure, and
     * calling it "too large" would send the operator shrinking a file that was
     * never the problem.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $photo = $this->file('photo');

        // PHP's own size refusals, which never reach Laravel's `max:`.
        $refusedForSize = $photo instanceof UploadedFile
            && in_array($photo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

        return [
            'photo.required' => 'photo_required',
            'photo.file' => 'photo_invalid_image',
            // `file` delegates to `uploaded`, so THIS is the key a PHP-level
            // upload failure lands on — not `photo.file`, which is what the
            // first attempt assumed and what the test caught.
            'photo.uploaded' => $refusedForSize ? 'photo_too_large' : 'photo_upload_failed',
            'photo.max' => 'photo_too_large',
        ];
    }
}
