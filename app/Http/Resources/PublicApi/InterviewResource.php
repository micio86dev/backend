<?php

declare(strict_types=1);

namespace App\Http\Resources\PublicApi;

use App\Models\Participant;
use App\PublicApi\Serializers\InterviewSerializer;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin HTTP wrapper around `App\PublicApi\Serializers\InterviewSerializer`
 * for `POST /v1/interviews`, `GET /v1/interviews` and
 * `GET /v1/interviews/{id}` (public-api step 5).
 *
 * `#[SchemaName('PublicInterview')]` (step 5 review follow-up, Part A) —
 * no OTHER `InterviewResource` class exists in this codebase today, so
 * this class does not currently collide with anything, but naming it
 * explicitly, alongside its `PublicOrganization`/`PublicProject` siblings,
 * keeps every public-API resource schema distinctly and predictably named
 * regardless of what a future admin-side class happens to be called.
 *
 * @mixin Participant
 */
#[SchemaName('PublicInterview')]
final class InterviewResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * `$progress`/`$recordingReady`, when given, are passed straight
     * through to `InterviewSerializer::toArray()`'s own same-named
     * parameters — see that method's docblock (batched per-page, never
     * per-row: `progressForMany()`/`recordingReadyForMany()`, gga review
     * step 6 follow-up finding 5).
     *
     * @param  list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>|null  $progress
     */
    public function __construct(
        Participant $resource,
        private readonly bool $expandProject = false,
        private readonly ?array $progress = null,
        private readonly ?bool $recordingReady = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * Explicit array-shape `@return`, for human/IDE readers — mirrors
     * `InterviewSerializer::toArray()`'s own docblock one level down.
     * Scramble itself does NOT read this: its schema inference for a
     * `JsonResource` (`JsonResourceTypeToSchema`) resolves `toArray()`'s
     * return type by tracing the ACTUAL METHOD BODY via
     * `ReferenceTypeResolver`, all the way down through
     * `InterviewSerializer::toArray()`'s own literal array construction —
     * never this (or that method's own) `@return` docblock. `hosted_url`'s
     * exported TYPE (`string|null`, matching the literal below) is
     * documented instead by `App\Support\Scramble\
     * InterviewHostedUrlNullableExtension` — see that class's own docblock
     * for why a docblock alone was empirically proven not to work here.
     *
     * @return array{id: string, project_id: string, project?: array<string, mixed>, candidate_ref: string, email: string, display_name: string, role_code: string|null, language: string, status: string, livemode: bool, metadata: array<string, string>, exit_redirect_url: string|null, hosted_url: string|null, progress: list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>, started_at: string|null, completed_at: string|null, transcript_ready: bool, scoring_ready: bool, recording_ready: bool, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;

        return InterviewSerializer::toArray($participant, $this->expandProject, $this->progress, $this->recordingReady);
    }
}
