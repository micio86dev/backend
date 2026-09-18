<?php

declare(strict_types=1);

namespace App\Testing;

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditVerdict;
use App\Exceptions\Audit\AuditJudgeException;

/**
 * Fake `AuditJudge` for testing (proposal AD-5, design D3) — mirrors
 * `FakeLLMProvider` exactly: canned verdicts, a `getCalls()` recorder,
 * `callCount()`, `httpRequestCount()` returning a hard `0`.
 *
 * Registered in the container for APP_ENV=testing (see
 * `AppServiceProvider`). The recorder is LOAD-BEARING for the proposal's own
 * success criteria — "was never passed to the judge (asserted on the fake's
 * recorded calls)" — so it records the full `AuditRequest`, not a summary.
 *
 * `throwOn(string $competencyCode)` supports the mid-run per-competency
 * failure test (P3b, out of this batch's scope) without an HTTP fake.
 */
final class FakeAuditJudge implements AuditJudge
{
    /** @var list<AuditRequest> */
    private array $calls = [];

    /** @var list<string> */
    private array $throwOnCompetencyCodes = [];

    public function __construct(
        private readonly float $defaultSupportProbability = 0.9,
        private readonly string $judgeModel = 'fake-jev-judge-v1',
        private readonly int $inputTokens = 100,
        private readonly int $outputTokens = 50,
        private readonly int $latencyMs = 10,
    ) {}

    /**
     * Configure this fake to throw `AuditJudgeException` when `judge()` is
     * called for the given competency code — supports testing per-competency
     * `Throwable` isolation (design D6) without an HTTP fake.
     */
    public function throwOn(string $competencyCode): void
    {
        $this->throwOnCompetencyCodes[] = $competencyCode;
    }

    public function judge(AuditRequest $request): AuditBatchResult
    {
        $this->calls[] = $request;

        if (in_array($request->competencyCode, $this->throwOnCompetencyCodes, true)) {
            throw new AuditJudgeException(
                message: "FakeAuditJudge configured to throw for competency {$request->competencyCode}.",
                retryable: true,
            );
        }

        $verdicts = [];
        foreach ($request->subjects as $subject) {
            $verdicts[$subject->indicatorScoreId] = new AuditVerdict(
                indicatorScoreId: $subject->indicatorScoreId,
                supportProbability: $this->defaultSupportProbability,
                questionProbabilities: [
                    'relevance' => $this->defaultSupportProbability,
                    'calibration' => $this->defaultSupportProbability,
                    'grounding' => $this->defaultSupportProbability,
                ],
            );
        }

        return new AuditBatchResult(
            verdicts: $verdicts,
            omissions: [],
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            judgeModel: $this->judgeModel,
            latencyMs: $this->latencyMs,
        );
    }

    /**
     * Return the number of times judge() was called.
     */
    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * Return the number of real HTTP requests made.
     * This is ALWAYS 0 — the fake never makes HTTP requests. A non-zero
     * return here would indicate a test bug (bypassed fake).
     */
    public function httpRequestCount(): int
    {
        return 0;
    }

    /**
     * Return the recorded calls for assertion in tests — the FULL
     * `AuditRequest`, not a summary (load-bearing for the "never passed to
     * the judge" success criteria).
     *
     * @return list<AuditRequest>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }
}
