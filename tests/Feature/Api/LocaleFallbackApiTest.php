<?php

/**
 * RED — 11.5: Locale fallback in API (C3).
 *
 * Seeds EN-only indicator; asserts ?locale=it returns EN fallback + translation_gap=true.
 * Also asserts Accept-Language header is honoured.
 *
 * Refs spec: "Locale-aware response falls back to EN when IT is absent".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('locale=it returns EN fallback text and translation_gap=true when IT is absent', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // ICO/PRS indicators are EN-only (seeder doesn't add IT)
    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators?locale=it');

    $response->assertOk();

    $firstIndicator = $response->json('data.0');

    // EN fallback text must be non-null (not empty)
    expect($firstIndicator['text'])->not->toBeNull()->not->toBeEmpty();

    // translation_gap must be true (IT not authored)
    expect($firstIndicator['translation_gap'])->toBeTrue();
});

test('Accept-Language: it header selects IT locale (returns EN fallback since no IT exists)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // Use Accept-Language header instead of ?locale= param
    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'it'])
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators');

    $response->assertOk();

    $firstIndicator = $response->json('data.0');

    // Should still return EN fallback text (IT not authored)
    expect($firstIndicator['text'])->not->toBeNull()->not->toBeEmpty();

    // translation_gap must be true
    expect($firstIndicator['translation_gap'])->toBeTrue();
});
