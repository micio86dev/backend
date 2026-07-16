<?php

namespace App\Testing;

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;

/**
 * Fake LLM provider for testing (D36 — cost-aware AI testing infrastructure).
 *
 * Registered in the Laravel service container for APP_ENV=testing
 * (see AppServiceProvider). All standard unit/integration tests use this
 * implementation — zero HTTP requests to any external AI API endpoint.
 *
 * For tests that need realistic LLM responses, use the cassette pattern:
 * see CassetteFactory in tests/TestCase.php and tests/Fixtures/cassettes/.
 *
 * Usage in tests:
 *   $fake = new FakeLLMProvider(content: 'Score: 4.0', model: 'test-model');
 *   $response = $fake->complete('Evaluate COM competency.');
 *   expect($response->content)->toBe('Score: 4.0');
 *
 * Track calls:
 *   expect($fake->callCount())->toBe(1);
 *   expect($fake->httpRequestCount())->toBe(0); // always 0 — no HTTP
 */
final class FakeLLMProvider implements LLMProvider
{
    /** @var array<int, array{prompt: string, options: array<string, mixed>}> */
    private array $calls = [];

    public function __construct(
        private readonly string $content = 'Fake LLM response for testing.',
        private readonly string $model = 'fake-llm-provider-v1',
        private readonly int $inputTokens = 100,
        private readonly int $outputTokens = 50,
        private readonly string $finishReason = 'stop',
    ) {}

    /**
     * Return the pre-configured fake response without making any HTTP request.
     *
     * @param  array<string, mixed> $options
     */
    public function complete(string $prompt, array $options = []): LLMResponse
    {
        $this->calls[] = ['prompt' => $prompt, 'options' => $options];

        return new LLMResponse(
            content: $this->content,
            model: $this->model,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            finishReason: $this->finishReason,
        );
    }

    /**
     * Return the number of times complete() was called.
     * Use this in tests to assert the provider was invoked the expected number of times.
     */
    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * Return the number of real HTTP requests made.
     * This is ALWAYS 0 — the fake never makes HTTP requests.
     * A non-zero return here would indicate a test bug (bypassed fake).
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
}
