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
 * Wire shape confirmed against the live TypeSafe API contract
 * (https://docs.typesafe.ai/api.md, read during the
 * scoring-audit-jev-prod-recovery follow-up — the original P1.0 verification
 * task, unavailable during the original apply session). `questions` is a
 * JSON OBJECT keyed by question id (`{"i1.relevance": {...}}`), NOT a list
 * of `{id, ...}` objects — the original placeholder guessed the latter and
 * every real call to TypeSafe was refused because of it.
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

            $questions["{$key}.relevance"] = [
                'type' => 'noul',
                'instructions' => "The excerpts in `state.indicators.{$key}.excerpts` describe behaviour of the kind `state.indicators.{$key}.indicator` names.",
            ];
            $questions["{$key}.calibration"] = [
                'type' => 'noul',
                'instructions' => "The excerpts support a rating of `state.indicators.{$key}.assigned_score` on `state.indicators.{$key}.scale`, rather than a materially lower one.",
            ];
            $questions["{$key}.grounding"] = [
                'type' => 'noul',
                'instructions' => "Every claim in `state.indicators.{$key}.explanation` is shown by the excerpts, with nothing asserted that they do not contain.",
            ];
        }

        $body = [
            'model' => (string) config('scoring.audit.judge_model', 'jev-latest'),
            'state' => [
                'competency_code' => $request->competencyCode,
                'indicators' => $indicators,
            ],
            // `json_encode([])` is `[]`, not `{}` — indistinguishable from a
            // populated map's own PHP array only once every subject is gone.
            // TypeSafe requires `questions` as a JSON OBJECT unconditionally
            // (see class docblock); an empty subjects list would otherwise
            // send the list shape the real endpoint refuses. `(object) []`
            // forces object encoding without touching the populated case,
            // which already carries string keys and encodes as `{...}` on
            // its own.
            'questions' => $questions === [] ? (object) [] : $questions,
        ];

        return [$body, $keyMap];
    }
}
