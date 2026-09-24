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
     * `$progress`, when given, is passed straight through to
     * `InterviewSerializer::toArray()`'s own `$progress` parameter — see
     * that method's docblock (gga finding 4: batched per-page, never
     * per-row).
     *
     * @param  list<array{competency_code: string, answers: list<array{question_index: int, answered_at: string}>}>|null  $progress
     */
    public function __construct(
        Participant $resource,
        private readonly bool $expandProject = false,
        private readonly ?array $progress = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Participant $participant */
        $participant = $this->resource;

        return InterviewSerializer::toArray($participant, $this->expandProject, $this->progress);
    }
}
