<?php

namespace Tests;

use App\Testing\FakeLLMProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use Tests\Contract\ContractValidator;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assert an HTTP response matches the vendored BEAI Public API contract
     * (`public-api/openapi.yaml`, SPEC.md §0 "Contract governance").
     *
     * `$path` is the CONTRACT path only — no server prefix (`/v1`) and no
     * Laravel route prefix (`/api/v1`), e.g. `/health` for a request actually
     * made against `/api/v1/health`. Delegates to `Tests\Contract\ContractValidator`,
     * converting its `ValidationFailed` into an ordinary PHPUnit assertion
     * failure so a mismatch reports like any other failed expectation.
     */
    protected function assertMatchesContract(TestResponse $response, string $method, string $path): void
    {
        try {
            ContractValidator::validate($response, $method, $path);
        } catch (ValidationFailed $exception) {
            static::fail(sprintf(
                "Response for [%s %s] does not match the BEAI Public API contract (public-api/openapi.yaml):\n%s",
                strtoupper($method),
                $path,
                $exception->getMessage()
            ));
        }

        static::assertTrue(true, 'assertMatchesContract: response matches the contract.');
    }

    /**
     * Configure the FakeLLMProvider to replay a specific VCR cassette (D36).
     *
     * Cassette convention: {purpose}--{model-slug}--{prompt-version}.json
     * Directory: tests/Fixtures/cassettes/
     *
     * Usage in a Pest test:
     *   $this->withCassette('bars-eval--haiku-4-5--prompt-v1')
     *        ->complete('Evaluate COM.');
     *
     * The cassette JSON must match the shape:
     *   { "response": { "content", "model", "input_tokens", "output_tokens", "finish_reason" } }
     *
     * @throws \RuntimeException if the cassette file is not found.
     */
    protected function withCassette(string $cassetteName): FakeLLMProvider
    {
        $path = base_path("tests/Fixtures/cassettes/{$cassetteName}.json");

        if (! file_exists($path)) {
            throw new \RuntimeException(
                "Cassette not found: {$path}. "
                .'Create a fixture JSON in tests/Fixtures/cassettes/ with the expected LLMResponse shape.'
            );
        }

        /** @var array{response: array{content: string, model: string, input_tokens: int, output_tokens: int, finish_reason: string}} $data */
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $response = $data['response'];

        return new FakeLLMProvider(
            content: $response['content'],
            model: $response['model'],
            inputTokens: $response['input_tokens'],
            outputTokens: $response['output_tokens'],
            finishReason: $response['finish_reason'],
        );
    }
}
