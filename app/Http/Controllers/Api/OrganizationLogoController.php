<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrganizationResource;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;
use App\Support\Uploads\ImageMagicBytes;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
 *   1. Inline `validate()` shape check — a fast rejection before any file work.
 *      Inline rather than a FormRequest, unlike the sibling: the rule set is
 *      one line and the message map exists only to force CODES over prose.
 *      This text used to say "FormRequest-level", and step 3 below still spoke
 *      of "the FormRequest's `max:`" — sending a reader looking for a
 *      `StoreOrganizationLogoRequest` that was never written.
 *   2. MAGIC BYTES, via the shared `ImageMagicBytes`. The claimed MIME type and
 *      the filename are both attacker-controlled; the header is not.
 *   3. The real byte cap, against `config('branding.logo.max_bytes')` and the
 *      actual size. The inline `max:` is a literal, not the policy.
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
    /**
     * The one prefix this endpoint will presign, and the security-critical
     * line in this class.
     *
     * Mirrors `ProfilePhotoUrlSigner::REQUIRED_PREFIX` for the same reason and
     * with the same force: `show()` is PUBLIC and mints a signed URL for an
     * object on the bucket that ALSO holds candidate proctoring snapshots
     * (`{org}/{participant}/{session}/{uuid}.jpg`). Today only `store()`
     * writes `logo_path`, and it writes this prefix. The guard is what holds
     * when that stops being true — a settings PATCH that accepts the column, a
     * portability import, a refactor — instead of an unauthenticated GET
     * handing out a candidate's webcam frame.
     */
    private const LOGO_PREFIX = 'organization-logos/';

    /**
     * The organization's logo, for anyone at all.
     *
     * PUBLIC AND ID-ADDRESSED, both deliberately, and both against the
     * doctrine the sibling endpoints in this controller follow — so the
     * departure is argued rather than assumed:
     *
     *   - Public, because the two readers that matter cannot present a token.
     *     An email client fetches a remote image through its own proxy
     *     (`EmailBranding`), and the candidate app paints the mark before the
     *     candidate has exchanged their link. A logo is brand material an
     *     organization already shows every candidate it invites; it is not
     *     tenant data.
     *   - Id-addressed, because `POST /organization/logo` resolves the org
     *     from the authenticated user and there is no authenticated user here.
     *     The id leaks nothing the response does not already publish, and a
     *     missing organization and a missing logo both answer 404 so this
     *     cannot be read as "does organization N exist".
     *
     * A REDIRECT, not a stream: the bytes travel from the object store to the
     * client directly, so a logo on every candidate page and in every message
     * does not hold a PHP worker open for each transfer.
     */
    // Scramble infers `200 application/json` from the return type, which is
    // the one thing this endpoint never answers. A spec that promises JSON
    // here is worse than no entry at all: both Nuxt apps generate their typed
    // client FROM this document, so the lie is what a consumer would be typed
    // against.
    #[IgnoreResponse(200)]
    #[Response(302, description: 'Redirect to a short-lived signed URL for the stored logo.')]
    public function show(int $organization): RedirectResponse
    {
        $key = Organization::query()->whereKey($organization)->value('logo_path');

        // One 404 for three different states — unknown organization, no logo,
        // and a key this endpoint refuses to sign. Distinguishing them is the
        // only thing an unauthenticated caller could learn here.
        if (! is_string($key) || ! str_starts_with($key, self::LOGO_PREFIX)) {
            abort(404);
        }

        $ttlMinutes = (int) config('branding.logo.url_ttl_minutes');

        $url = Storage::temporaryUrl($key, now()->addMinutes($ttlMinutes));

        // CLAMPED, not merely documented. `config/branding.php` says the cache
        // window "must stay comfortably below" the signature's lifetime, and
        // both values are independently env-overridable — so one
        // `BRANDING_LOGO_URL_TTL_MINUTES=5` in Railway, against the default
        // 600-second cache, produces a redirect the browser keeps replaying
        // after the signature it points at has died. That failure is a broken
        // image served from the client's own cache: nothing on the wire,
        // nothing in a log, and nothing in CI. A stated MUST that two
        // environment variables can violate is prose; `min()` is the invariant.
        //
        // Half the TTL rather than all of it, so a redirect handed out in the
        // last moments of its cache window still has a live signature behind it.
        $maxAge = min(
            (int) config('branding.logo.redirect_cache_seconds'),
            intdiv($ttlMinutes * 60, 2),
        );

        return redirect()->away($url)->withHeaders([
            'Cache-Control' => 'public, max-age='.$maxAge,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // The ACTING org, never `$request->user()->organization_id`
        // directly — see `OrganizationController`'s own docblock for why.
        $organization = Organization::findOrFail(app(TenantResolver::class)->getOrgId());

        $this->authorize('update', $organization);

        $request->validate([
            // Shape only. `mimes` checks the CLAIM — a browser sends whatever
            // MIME type it likes — so it is a cheap first filter, never the
            // decision. Step 2 is the decision.
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ], [
            // CODES, matching the ones the two checks below already throw. A
            // rule with no code answers in English prose under the same `logo`
            // key, and the backoffice — which cannot translate what it has no
            // key for — prints that sentence into an Italian field error.
            //
            // `logo.uploaded`, not `logo.file`, is the key that resolves when
            // the upload itself failed: Laravel's `file` rule DELEGATES to
            // `uploaded`. And that is the path production actually takes.
            // There is no `php.ini` in `api/docker/`, so PHP's compiled
            // `upload_max_filesize=2M` fires before Laravel ever sees the
            // size, and `config/branding.php` says an oversize logo "is almost
            // always an unoptimised export" — the ordinary case, not the edge.
            //
            // Only the SIZE errors map to too_large. A partial transfer or a
            // missing tmp directory is a genuine upload failure, and calling
            // it "too large" sends the operator shrinking a file that was
            // never the problem. Same resolution
            // `UpdateProfilePhotoRequest::messages()` already documents.
            'logo.required' => 'logo_required',
            'logo.file' => 'logo_invalid_image',
            'logo.mimes' => 'logo_invalid_image',
            'logo.uploaded' => $this->refusedForSize($request) ? 'logo_too_large' : 'logo_upload_failed',
            'logo.max' => 'logo_too_large',
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
            self::LOGO_PREFIX.$organization->id,
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
        // The ACTING org, never `$request->user()->organization_id`
        // directly — see `OrganizationController`'s own docblock for why.
        $organization = Organization::findOrFail(app(TenantResolver::class)->getOrgId());

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

    /**
     * PHP's own size refusals, which never reach Laravel's `max:` rule.
     *
     * `UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` mean the file WAS too
     * large; every other upload error means the transfer broke.
     */
    private function refusedForSize(Request $request): bool
    {
        $logo = $request->file('logo');

        return $logo instanceof UploadedFile
            && in_array($logo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    }
}
