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

test('Part A item 4: Interview.hosted_url is exported as string|null, never the literal null, via InterviewHostedUrlNullableExtension', function (): void {
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
