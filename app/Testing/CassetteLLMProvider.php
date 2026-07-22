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
 * REQ: CassetteLLMProvider (C9 D8 CW1 — multi-competency golden-cassette tests)
 */
final class CassetteLLMProvider implements LLMProvider
{
    /** @var array<int, array{prompt: string, options: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param  array<string, string>  $cassette  Map of competency_code → JSON response string.
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
     * @throws \LogicException When no cassette entry exists for the requested competency_code.
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

        return new LLMResponse(
            content: $this->cassette[$competencyCode],
            model: $this->model,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            finishReason: $this->finishReason,
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
