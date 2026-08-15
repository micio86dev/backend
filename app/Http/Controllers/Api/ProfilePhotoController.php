<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfilePhotoRequest;
use App\Http\Resources\Admin\ProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * ProfilePhotoController (user-avatar-image, design D1/D3b/D5).
 *
 * POST/DELETE /api/profile/photo — a binary sub-resource beside the JSON
 * `/api/profile` resource, never inside it. Self-resolving exactly like
 * ProfileController: the subject is `$request->user()`, never a route
 * parameter (guarded by ProfileNoIdParamArchTest).
 */
class ProfilePhotoController extends Controller
{
    /**
     * POST /api/profile/photo
     *
     * Validation order matters (design D3b) — the first four steps touch
     * only the PHP temp file, so a rejected upload NEVER reaches the disk:
     *   1. UpdateProfilePhotoRequest (required|file|max:2048 KB, shape only
     *      — a fast pre-check against a hardcoded literal, NOT itself
     *      config-driven; step 2 below is the real enforcement point).
     *   2. Magic-byte verdict (content, never the declared type)
     *   3. $file->getSize() > config('profile.photo.max_bytes') — the ACTUAL
     *      byte cap (post-apply verification finding: this step was
     *      missing; the FormRequest literal merely coincided with the
     *      config default and could not be overridden).
     *   4. getimagesize() — false, or over the configured dimension cap
     *   5. Storage::putFileAs(...) — no disk() argument (SingleStorageDiskArchTest)
     *   6. $user->profile_photo_path = $newKey; $user->save();
     *      throws → delete the NEW key in the catch, then re-throw — never
     *      orphan the object the row-write that just failed never pointed to.
     *   7. After the row commits, delete the OLD key. Logged, never fatal:
     *      at most one stale object per user is preferable to failing a
     *      request over a change that already succeeded.
     */
    public function store(UpdateProfilePhotoRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var UploadedFile $file */
        $file = $request->file('photo');

        $extension = $this->verdictExtension($file->getRealPath());

        if ($extension === null) {
            throw ValidationException::withMessages([
                'photo' => ['The photo must be a valid JPEG or PNG image.'],
            ]);
        }

        // The REAL byte-cap enforcement (post-apply verification finding):
        // UpdateProfilePhotoRequest's `max:2048` is a fast, FormRequest-level
        // shape check against a hardcoded literal that merely happens to
        // equal the config default — it is not itself config-driven. This
        // is the actual enforcement point config('profile.photo.max_bytes')
        // was always meant to be, checked against the real byte count.
        $maxBytes = (int) config('profile.photo.max_bytes');

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'photo' => ['The photo exceeds the maximum allowed size.'],
            ]);
        }

        $dimensions = @getimagesize($file->getRealPath());
        $maxDimension = (int) config('profile.photo.max_dimension');

        if ($dimensions === false || $dimensions[0] > $maxDimension || $dimensions[1] > $maxDimension) {
            throw ValidationException::withMessages([
                'photo' => ['The photo dimensions are invalid or exceed the maximum allowed size.'],
            ]);
        }

        $oldKey = $user->profile_photo_path;
        $directory = 'profile-photos/'.$user->id;
        $filename = (string) Str::uuid().'.'.$extension;

        // No argument: the disk is resolved through the single storage
        // configuration point, exactly like SnapshotController — never named
        // here (SingleStorageDiskArchTest).
        $stored = Storage::putFileAs($directory, $file, $filename);

        if ($stored === false) {
            throw new RuntimeException('Failed to store the uploaded photo.');
        }

        $newKey = $stored;

        try {
            $user->profile_photo_path = $newKey;
            $user->save();
        } catch (Throwable $e) {
            // The row write failed — the NEW object has no pointer to it and
            // must not be left behind. This is the one orphan the design
            // explicitly forbids (D3b): unlike step 6 below, this failure is
            // NOT logged-and-continued, because the request itself failed.
            Storage::delete($newKey);

            throw $e;
        }

        // After the row commits: delete the OLD object. Failure here is
        // logged, never fatal (design D3b) — the user's photo is already
        // correct, and showing an error over a change that already
        // succeeded is worse than at most one stale, unreachable object.
        if ($oldKey !== null) {
            try {
                Storage::delete($oldKey);
            } catch (Throwable $e) {
                Log::warning('Could not delete previous profile photo object.', [
                    'user_id' => $user->id,
                    'key' => $oldKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $fresh = $user->fresh();
        abort_if($fresh === null, 404);

        return (new ProfileResource($fresh->load('organization')))->response();
    }

    /**
     * DELETE /api/profile/photo
     *
     * Object-first, then null the column — exactly purgeSnapshots()'s
     * ordering (design D5): a failed object delete leaves the row intact
     * for a retryable second call, and S3/R2 deletes are idempotent, so a
     * second DELETE with nothing left to remove still succeeds (design
     * spec: "Removing a photo twice ... still 200").
     */
    public function destroy(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $key = $user->profile_photo_path;

        if ($key !== null) {
            Storage::delete($key);
            $user->profile_photo_path = null;
            $user->save();
        }

        $fresh = $user->fresh();
        abort_if($fresh === null, 404);

        return (new ProfileResource($fresh->load('organization')))->response();
    }

    /**
     * Magic-byte verdict — content only, never the declared Content-Type,
     * `getMimeType()`, or the client filename (SnapshotController.php:95
     * precedent). Returns the canonical extension for the detected format,
     * or null when neither JPEG nor PNG's magic bytes match.
     */
    private function verdictExtension(string $realPath): ?string
    {
        $handle = fopen($realPath, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 8);
        fclose($handle);

        if ($header === false || strlen($header) < 3) {
            return null;
        }

        // JPEG: FF D8 FF
        if (ord($header[0]) === 0xFF && ord($header[1]) === 0xD8 && ord($header[2]) === 0xFF) {
            return 'jpg';
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        $pngMagic = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";
        if (strlen($header) === 8 && $header === $pngMagic) {
            return 'png';
        }

        return null;
    }
}
