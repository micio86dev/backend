<?php

declare(strict_types=1);

/**
 * `POST /api/m2m/participants/{id}/retry` is gated by the `participants:retry`
 * ability middleware, so a client without it gets 403. Scramble documents only
 * what a controller visibly does and cannot see route middleware, so the
 * refusal has to be declared on the action (`@throws AuthorizationException`),
 * as the operator twin and the platform avatar-template controller do.
 *
 * Reads the COMMITTED spec (the doctrine of `CatalogueOpenApi403ContractTest`);
 * a stale spec is caught by the CI fresh-export diff.
 */
test('the M2M retry operation documents the shared 403 component', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    $operation = $spec['paths']['/m2m/participants/{id}/retry']['post'] ?? null;

    expect($operation)->not->toBeNull('POST /m2m/participants/{id}/retry is missing from openapi.json')
        ->and($operation['responses']['403']['$ref'] ?? null)
        ->toBe('#/components/responses/AuthorizationException');
});

test('the operator retry operation documents the same 403 component', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($spec['paths']['/participants/{id}/retry']['post']['responses']['403']['$ref'] ?? null)
        ->toBe('#/components/responses/AuthorizationException');
});
