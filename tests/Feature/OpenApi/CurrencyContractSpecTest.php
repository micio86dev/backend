<?php

declare(strict_types=1);

/**
 * The currency contract the generated clients are typed from, read from the COMMITTED
 * `openapi.json` (a stale spec is caught by the CI fresh-export diff). Guards the
 * shapes Scramble got wrong without help: a bare-object PATCH 200 branch, a writable
 * `code` on the update body, an unconstrained `currency_code`.
 */

/** @return array<string, mixed> */
function ccSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('PATCH /admin/currencies/{code} answers exactly {data: CurrencyResource} and documents its 409s', function (): void {
    $responses = ccSpec()['paths']['/admin/currencies/{code}']['patch']['responses'];

    expect($responses['200']['content']['application/json']['schema'])->toBe([
        'type' => 'object',
        'properties' => ['data' => ['$ref' => '#/components/schemas/CurrencyResource']],
        'required' => ['data'],
    ])
        ->and(json_encode($responses['409']))->toContain('currency_in_use')->toContain('currency_usd_immutable')
        ->and($responses)->toHaveKeys(['401', '403', '422']);
});

test('the update body does not list the immutable code', function (): void {
    expect(ccSpec()['components']['schemas']['UpdateCurrencyRequest']['properties'])->not->toHaveKey('code');
});

test('the organization requests constrain currency_code to three capital letters', function (): void {
    $schemas = ccSpec()['components']['schemas'];

    foreach (['UpdateOrganizationRequest', 'UpdateManagedOrganizationRequest', 'StoreOrganizationRequest'] as $name) {
        expect($schemas[$name]['properties']['currency_code']['pattern'] ?? null)->toBe('^[A-Z]{3}$');
    }
});

test('rate_per_usd documents the decimal-string canonical form on both bodies', function (): void {
    $schemas = ccSpec()['components']['schemas'];

    foreach (['StoreCurrencyRequest', 'UpdateCurrencyRequest'] as $name) {
        expect(json_encode($schemas[$name]['properties']['rate_per_usd']))->toContain('string');
    }
});
