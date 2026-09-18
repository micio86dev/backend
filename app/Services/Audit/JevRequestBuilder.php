<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\AuditRequest;

/**
 * PURE: `AuditRequest` → `[array $body, array $keyMap]` (design D2/D3). No
 * facades, no `Log`, no config reads, no I/O — one of the two classes C-C's
 * containment strategy relies on: when the live TypeSafe wire shape turns
 * out to differ from the guess below, only this class and
 * `JevResponseMapper` change.
 *
 * Wire shape — UNVERIFIED (design.md C-C, "Wire payload sketch"): built from
 * the TypeSafe skill's described programming model (state / questions /
 * Noul), NOT the live API reference. No network access was available this
 * session to confirm the endpoint, envelope, or field names against
 * https://docs.typesafe.ai/api.md and
 * https://docs.typesafe.ai/primitives/noul.md.
 *
 * `indicatorScoreId` is a LOCAL correlation key and is NEVER serialized
 * (AD-6, design D2): each subject is assigned an ordinal question key
 * (`i1`, `i2`, …) scoped to the request, and the ordinal → `indicatorScoreId`
 * map is returned to the caller to keep in memory. Three Noul questions per
 * subject — `relevance`, `calibration`, `grounding` — mirror the validated
 * spike (design D2).
 */
final class JevRequestBuilder
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, int>} [$body, $keyMap]
     *                                                               $keyMap is ordinal key => indicatorScoreId
     */
    public function build(AuditRequest $request): array
    {
        $indicators = [];
        $questions = [];
        $keyMap = [];

        foreach ($request->subjects as $index => $subject) {
            $key = 'i'.($index + 1);
            $keyMap[$key] = $subject->indicatorScoreId;

            $indicators[] = [
                'key' => $key,
                'indicator' => $subject->indicatorText,
                'assigned_score' => $subject->score,
                'scale' => '1 to 5, where higher means stronger demonstration of the indicator',
                'explanation' => $subject->explanation,
                'excerpts' => $subject->excerpts,
            ];

            $questions[] = [
                'id' => "{$key}.relevance",
                'type' => 'noul',
                'instructions' => "The excerpts in `state.indicators.{$key}.excerpts` describe behaviour of the kind `state.indicators.{$key}.indicator` names.",
            ];
            $questions[] = [
                'id' => "{$key}.calibration",
                'type' => 'noul',
                'instructions' => "The excerpts support a rating of `state.indicators.{$key}.assigned_score` on `state.indicators.{$key}.scale`, rather than a materially lower one.",
            ];
            $questions[] = [
                'id' => "{$key}.grounding",
                'type' => 'noul',
                'instructions' => "Every claim in `state.indicators.{$key}.explanation` is shown by the excerpts, with nothing asserted that they do not contain.",
            ];
        }

        $body = [
            'model' => (string) config('scoring.audit.judge_model', 'jev-1'),
            'state' => [
                'competency_code' => $request->competencyCode,
                'indicators' => $indicators,
            ],
            'questions' => $questions,
        ];

        return [$body, $keyMap];
    }
}
