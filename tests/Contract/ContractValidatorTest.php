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
