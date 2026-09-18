<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditRequest;
use App\Exceptions\Audit\AuditJudgeException;
use Illuminate\Support\Facades\Http;

/**
 * Production `AuditJudge` binding — TypeSafe's Jev (proposal AD-5, design
 * D3). Mirrors `AnthropicLLMProvider` structurally: raw `Http`, NO
 * third-party SDK — therefore NO D25 dependency to pin.
 *
 * Endpoint path and wire shape — UNVERIFIED (design.md C-C): no network
 * access was available this session to confirm them against
 * https://docs.typesafe.ai/api.md and
 * https://docs.typesafe.ai/primitives/noul.md. `self::JUDGE_PATH` is a
 * placeholder pending that verification task.
 *
 * The one deliberate divergence from `AnthropicLLMProvider`: the response
 * body is NOT put into the exception message. The request body here
 * contains verbatim candidate speech, and a vendor error frequently echoes
 * the request — status code only, mirroring `ScoreEvaluationJob`'s own
 * stated rule for the sibling case (design D3).
 */
final class TypesafeJevJudge implements AuditJudge
{
    private const JUDGE_PATH = '/v1/judgments';

    public function __construct(
        private readonly JevRequestBuilder $builder = new JevRequestBuilder,
        private readonly JevResponseMapper $mapper = new JevResponseMapper,
    ) {}

    public function judge(AuditRequest $request): AuditBatchResult
    {
        [$body, $keyMap] = $this->builder->build($request);

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout((int) config('scoring.audit.timeout_seconds', 30))
                ->post($this->buildUrl(), $body);
        } catch (\Throwable $e) {
            throw new AuditJudgeException(
                message: 'TypeSafe transport error: '.$e->getMessage(),
                retryable: true,
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new AuditJudgeException(
                message: "TypeSafe returned HTTP {$response->status()}",
                // 5xx is a vendor-side failure; 429 (rate limited) and 408
                // (request timeout) are also transient and safe to retry
                // (P3b: this flag had zero consumers through P3a, confirmed
                // by reading every AuditJudgeException call site before
                // fixing the classification — a functionally inert bug is
                // still a wrong one once something starts reading it).
                retryable: $response->status() >= 500 || in_array($response->status(), [408, 429], true),
            );
        }

        $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

        return $this->mapper->map($response->json(), $keyMap, $latencyMs);
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.(string) config('scoring.audit.api_key', ''),
            'content-type' => 'application/json',
        ];
    }

    private function buildUrl(): string
    {
        $base = rtrim((string) config('scoring.audit.base_url', 'https://api.typesafe.ai'), '/');

        return $base.self::JUDGE_PATH;
    }
}
