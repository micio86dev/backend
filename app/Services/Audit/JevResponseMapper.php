<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditVerdict;
use App\Exceptions\Audit\AuditJudgeException;
use Illuminate\Support\Facades\Log;

/**
 * PURE: `array $json, array $keyMap, int $latencyMs` → `AuditBatchResult`
 * (design D3), unit-testable without an HTTP fake. Deciding what
 * `malformed` means lives entirely here — see the mapping rules table below.
 *
 * Response envelope shape — UNVERIFIED (design.md C-C): a flat `answers`
 * map keyed by question id (`"i1.relevance" => 0.9`), the natural
 * continuation of the request envelope's own question-id namespace and
 * Noul's "probability of yes" contract. No network access was available
 * this session to confirm the live TypeSafe response shape against
 * https://docs.typesafe.ai/api.md.
 *
 * Mapping rules:
 *   - envelope unparseable / not an object            → AuditJudgeException
 *   - a subject's ordinal key absent from the answers  → omitted from verdicts
 *   - any of its three probabilities absent/non-numeric → omitted from verdicts
 *   - any probability outside [0,1]                     → omitted from verdicts
 *   - answers present for keys never sent               → ignored, logged at warning
 *
 * KNOWN GAP, noted rather than silently resolved: this class distinguishes
 * three structurally different reasons a subject can be omitted
 * (`verdict_missing` / `verdict_unparseable` / `probability_out_of_domain`,
 * design D3/C-E), but `AuditBatchResult` — exactly as specified by design D2
 * — carries only the SURVIVING `$verdicts`, with no channel for WHY an
 * omitted subject was omitted. `AuditEvaluationJob` (P3b, out of this
 * batch's scope) needs that per-subject reason to pick the correct
 * `AuditOutcomeReason::Verdict*` case. This gap must be resolved when P3b is
 * implemented — either by extending `AuditBatchResult`/`AuditVerdict` with a
 * reasons channel, or by another mechanism — and is flagged here rather than
 * silently designed around, per this batch's scope (P1 only).
 */
final class JevResponseMapper
{
    private const QUESTION_SUFFIXES = ['relevance', 'calibration', 'grounding'];

    /**
     * @param  array<string, int>  $keyMap  ordinal key => indicatorScoreId, exactly what was sent
     *
     * @throws AuditJudgeException when the envelope is unparseable or not an object
     */
    public function map(mixed $json, array $keyMap, int $latencyMs): AuditBatchResult
    {
        if (! is_array($json)) {
            throw new AuditJudgeException('TypeSafe response envelope is not an object.');
        }

        $answers = $json['answers'] ?? null;

        if (! is_array($answers)) {
            throw new AuditJudgeException('TypeSafe response envelope carries no "answers" object.');
        }

        $sentQuestionIds = [];
        foreach (array_keys($keyMap) as $key) {
            foreach (self::QUESTION_SUFFIXES as $suffix) {
                $sentQuestionIds["{$key}.{$suffix}"] = true;
            }
        }

        $unsolicited = array_diff(array_keys($answers), array_keys($sentQuestionIds));
        if ($unsolicited !== []) {
            Log::warning('TypeSafe response carried answers for questions never sent.', [
                'unsolicited_question_ids' => array_values($unsolicited),
            ]);
        }

        $verdicts = [];

        foreach ($keyMap as $key => $indicatorScoreId) {
            $relevance = $this->extractProbability($answers, "{$key}.relevance");
            $calibration = $this->extractProbability($answers, "{$key}.calibration");
            $grounding = $this->extractProbability($answers, "{$key}.grounding");

            if ($relevance === null || $calibration === null || $grounding === null) {
                continue;
            }

            if (! $this->inDomain($relevance) || ! $this->inDomain($calibration) || ! $this->inDomain($grounding)) {
                continue;
            }

            $raw = ['relevance' => $relevance, 'calibration' => $calibration, 'grounding' => $grounding];

            $verdicts[$indicatorScoreId] = new AuditVerdict(
                indicatorScoreId: $indicatorScoreId,
                supportProbability: min($raw),
                questionProbabilities: $raw,
            );
        }

        return new AuditBatchResult(
            verdicts: $verdicts,
            inputTokens: (int) ($json['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($json['usage']['output_tokens'] ?? 0),
            judgeModel: (string) ($json['model'] ?? config('scoring.audit.judge_model', 'jev-1')),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function extractProbability(array $answers, string $questionId): ?float
    {
        $value = $answers[$questionId] ?? null;

        return (is_int($value) || is_float($value)) ? (float) $value : null;
    }

    private function inDomain(float $probability): bool
    {
        return $probability >= 0.0 && $probability <= 1.0;
    }
}
