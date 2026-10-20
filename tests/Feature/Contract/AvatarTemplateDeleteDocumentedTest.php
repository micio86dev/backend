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

/**
 * @return array<string, mixed>
 */
function adConflict(string $path): array
{
    return adSpec()['paths'][$path]['delete']['responses']['409'];
}

test('the 409 of both delete routes is described by its two codes, not by controller rationale', function (string $path): void {
    $description = adConflict($path)['description'];

    expect($description)->toContain('template_active')->toContain('template_in_use')
        ->and($description)->not->toContain('a decision, not a cleanup')
        ->and($description)->not->toContain('409 rather than 422');
})->with(['organization' => '/avatar-templates/{id}', 'platform' => '/admin/avatar-templates/{id}']);

test('every organization 409 variant types message as the exact value the server sends', function (string $error): void {
    $variant = adOrgConflictVariant($error);

    expect($variant['properties']['message'])->toBe(['type' => 'string', 'const' => $error]);
})->with(['template_in_use', 'template_active']);

test('the platform 409 types message as one of its two codes, like error', function (): void {
    $properties = adConflict('/admin/avatar-templates/{id}')['content']['application/json']['schema']['properties'];

    expect($properties['message']['enum'])->toEqualCanonicalizing(['template_active', 'template_in_use'])
        ->and($properties['message']['enum'])->toEqualCanonicalizing($properties['error']['enum']);
});
