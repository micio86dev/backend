<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\Uploads\ImageMagicBytes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The organization's logo.
 *
 * Mirrors `ProfilePhotoController`'s ordering deliberately, because that
 * ordering is the safety property and not a style: nothing reaches the disk
 * until every check has passed, and nothing is deleted until the row that
 * replaces it has committed.
 *
 *   1. FormRequest-level shape check — a fast rejection before any file work.
 *   2. MAGIC BYTES, via the shared `ImageMagicBytes`. The claimed MIME type and
 *      the filename are both attacker-controlled; the header is not.
 *   3. The real byte cap, against `config('branding.logo.max_bytes')` and the
 *      actual size. The FormRequest's `max:` is a literal, not the policy.
 *   4. Decoded dimensions. The byte cap alone does not stop a decompression
 *      bomb — a few kilobytes of PNG can decode to hundreds of megabytes.
 *   5. Store, with NO `disk()` argument: the disk comes from the single
 *      storage configuration point (`SingleStorageDiskArchTest` enforces it).
 *   6. Write the row. If that throws, delete the NEW object before re-throwing
 *      — otherwise it is an orphan nothing points at.
 *   7. Only after the row commits, delete the OLD object. Logged and never
 *      fatal: the new logo is already live, and failing the request over a
 *      leftover file would tell an operator their upload failed when it did
 *      not.
 */
final class OrganizationLogoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = Organization::findOrFail($user->organization_id);

        $this->authorize('update', $organization);

        $request->validate([
            // Shape only. `mimes` checks the CLAIM — a browser sends whatever
            // MIME type it likes — so it is a cheap first filter, never the
            // decision. Step 2 is the decision.
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('logo');

        $extension = ImageMagicBytes::extensionFor($file->getRealPath());

        if ($extension === null) {
            // One code for every rejection reason. Distinguishing "not an
            // image" from "an SVG" from "a PHP file" would tell an uploader
            // which disguise got furthest.
            throw ValidationException::withMessages(['logo' => ['logo_invalid_image']]);
        }

        $maxBytes = (int) config('branding.logo.max_bytes');

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['logo' => ['logo_too_large']]);
        }

        $dimensions = @getimagesize($file->getRealPath());
        $maxDimension = (int) config('branding.logo.max_dimension');

        if ($dimensions === false || $dimensions[0] > $maxDimension || $dimensions[1] > $maxDimension) {
            throw ValidationException::withMessages(['logo' => ['logo_dimensions_invalid']]);
        }

        $oldKey = $organization->logo_path;
        $newKey = Storage::putFileAs(
            'organization-logos/'.$organization->id,
            $file,
            (string) Str::uuid().'.'.$extension,
        );

        if ($newKey === false) {
            throw new RuntimeException('Failed to store the uploaded logo.');
        }

        try {
            $organization->logo_path = $newKey;
            $organization->save();
        } catch (Throwable $e) {
            // The row write failed, so nothing points at the new object.
            // Deleting it here is what stops a failed upload leaving a file
            // behind on every retry.
            Storage::delete($newKey);

            throw $e;
        }

        if ($oldKey !== null && $oldKey !== $newKey) {
            $this->deleteQuietly($oldKey, $organization->id);
        }

        return (new OrganizationResource($organization->fresh()))->response();
    }

    /**
     * Remove the logo, returning the organization to the product's own mark.
     *
     * Absent is a supported state, not a broken one — DESIGN.md's Quint logo is
     * what renders when none is configured — so this is a legitimate action
     * rather than an undo.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = Organization::findOrFail($user->organization_id);

        $this->authorize('update', $organization);

        $oldKey = $organization->logo_path;

        // The ROW first, the object second, and in that order for the same
        // reason as `store()`: a cleared column with a leftover file is
        // invisible to everyone, while a deleted file still referenced by a row
        // is a broken image on every page.
        $organization->logo_path = null;
        $organization->save();

        if ($oldKey !== null) {
            $this->deleteQuietly($oldKey, $organization->id);
        }

        return (new OrganizationResource($organization->fresh()))->response();
    }

    /**
     * Best-effort object removal.
     *
     * Never fatal. By the time this runs the database already tells the truth,
     * so failing the request would report a failure that did not happen. The
     * log line is what makes the orphan findable later.
     */
    private function deleteQuietly(string $key, int $organizationId): void
    {
        try {
            Storage::delete($key);
        } catch (Throwable $e) {
            Log::warning('Failed to delete a replaced organization logo', [
                'organization_id' => $organizationId,
                'exception' => $e::class,
            ]);
        }
    }
}
