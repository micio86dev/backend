<?php

declare(strict_types=1);

/**
 * The published contract must describe `persona.display_name` on the avatar template surfaces
 * (live-interview-call-chrome, T-A3.3 and T-A3.5). The COMMITTED `openapi.json` is read the way
 * the backoffice's codegen reads it: Scramble derives the schema from the rules and the
 * `@scramble-return`, and that derivation is silent when it goes stale.
 *
 * `display_name` must be OPTIONAL inside `persona`: `persona: {}` is the payload that clears the name.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */

/**
 * @return array<string, mixed>
 */
function personaDocumentedSpec(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('every avatar template write documents an optional nullable persona.display_name', function (string $path, string $method): void {
    $operation = personaDocumentedSpec()['paths'][$path][$method] ?? null;
    expect($operation)->not->toBeNull("openapi.json documents no {$method} {$path}");

    $schema = $operation['requestBody']['content']['application/json']['schema'];
    $persona = $schema['properties']['persona'];

    expect($persona['properties']['display_name']['type'])->toBe(['string', 'null'])
        ->and($persona['required'] ?? [])->not->toContain('display_name')
        ->and($schema['required'] ?? [])->not->toContain('persona');
})->with([
    'organization create' => ['/avatar-templates', 'post'],
    'organization update' => ['/avatar-templates/{id}', 'patch'],
    'platform create' => ['/admin/avatar-templates', 'post'],
    'platform update' => ['/admin/avatar-templates/{id}', 'patch'],
]);
