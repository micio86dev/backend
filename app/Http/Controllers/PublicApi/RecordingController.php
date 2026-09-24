<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\InterviewRecording;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantResolver;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `GET /v1/interviews/{id}/recording` — BEAI Public API (public-api step 6,
 * G-01, SPEC.md §3.3 "Audio only"). No provider ingestion exists yet (G-01,
 * open) — a live interview has no `InterviewRecording` row, and this
 * endpoint answers `404 recording_not_ready`.
 *
 * A separate controller from `InterviewController` (public-api step 5's own
 * `SessionTokenController` precedent: one dedicated class per action with a
 * DIFFERENT scope requirement — `recordings:read`, never `interviews:read`)
 * — duplicates the organization/participant resolution rather than sharing
 * `InterviewController`'s private helpers, which is the deliberate,
 * established boundary between these controllers.
 */
final class RecordingController extends Controller
{
    /**
     * Signed URL TTL (SPEC.md §3.3 "signed, TTL 10 min, single org").
     */
    private const URL_TTL_MINUTES = 10;

    // `#[Response(200, description: ...)]` (step 6 review follow-up,
    // finding 14) — Scramble's own inference picked the nearest preceding
    // CODE COMMENT above `Storage::disk()->temporaryUrl()`'s
    // `JSON_PRESERVE_ZERO_FRACTION` call (an internal implementation note,
    // meaningless to an API consumer) as this response's description, the
    // SAME quirk `InterviewController::index()`'s own docblock already
    // documents for `created_after`/`created_before`. This attribute
    // overrides ONLY the description (no `type:` argument — the inferred
    // schema itself was already correct) with a caller-facing one.
    #[Response(200, description: 'A ready recording: a signed audio URL, its format, duration and size.')]
    #[Response(404, description: 'Not found, or recording not ready (code=recording_not_ready).', type: Problem::PROBLEM_SHAPE)]
    #[Response(500, description: 'The signed URL could not be generated (code=internal_error).', type: Problem::PROBLEM_SHAPE)]
    public function show(Request $request, string $interview): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $participant = $this->resolveParticipant($interview, $organization);

        if ($participant === null) {
            abort(404);
        }

        // A participant has at most ONE current recording, enforced at the
        // DATABASE level: `interview_recordings_participant_id_unique`
        // (the owning migration's own docblock, "One row per participant
        // enrolment") makes a second row for the same `participant_id`
        // impossible to insert, not merely unlikely — verified directly in
        // `RecordingTest` (step 6 review follow-up, finding 8). `orderBy`
        // is added regardless, as cheap defence in depth: a bare `first()`
        // with no ORDER BY is fragile on its own terms (no engine
        // guarantees row order without one, even over a single matching
        // row) and costs nothing to make explicit — `id desc` documents
        // the INTENDED tie-break ("most recently created wins") for if
        // this constraint is ever relaxed later, rather than leaving that
        // decision to be rediscovered from scratch at that point.
        $recording = InterviewRecording::where('participant_id', $participant->id)
            ->orderBy('id', 'desc')
            ->first();

        if ($recording === null) {
            return Problem::make($request, 404, 'recording_not_ready', 'Recording not ready');
        }

        // Structural org-binding guard (SPEC.md §3.3 "single org") — the
        // object key itself must live under this organization's own
        // prefix, independent of the row already being tenant-scoped by
        // `organization_id`: a key that does NOT start with
        // `recordings/{org_id}/` is refused exactly like a missing row,
        // never presigned. Mirrors `App\Support\ProfilePhotoUrlSigner`'s
        // own "the prefix guard is the security-critical line" discipline.
        $requiredPrefix = 'recordings/'.$organization->id.'/';

        if (! str_starts_with($recording->object_key, $requiredPrefix)) {
            return Problem::make($request, 404, 'recording_not_ready', 'Recording not ready');
        }

        $expiresAt = now()->addMinutes(self::URL_TTL_MINUTES);

        // Guarded (step 6 review follow-up, finding 9): an unreachable/
        // misconfigured storage backend, or a driver that refuses to sign
        // this particular key, must not reach the caller as an unhandled
        // exception. `App\Support\PublicApi\PublicApiExceptionRenderer`'s
        // own `default` arm already converts ANY uncaught `Throwable` on
        // `/api/v1/*` into exactly `500 internal_error`, Problem-shaped
        // (verified directly by this method's own RED/GREEN cycle — the
        // response shape was already correct with no guard here at all) —
        // so this `try`/`catch` is not what makes the RESPONSE correct.
        // What it adds is a Log::error line carrying THIS request's own
        // organization/participant context, which the generic
        // report()-then-render() pipeline's exception log does not — the
        // same "our own 500s deserve tenant-scoped diagnostics, not just a
        // bare stack trace" discipline `InterviewEventRecorder`'s own
        // structured failure log already applies at its seam.
        try {
            $url = Storage::disk()->temporaryUrl($recording->object_key, $expiresAt);
        } catch (Throwable $e) {
            Log::error('public-api: failed to generate a signed recording URL', [
                'organization_id' => $organization->id,
                'participant_id' => $participant->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return Problem::make($request, 500, 'internal_error', 'Could not generate a signed recording URL');
        }

        // JSON_PRESERVE_ZERO_FRACTION (gga finding 2), via the shared
        // PublicApiJson::response() — `duration_seconds` is stored/cast as
        // a plain `int` today (never a whole-number float PHP's own
        // json_encode() would otherwise truncate), so this call carries no
        // OBSERVABLE effect for this field right now, but keeps this
        // endpoint on the same uniform, future-proof responder every other
        // `/v1` action uses rather than being the one call site someone
        // has to remember to special-case later.
        return PublicApiJson::response([
            'interview_id' => PublicId::encode($participant),
            'kind' => 'audio',
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'format' => $recording->format,
            'duration_seconds' => $recording->duration_seconds,
            'size_bytes' => $recording->size_bytes,
        ]);
    }

    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        // Unreachable through the real /v1 stack (PublicApiTenantContext
        // already 401s first) — resolved explicitly rather than trusted,
        // same defensive discipline as InterviewController::resolveOrganization().
        if ($organization === null) {
            abort(404);
        }

        return $organization;
    }

    private function resolveParticipant(string $rawId, Organization $organization): ?Participant
    {
        $bareId = PublicId::decode($rawId, Participant::publicIdPrefix());

        if ($bareId === null) {
            return null;
        }

        return Participant::where('organization_id', $organization->id)
            ->wherePublicId($bareId)
            ->first();
    }
}
