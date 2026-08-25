<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin EvaluationResource — Evaluation scope (C11 D5).
 *
 * Wraps the array produced by `AdminEvaluationSerializer::serialize()` for
 * `GET /api/participants/{id}/evaluation` (requires lifecycle ===
 * `completato`, D2). Top-level shape mirrors
 * `esempio-report-valutazione.json` — keyed by competency code, no envelope.
 *
 * SCOPE BOUNDARY: Evaluation-scope ONLY — MUST NEVER carry Transcript fields
 * (`utterances`, `speaker`). The underlying array is produced exclusively by
 * AdminEvaluationSerializer, which has no access to
 * InterviewSession/Utterance data, so this is structural, not just a filter.
 *
 * `meta.scoring` (D7, bars-full-scale-1-5) is emitted as a SIBLING of `data`,
 * via `with()` — never merged into the competency map, which is keyed by
 * competency code and frameworks are custom/versioned per tenant (a reserved
 * key inside that map would risk colliding with a tenant-authored code).
 */
class EvaluationResource extends JsonResource
{
    /**
     * @param  array<string, array{score: float|null, reliability: string, behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, unassessable_reason: string|null}>, unscorable_reason: string|null}>  $resource
     * @param  array{prompt_version: string, model_version: string, framework_version: string}|null  $scoringMeta
     */
    public function __construct(
        array $resource,
        private readonly ?array $scoringMeta = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if ($this->scoringMeta === null) {
            return [];
        }

        return ['meta' => ['scoring' => $this->scoringMeta]];
    }

    /**
     * Force JSON_PRESERVE_ZERO_FRACTION on the FIRST encode of this resource.
     *
     * Without it, PHP's json_encode() silently drops the fractional part of
     * a whole-number float (json_encode(4.0) === "4", not "4.0") — Laravel's
     * JsonResponse default encoding options do not set this flag either.
     * `score` is a float per spec and the reference shape
     * (esempio-report-valutazione.json) shows "score": 4.0 literally; losing
     * the decimal on the wire would (a) diverge from that reference and
     * (b) make an integer-vs-float distinction ambiguous to API consumers
     * that treat "4" and "4.0" differently (e.g. strict client-side schemas).
     *
     * MUST be set here, not in withResponse(): ResourceResponse::toResponse()
     * builds the JsonResponse (encoding with jsonOptions()) BEFORE it calls
     * withResponse() — by then the float has already round-tripped through a
     * lossy encode/decode via JsonResponse::setEncodingOptions()
     * (Symfony's implementation re-decodes the ALREADY-serialized string).
     * Setting the flag late is provably too late; it must be part of the
     * options used for the original encode.
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}
