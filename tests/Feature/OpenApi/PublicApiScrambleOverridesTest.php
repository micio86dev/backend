<?php

declare(strict_types=1);

/**
 * Step 6 review follow-up, Part A items 4 and 5 — generates the OpenAPI
 * document IN PROCESS (the same `Scramble::getGeneratorConfig()` +
 * `Generator` call `scramble:export` itself makes, never writing a file)
 * and asserts on the two fields whose exported TYPE depends on a
 * documentation-only override rather than a runtime branch. Exercised
 * against this connection's real database driver — `api/CLAUDE.md`'s own
 * "never SQLite" warning about `scramble:export` applies here too, and
 * this suite's `phpunit.xml` connection is Postgres by default.
 */

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\DatabaseTransactions;

// Generating the document introspects request classes whose rules() can open a
// draft catalogue revision; without this the draft outlives the test and breaks
// every later test that seeds the baseline (framework_catalog_revisions_one_draft).
uses(DatabaseTransactions::class);

test('Part A item 4: Interview.hosted_url is exported as string|null, never the literal null, via PublicSerializerTypesExtension', function (): void {
    $config = Scramble::getGeneratorConfig('default');
    $document = app(Generator::class)($config);

    $schema = $document['components']['schemas']['PublicInterview'] ?? null;
    expect($schema)->not->toBeNull();

    $hostedUrl = $schema['properties']['hosted_url'] ?? null;
    expect($hostedUrl)->not->toBeNull();
    expect($hostedUrl['type'])->toBe(['string', 'null']);
});

test('Part A item 5: GET /embed/exchange 200 body documents access_token as string, never null', function (): void {
    $config = Scramble::getGeneratorConfig('default');
    $document = app(Generator::class)($config);

    $operation = $document['paths']['/embed/exchange']['get'] ?? null;
    expect($operation)->not->toBeNull();

    $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull();

    $accessToken = $schema['properties']['access_token'] ?? null;
    expect($accessToken)->not->toBeNull();
    expect($accessToken['type'])->toBe('string');
});

test('Interview.metadata is exported as a string map (object with string values), never an untyped array', function (): void {
    $config = Scramble::getGeneratorConfig('v1');
    $document = app(Generator::class)($config);

    $metadata = $document['components']['schemas']['PublicInterview']['properties']['metadata'] ?? null;
    expect($metadata)->not->toBeNull();
    expect($metadata['type'])->toBe('object');
    expect($metadata['additionalProperties'])->toBe(['type' => 'string']);
});

test('Interview.progress is exported as a typed list of competencies with their answers', function (): void {
    $config = Scramble::getGeneratorConfig('v1');
    $document = app(Generator::class)($config);

    $progress = $document['components']['schemas']['PublicInterview']['properties']['progress'] ?? null;
    expect($progress)->not->toBeNull();
    expect($progress['type'])->toBe('array');
    expect($progress['items']['properties']['competency_code'])->toBe(['type' => 'string']);
    expect($progress['items']['properties']['answers']['items']['properties']['question_index'])->toBe(['type' => 'integer']);
});

test('conflict responses share one Problem shape: no union of literal variants, no constant detail', function (string $path): void {
    $document = app(Generator::class)(Scramble::getGeneratorConfig('v1'));

    $schema = $document['paths'][$path]['post']['responses']['409']['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull();
    expect($schema)->not->toHaveKey('anyOf');
    expect($schema['properties']['detail'])->not->toHaveKey('const');
    expect($schema['properties']['code'])->not->toHaveKey('const');
})->with(['/interviews', '/interviews/{interview}/session-tokens']);

test('list fields carry their item type: organization allowed_domains, project competencies', function (): void {
    $document = json_decode(json_encode(app(Generator::class)(Scramble::getGeneratorConfig('v1'))), true);
    $schemas = $document['components']['schemas'];

    expect($schemas['PublicOrganization']['properties']['allowed_domains']['items'])->toBe(['type' => 'string']);

    foreach ([$schemas['PublicProject']['properties']['competencies'], $schemas['PublicInterview']['properties']['project']['properties']['competencies']] as $competencies) {
        expect($competencies['type'])->toBe('array');
        expect($competencies['items']['properties']['code'])->toBe(['type' => 'string']);
        expect($competencies['items']['properties']['name'])->toBe(['type' => 'string']);
        expect($competencies['items']['properties']['type'])->toBe(['type' => 'string']);
    }
});

test('the exported PublicInterview documents project as optional because it only appears with expand=project', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('openapi.v1.json')), true)['components']['schemas']['PublicInterview'];

    expect($schema['properties'])->toHaveKey('project');
    expect($schema['required'])->not->toContain('project');
    expect($schema['required'])->toContain('project_id');
});
