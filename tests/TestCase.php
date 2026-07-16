<?php

namespace Tests;

use App\Testing\FakeLLMProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
                . 'Create a fixture JSON in tests/Fixtures/cassettes/ with the expected LLMResponse shape.'
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
