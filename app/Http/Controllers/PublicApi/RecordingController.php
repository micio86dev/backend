<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Models\InterviewRecording;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\PublicApi\ApiMode;
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
 * `GET /v1/interviews/{id}/recording`.
 *
 * No provider ingestion exists yet, so a live interview has no `InterviewRecording`
 * row and answers `404 recording_not_ready`. A dedicated controller because its scope
 * (`recordings:read`) differs from `InterviewController`'s (`interviews:read`).
 */
final class RecordingController extends Controller
{
    /** Signed URL lifetime in minutes. */
    private const URL_TTL_MINUTES = 10;

    // The explicit 200 description keeps Scramble from publishing an internal code comment.
    /**
     * Get the recording.
     *
     * Returns a signed audio URL for the interview's recording, with its format, duration and size. The URL is valid for 10 minutes. When no recording is available yet the request answers `404 recording_not_ready`; a malformed or unknown interview id answers `404 not_found`.
     */
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

        // `id desc`: the most recent recording wins should the one-per-participant constraint ever relax.
        $recording = InterviewRecording::where('participant_id', $participant->id)
            ->orderBy('id', 'desc')
            ->first();

        if ($recording === null) {
            return Problem::make($request, 404, 'recording_not_ready', 'Recording not ready');
        }

        // The object key must live under this organization's own prefix; otherwise it is refused like a missing row.
        $requiredPrefix = 'recordings/'.$organization->id.'/';

        if (! str_starts_with($recording->object_key, $requiredPrefix)) {
            return Problem::make($request, 404, 'recording_not_ready', 'Recording not ready');
        }

        $expiresAt = now()->addMinutes(self::URL_TTL_MINUTES);

        // Logged with the tenant context, which the generic 500 pipeline does not carry.
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

    /** Resolved explicitly rather than trusted: the tenant middleware already answers 401 first. */
    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

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

        // Mode-scoped: a test key never fetches a live interview's recording, nor the reverse.
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        return Participant::where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->wherePublicId($bareId)
            ->first();
    }
}
