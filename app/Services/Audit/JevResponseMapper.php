<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditVerdict;
use App\Enums\Audit\AuditOutcomeReason;
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
 *   - envelope unparseable / not an object              → AuditJudgeException
 *   - "usage" present but not an object, or "model"
 *     present but not a string                          → AuditJudgeException
 *   - a subject's ordinal key ENTIRELY absent (none of
 *     its 3 suffixed questions present)                  → omitted, reason VerdictMissing
 *   - some but not all of its 3 probabilities
 *     present/numeric                                     → omitted, reason VerdictUnparseable
 *   - all 3 present/numeric but one outside [0,1]          → omitted, reason ProbabilityOutOfDomain
 *   - answers present for keys never sent                 → ignored, logged at warning
 *
 * Every omitted subject's reason is recorded on
 * `AuditBatchResult::$omissions` (P3b resolution of the gap P1 flagged
 * forward: `$verdicts` alone told the caller a subject was missing, never
 * WHY). `AuditEvaluationJob` reads this channel to pick the correct
 * `malformed` row's `outcome_reason`.
 */
final class JevResponseMapper
{
    private const QUESTION_SUFFIXES = ['relevance', 'calibration', 'grounding'];

    /**
     * @param  array<string, int>  $keyMap  ordinal key => indicatorScoreId, exactly what was sent
     *
     * @throws AuditJudgeException when the envelope is unparseable, not an
     *                             object, or carries a malformed "usage"/"model" field
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

        $usage = $json['usage'] ?? null;
        if ($usage !== null && ! is_array($usage)) {
            throw new AuditJudgeException('TypeSafe response envelope carries a non-object "usage" field.');
        }

        $model = $json['model'] ?? null;
        if ($model !== null && ! is_string($model)) {
            throw new AuditJudgeException('TypeSafe response envelope carries a non-string "model" field.');
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
        $omissions = [];

        foreach ($keyMap as $key => $indicatorScoreId) {
            $relevance = $this->extractProbability($answers, "{$key}.relevance");
            $calibration = $this->extractProbability($answers, "{$key}.calibration");
            $grounding = $this->extractProbability($answers, "{$key}.grounding");

            if ($relevance === null && $calibration === null && $grounding === null) {
                $omissions[$indicatorScoreId] = AuditOutcomeReason::VerdictMissing;

                continue;
            }

            if ($relevance === null || $calibration === null || $grounding === null) {
                $omissions[$indicatorScoreId] = AuditOutcomeReason::VerdictUnparseable;

                continue;
            }

            if (! $this->inDomain($relevance) || ! $this->inDomain($calibration) || ! $this->inDomain($grounding)) {
                $omissions[$indicatorScoreId] = AuditOutcomeReason::ProbabilityOutOfDomain;

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
            omissions: $omissions,
            inputTokens: $this->extractTokenCount($usage, 'input_tokens'),
            outputTokens: $this->extractTokenCount($usage, 'output_tokens'),
            judgeModel: $model ?? (string) config('scoring.audit.judge_model', 'jev-1'),
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

    /**
     * Guarded extraction — never throws. `map()`'s own explicit `is_array($usage)`
     * check above is what turns a genuinely malformed "usage" field into a clean
     * `AuditJudgeException`; this helper only handles a well-formed object whose
     * individual token count is missing or the wrong type.
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function extractTokenCount(?array $usage, string $key): int
    {
        $value = $usage[$key] ?? null;

        return (is_int($value) || is_float($value)) ? (int) $value : 0;
    }
}
