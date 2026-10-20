<?php

declare(strict_types=1);

/**
 * The published responses of the two avatar-template DELETE routes (hr-avatar-adapter A14).
 *
 * A 204 has no body, so its documentation must declare no `content`: a `null` JSON body made the generated
 * clients type the success as a JSON payload. The organization route's 409 stays discriminated by `error`, as
 * on develop: `template_in_use` REQUIRES `project_count` (and now `organization_count`), `template_active`
 * carries no counts. A flattened object made every count optional on both variants.
 */

/**
 * @return array<string, mixed>
 */
function adSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The values the `error` member of a 409 variant can take (`const` or `enum`).
 *
 * @param  array<string, mixed>  $variant
 * @return list<string>
 */
function adErrorValues(array $variant): array
{
    $error = $variant['properties']['error'];

    return array_key_exists('const', $error) ? [$error['const']] : array_values($error['enum'] ?? []);
}

/**
 * The organization route's 409 variant whose `error` is exactly `$error`.
 *
 * @return array<string, mixed>
 */
function adOrgConflictVariant(string $error): array
{
    $schema = adSpec()['paths']['/avatar-templates/{id}']['delete']['responses']['409']['content']['application/json']['schema'];

    expect($schema)->toHaveKey('anyOf');

    $matches = array_values(array_filter($schema['anyOf'], fn (array $variant): bool => adErrorValues($variant) === [$error]));

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

test('the 204 of both template delete routes declares no content', function (string $path): void {
    $response = adSpec()['paths'][$path]['delete']['responses']['204'];

    expect($response)->not->toHaveKey('content')
        ->and($response['description'])->not->toBe('');
})->with(['organization' => '/avatar-templates/{id}', 'platform' => '/admin/avatar-templates/{id}']);

test('the organization delete conflict is discriminated by error', function (): void {
    $schema = adSpec()['paths']['/avatar-templates/{id}']['delete']['responses']['409']['content']['application/json']['schema'];

    expect($schema['anyOf'])->toHaveCount(2);
});

test('the template_in_use variant requires both counts and describes the organization count', function (): void {
    $variant = adOrgConflictVariant('template_in_use');

    expect($variant['required'])->toEqualCanonicalizing(['error', 'message', 'project_count', 'organization_count'])
        ->and($variant['properties']['project_count']['type'])->toBe('integer')
        ->and($variant['properties']['organization_count']['type'])->toBe('integer')
        ->and($variant['properties']['organization_count']['description'] ?? '')->not->toBe('');
});

test('the template_active variant carries no counts', function (): void {
    $variant = adOrgConflictVariant('template_active');

    expect($variant['required'])->toEqualCanonicalizing(['error', 'message'])
        ->and($variant['properties'])->not->toHaveKey('project_count')
        ->and($variant['properties'])->not->toHaveKey('organization_count');
});
