<?php

declare(strict_types=1);

namespace App\Testing;

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;

/**
 * Cassette-based LLM provider for multi-competency golden-cassette tests (C9 D8 CW1).
 *
 * Keyed by competency_code (NOT by call-order position) so that cassette entries remain
 * resilient to reordering of competency processing — a call-order-keyed cassette is
 * brittle if the processing sequence ever changes.
 *
 * Usage:
 *   $cassette = new CassetteLLMProvider([
 *       'COL' => '{"behaviors": [...]}',  // COL LLM response JSON
 *       'SLF' => '{"behaviors": [...]}',  // SLF LLM response JSON
 *   ]);
 *   $this->app->instance(LLMProvider::class, $cassette);
 *
 * complete() reads $options['competency_code'] to look up the pre-configured response.
 * Throws \LogicException if the cassette has no entry for the requested competency_code.
 *
 * Per-call variation (scoring-failure-containment D8): a bare `string` cassette
 * entry cannot vary `finishReason` across successive calls to the SAME
 * competency_code, so it cannot express "first call truncated, second call
 * complete" — needed by the A1 truncated cassette and B1's truncation-retry
 * test. The value type therefore widens to
 * `string|CassetteResponse|list<CassetteResponse>`:
 *   - bare `string`            → today's meaning, unchanged.
 *   - `CassetteResponse`       → one call, explicit `finishReason` override.
 *   - `list<CassetteResponse>` → consumed IN CALL ORDER, per competency_code.
 * A `list` entry tracks its own call-order cursor per competency_code — a
 * second competency_code's calls never advance a different competency's
 * cursor.
 *
 * REQ: CassetteLLMProvider (C9 D8 CW1 — multi-competency golden-cassette tests)
 */
final class CassetteLLMProvider implements LLMProvider
{
    /** @var array<int, array{prompt: string, options: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, int> Per-competency_code call-order cursor for list<CassetteResponse> entries. */
    private array $callOrderCursors = [];

    /**
     * @param  array<string, string|CassetteResponse|list<CassetteResponse>>  $cassette  Map of
     *   competency_code → JSON response string, a single CassetteResponse, or a
     *   list of CassetteResponse consumed in call order.
     * @param  string  $model  Model name to report in the LLMResponse.
     */
    public function __construct(
        private readonly array $cassette,
        private readonly string $model = 'cassette-llm-provider-v1',
        private readonly int $inputTokens = 200,
        private readonly int $outputTokens = 100,
        private readonly string $finishReason = 'stop',
    ) {}

    /**
     * Return the pre-configured cassette response for the competency_code in $options.
     *
     * @param  array<string, mixed>  $options  Must contain 'competency_code'.
     *
     * @throws \LogicException When no cassette entry exists for the requested competency_code,
     *   or when a list<CassetteResponse> entry is exhausted for that competency_code.
     */
    public function complete(string $prompt, array $options = []): LLMResponse
    {
        $this->calls[] = ['prompt' => $prompt, 'options' => $options];

        $competencyCode = (string) ($options['competency_code'] ?? '');

        if (! array_key_exists($competencyCode, $this->cassette)) {
            throw new \LogicException(
                "CassetteLLMProvider: no cassette entry for competency_code [{$competencyCode}]. "
                .'Available keys: ['.implode(', ', array_keys($this->cassette)).'].'
            );
        }

        $entry = $this->cassette[$competencyCode];

        if (is_array($entry)) {
            $callIndex = $this->callOrderCursors[$competencyCode] ?? 0;
            $this->callOrderCursors[$competencyCode] = $callIndex + 1;

            if (! array_key_exists($callIndex, $entry)) {
                throw new \LogicException(
                    "CassetteLLMProvider: list<CassetteResponse> for competency_code [{$competencyCode}] "
                    ."exhausted at call index [{$callIndex}] — only ".count($entry).' response(s) configured.'
                );
            }

            return $this->fromCassetteResponse($entry[$callIndex]);
        }

        if ($entry instanceof CassetteResponse) {
            return $this->fromCassetteResponse($entry);
        }

        return new LLMResponse(
            content: $entry,
            model: $this->model,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            finishReason: $this->finishReason,
        );
    }

    private function fromCassetteResponse(CassetteResponse $response): LLMResponse
    {
        return new LLMResponse(
            content: $response->content,
            model: $this->model,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            finishReason: $response->finishReason,
            // Mirrors AnthropicLLMProvider::parseResponse()'s derivation exactly
            // (D3) — the cassette is a stand-in for a real provider, so it must
            // derive `truncated` the same way, not hardcode it separately.
            truncated: $response->finishReason === 'max_tokens',
        );
    }

    /**
     * Return the number of times complete() was called.
     */
    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * Return the number of real HTTP requests made.
     * Always 0 — cassette never makes HTTP requests.
     */
    public function httpRequestCount(): int
    {
        return 0;
    }

    /**
     * Return the recorded calls for assertion in tests.
     *
     * @return array<int, array{prompt: string, options: array<string, mixed>}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Return the competency codes that were actually requested during this test run.
     *
     * @return list<string>
     */
    public function getRequestedCompetencyCodes(): array
    {
        return array_values(array_map(
            static fn (array $call): string => (string) ($call['options']['competency_code'] ?? ''),
            $this->calls
        ));
    }
}
