<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use Tests\Contract\ContractValidator;

/**
 * Unit-level coverage of the `ContractValidator` helper itself, against
 * FABRICATED responses (`TestResponse::fromBaseResponse()`) — no HTTP call,
 * no route. `tests/Feature/PublicApi/HealthEndpointTest.php` covers the real
 * `GET /api/v1/health` call (T-CONTRACT-004).
 */

/**
 * Writes an inline mini OpenAPI spec to a throwaway file, so a test can
 * point `config('public_api.contract_path')` at it and exercise
 * `ContractValidator::validate()` against a schema shaped for exactly that
 * test, instead of the full vendored contract. Deleted on shutdown.
 */
function writeInlineContract(string $yaml): string
{
    $path = sys_get_temp_dir().'/contract-validator-test-'.bin2hex(random_bytes(8)).'.yaml';

    file_put_contents($path, $yaml);

    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}
test('T-CONTRACT-002: ContractValidator rejects a fake GET /health response whose body is {"status":"degraded"}', function (): void {
    $response = TestResponse::fromBaseResponse(
        (new JsonResponse(['status' => 'degraded'], 200))
            ->header('Content-Type', 'application/json; charset=utf-8')
    );

    expect(fn () => ContractValidator::validate($response, 'GET', '/health'))
        ->toThrow(ValidationFailed::class);
});

test('T-CONTRACT-003: ContractValidator rejects a fake GET /health response with an undeclared status code (418)', function (): void {
    $response = TestResponse::fromBaseResponse(
        (new JsonResponse(['status' => 'ok'], 418))
            ->header('Content-Type', 'application/json; charset=utf-8')
    );

    expect(fn () => ContractValidator::validate($response, 'GET', '/health'))
        ->toThrow(ValidationFailed::class);
});

/**
 * Not a numbered T-* case from SPEC.md — those (`T-CONV-004` "problem+json
 * shape on every error path") land with the step-3 conventions layer. This
 * satisfies the deliverable's own requirement that the validator itself is
 * proven against `application/problem+json` bodies before step 3 builds on
 * it: exercised against the `/organization` operation's declared 401
 * (`components/responses/Unauthorized` → `Problem`), which step 1 does not
 * implement — the validator only reads the vendored contract, not the app's
 * routes.
 */
test('ContractValidator validates application/problem+json bodies against the Problem schema', function (): void {
    $validProblem = [
        'type' => 'https://developers.beai.example/errors/invalid_api_key',
        'title' => 'Invalid API key',
        'status' => 401,
        'code' => 'invalid_api_key',
        'request_id' => 'req_01J000000000000000000000',
    ];

    $matching = TestResponse::fromBaseResponse(
        (new JsonResponse($validProblem, 401))->header('Content-Type', 'application/problem+json')
    );

    expect(fn () => ContractValidator::validate($matching, 'GET', '/organization'))
        ->not->toThrow(ValidationFailed::class);

    $missingRequiredCode = $validProblem;
    unset($missingRequiredCode['code']);

    $malformed = TestResponse::fromBaseResponse(
        (new JsonResponse($missingRequiredCode, 401))->header('Content-Type', 'application/problem+json')
    );

    expect(fn () => ContractValidator::validate($malformed, 'GET', '/organization'))
        ->toThrow(ValidationFailed::class);
});

/**
 * The validator is cached in a static property, built once from whichever
 * `contract_path` was configured the FIRST time it ran in this process
 * (`ContractValidatorTest.php`'s own earlier tests already exercise the real
 * vendored contract). Overriding `config('public_api.contract_path')` to a
 * second file must not be silently ignored by a stale single-entry cache —
 * the cache must be keyed by the resolved path.
 */
test('ContractValidator caches one validator per contract_path, not just the first one seen', function (): void {
    $firstPath = writeInlineContract(<<<'YAML'
    openapi: 3.1.0
    info: { title: Mini, version: '1.0' }
    paths:
      /probe:
        get:
          responses:
            '200':
              description: ok
              content:
                application/json:
                  schema:
                    type: object
                    properties:
                      status: { type: string, const: ok }
    YAML);

    $secondPath = writeInlineContract(<<<'YAML'
    openapi: 3.1.0
    info: { title: MiniTwo, version: '1.0' }
    paths:
      /probe:
        get:
          responses:
            '200':
              description: ok
              content:
                application/json:
                  schema:
                    type: object
                    properties:
                      status: { type: string, const: different }
    YAML);

    $response = TestResponse::fromBaseResponse(
        (new JsonResponse(['status' => 'ok'], 200))->header('Content-Type', 'application/json')
    );

    config(['public_api.contract_path' => $firstPath]);

    expect(fn () => ContractValidator::validate($response, 'GET', '/probe'))
        ->not->toThrow(ValidationFailed::class);

    config(['public_api.contract_path' => $secondPath]);

    // Same body, but the SECOND contract's const is "different": a cache
    // keyed only on the first path seen would keep validating against the
    // first contract and wrongly let this pass.
    expect(fn () => ContractValidator::validate($response, 'GET', '/probe'))
        ->toThrow(ValidationFailed::class);
});

/**
 * `Recording.kind` in the real contract is `{ type: string, const: audio }`
 * and NOT in `Recording`'s `required` list — the const check must not treat
 * an ABSENT optional const property as a violation. This mini spec mirrors
 * that exact shape: `/widget`'s `kind` is declared but optional, while
 * `/widget-required`'s `kind` is declared AND required, to exercise all
 * three cases the finding names against one contract.
 */
test('ContractValidator: an absent OPTIONAL const property passes, wrong value fails, absent REQUIRED const property fails', function (): void {
    $contractPath = writeInlineContract(<<<'YAML'
    openapi: 3.1.0
    info: { title: Mini, version: '1.0' }
    paths:
      /widget:
        get:
          responses:
            '200':
              description: ok
              content:
                application/json:
                  schema:
                    type: object
                    required: [id]
                    properties:
                      id: { type: string }
                      kind: { type: string, const: audio }
      /widget-required:
        get:
          responses:
            '200':
              description: ok
              content:
                application/json:
                  schema:
                    type: object
                    required: [id, kind]
                    properties:
                      id: { type: string }
                      kind: { type: string, const: audio }
    YAML);

    config(['public_api.contract_path' => $contractPath]);

    $absentOptional = TestResponse::fromBaseResponse(
        (new JsonResponse(['id' => 'w1'], 200))->header('Content-Type', 'application/json')
    );

    expect(fn () => ContractValidator::validate($absentOptional, 'GET', '/widget'))
        ->not->toThrow(ValidationFailed::class);

    $presentWrongValue = TestResponse::fromBaseResponse(
        (new JsonResponse(['id' => 'w1', 'kind' => 'video'], 200))->header('Content-Type', 'application/json')
    );

    expect(fn () => ContractValidator::validate($presentWrongValue, 'GET', '/widget'))
        ->toThrow(ValidationFailed::class);

    $absentRequired = TestResponse::fromBaseResponse(
        (new JsonResponse(['id' => 'w1'], 200))->header('Content-Type', 'application/json')
    );

    expect(fn () => ContractValidator::validate($absentRequired, 'GET', '/widget-required'))
        ->toThrow(ValidationFailed::class);
});

/**
 * The two webhook envelope `event` consts (`ProgressWebhookPayload`,
 * `EvaluationWebhookPayload` in the real contract) live inside a top-level
 * `allOf`: a `$ref` to the shared `WebhookEnvelope` (whose own `event` is
 * a plain `{ $ref: WebhookEventType }`, no `const`), narrowed by a second,
 * inline `allOf` element that adds `event: { const: ... }`. A response
 * schema built that way has `$schema->properties === null` — the const
 * check must still find and enforce `event`'s const, merged in from the
 * `allOf` branch.
 */
test('ContractValidator enforces a const declared inside a top-level allOf branch (webhook event shape)', function (): void {
    $contractPath = writeInlineContract(<<<'YAML'
    openapi: 3.1.0
    info: { title: MiniAllOf, version: '1.0' }
    components:
      schemas:
        Envelope:
          type: object
          required: [event]
          properties:
            event: { type: string }
    paths:
      /webhook:
        post:
          responses:
            '200':
              description: ok
              content:
                application/json:
                  schema:
                    allOf:
                      - $ref: '#/components/schemas/Envelope'
                      - type: object
                        properties:
                          event: { const: progress }
    YAML);

    config(['public_api.contract_path' => $contractPath]);

    $matching = TestResponse::fromBaseResponse(
        (new JsonResponse(['event' => 'progress'], 200))->header('Content-Type', 'application/json')
    );

    expect(fn () => ContractValidator::validate($matching, 'POST', '/webhook'))
        ->not->toThrow(ValidationFailed::class);

    $mismatched = TestResponse::fromBaseResponse(
        (new JsonResponse(['event' => 'evaluation'], 200))->header('Content-Type', 'application/json')
    );

    expect(fn () => ContractValidator::validate($mismatched, 'POST', '/webhook'))
        ->toThrow(ValidationFailed::class);
});
