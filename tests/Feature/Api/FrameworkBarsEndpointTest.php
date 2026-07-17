<?php

/**
 * RED — 11.4: GET /api/framework/roles/ICO/competencies/PRS/indicators (C3).
 *
 * Asserts indicators returned with non-null anchors.
 * Refs spec: "Requesting competency BARS returns indicators with anchors".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder())->run();
});

test('GET indicators for ICO/PRS returns 3 indicators with non-null anchors', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators');

    $response->assertOk()
        ->assertJsonCount(3, 'data');

    $firstIndicator = $response->json('data.0');
    expect($firstIndicator['text'])->not->toBeNull()->not->toBeEmpty();
    expect($firstIndicator['anchor_5'])->not->toBeNull()->not->toBeEmpty();
    expect($firstIndicator['anchor_3'])->not->toBeNull()->not->toBeEmpty();
    expect($firstIndicator['anchor_1'])->not->toBeNull()->not->toBeEmpty();
    expect($firstIndicator['position'])->toBe(0);
});
