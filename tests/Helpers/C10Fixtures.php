<?php

declare(strict_types=1);

/**
 * Shared C10 (webhooks-integration) test fixtures.
 *
 * These live here, autoloaded via composer `autoload-dev.files`, and NOT inside a
 * test file. CI runs `php artisan test --parallel`, and ParaTest distributes test
 * FILES across worker processes: a helper defined in one test file is simply not
 * defined in a worker that did not receive that file. That is exactly how 19 C10
 * tests failed in CI while the sequential local run stayed green — the local gate
 * loads every file in a single process and masks the problem entirely.
 *
 * Rule of thumb: any helper used by more than one test file MUST live in an
 * autoloaded file, never in a test file.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

/**
 * @param  array<string, mixed>  $projectAttrs
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10RecorderFixtures(array $projectAttrs = []): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_test_secret_value',
        'webhook_events' => ['progress', 'evaluation'],
    ], $projectAttrs));

    $participant = Participant::factory()->forProject($project)->create();

    return [$org, $project, $participant];
}

/**
 * @return Closure(string): array<string, mixed>
 */
function c10StubPayload(): Closure
{
    return fn (string $deliveryId): array => ['delivery_id' => $deliveryId, 'stub' => true];
}
