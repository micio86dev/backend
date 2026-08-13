<?php

declare(strict_types=1);

use App\Models\Competency;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * `StoreProjectRequest` requires `competency_ids[]` — integer primary keys — but
 * the only surface a client can discover competencies through is the framework
 * catalog. Without `id` on that payload the backoffice can build a competency
 * picker it is structurally unable to submit, which is the exact shape of a
 * feature that passes every unit test and does not work.
 *
 * The catalog is global (`framework_competencies` carries no `organization_id`),
 * so exposing the key leaks nothing tenant-specific.
 */
beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('the competency catalog exposes ids so competency_ids can be submitted', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $competency = Competency::query()->where('code', 'PRS')->firstOrFail();

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies');

    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('code', 'PRS');

    expect($row)->not->toBeNull()
        ->and($row)->toHaveKey('id')
        ->and($row['id'])->toBe($competency->id);
});
