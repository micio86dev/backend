<?php

declare(strict_types=1);

/**
 * The published contract must describe the candidate interview outline
 * (live-interview-call-chrome, T-A2.4).
 *
 * Scramble derives the schema from a hand-written `@scramble-return`, and that derivation is
 * silent: a stale or missing annotation still exports, so the COMMITTED `openapi.json` is read
 * the way the frontend's codegen reads it.
 *
 * REQ: interview-session "GET /candidate/interview/outline" - The response is documented
 */

/**
 * @return array<string, mixed>
 */
function outlineDocumentedSchema(): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/candidate/interview/outline']['get'] ?? null;

    expect($operation)->not->toBeNull('openapi.json documents no GET /candidate/interview/outline')
        ->and($operation['operationId'])->toBe('outline.show');

    return $operation['responses']['200']['content']['application/json']['schema'];
}

test('the outline documents its six fields, its nullable names and the status enumeration', function (): void {
    $schema = outlineDocumentedSchema();

    expect(array_keys($schema['properties']))->toBe(
        ['assessment_type', 'role_name', 'avatar_name', 'candidate_name', 'primary_total_all', 'competencies'],
    )
        ->and($schema['required'])->toBe(array_keys($schema['properties']))
        ->and($schema['properties']['assessment_type']['enum'])->toBe(['standard', 'potential'])
        ->and($schema['properties']['role_name']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['avatar_name']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['candidate_name']['type'])->toBe(['string', 'null']);
});

test('a competency entry carries a status enumeration and no code or identifier', function (): void {
    $entry = outlineDocumentedSchema()['properties']['competencies']['items'];

    expect(array_keys($entry['properties']))->toBe(['name', 'ordinal', 'status', 'primary_total'])
        ->and($entry['properties']['status']['enum'])->toBe(['done', 'current', 'upcoming', 'retry'])
        ->and($entry['properties']['name']['type'])->toBe(['string', 'null']);
});
