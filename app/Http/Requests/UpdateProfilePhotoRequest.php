<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
}
