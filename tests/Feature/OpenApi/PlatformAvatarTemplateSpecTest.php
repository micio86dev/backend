<?php

declare(strict_types=1);

/**
 * The platform avatar-template surface is documented in the committed
 * `openapi.json` (global-avatar-templates A4): all eight operations, the same
 * 403 component on every one, the 409 bodies a superadmin must handle, and the
 * `avatarTemplates.manageGlobal` ability flag the backoffice gates its nav on.
 *
 * Reads the COMMITTED spec (the doctrine of `CatalogueOpenApi403ContractTest`);
 * a stale spec is caught by the CI fresh-export diff. Scramble documents only
 * what a controller visibly does, so a refusal buried in a helper vanishes from
 * the contract — which is what these assertions guard.
 */

/** @return array<string, mixed> */
function pasSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

const PAS_OPERATIONS = [
    'get /admin/avatar-templates',
    'post /admin/avatar-templates',
    'get /admin/avatar-templates/{id}',
    'patch /admin/avatar-templates/{id}',
    'delete /admin/avatar-templates/{id}',
    'post /admin/avatar-templates/{id}/activate',
    'post /admin/avatar-templates/{id}/deactivate',
    'post /admin/avatar-templates/{id}/duplicate',
];

test('every platform avatar-template operation is in the committed spec', function (): void {
    $paths = pasSpec()['paths'];

    foreach (PAS_OPERATIONS as $operation) {
        [$method, $path] = explode(' ', $operation);

        expect($paths[$path][$method] ?? null)->not->toBeNull("{$operation} is missing from openapi.json");
    }
});

test('every platform avatar-template operation refuses with the shared AuthorizationException component', function (): void {
    $paths = pasSpec()['paths'];

    foreach (PAS_OPERATIONS as $operation) {
        [$method, $path] = explode(' ', $operation);

        expect($paths[$path][$method]['responses']['403']['$ref'] ?? null)
            ->toBe('#/components/responses/AuthorizationException', "{$operation} must reference the shared 403 component");
    }
});

test('the delete documents both 409 bodies a superadmin has to handle', function (): void {
    $conflict = pasSpec()['paths']['/admin/avatar-templates/{id}']['delete']['responses']['409']['content']['application/json']['schema'] ?? null;

    expect($conflict)->not->toBeNull('DELETE must document its 409')
        ->and(array_keys($conflict['properties']))->toEqualCanonicalizing(['error', 'message', 'organization_count', 'project_count']);
});

test('the manageGlobal ability flag is documented on the /auth/me operation', function (): void {
    $operation = pasSpec()['paths']['/auth/me']['get'];
    $flag = $operation['responses']['200']['content']['application/json']['schema']['properties']['abilities']['properties']['avatarTemplates']['properties']['manageGlobal'] ?? null;

    // A per-flag description is not expressible in an inline array shape, so the
    // operation description is where the flag's meaning lives.
    expect($flag)->toBe(['type' => 'boolean'])
        ->and($operation['description'])->toContain('abilities.avatarTemplates.manageGlobal')
        ->and($operation['description'])->toContain('superadmin');
});
