<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation on GET /api/organization.
 *
 * The route accepts no id at all (D2), so cross-tenant isolation here is a
 * property of the resolver, not of a filter that could be bypassed — but it
 * is still asserted end-to-end, mirroring AdminCrossTenantIsolationTest.
 *
 * REQ: Cross-Tenant Isolation
 *      (openspec/changes/backoffice-missing-pages/specs/organization-settings/spec.md)
 */

use App\Models\Organization;

test('org A never observes org B name or webhook defaults', function (): void {
    $orgA = Organization::factory()->create([
        'name' => 'Org A',
        'default_webhook_url' => 'https://org-a.example.test/hook',
    ]);
    Organization::factory()->create([
        'name' => 'Org B Secret',
        'default_webhook_url' => 'https://org-b-secret.example.test/hook',
    ]);

    $token = authTokenForRole($orgA, 'admin');

    $response = $this->withToken($token)->getJson('/api/organization');

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Org A');
    expect($response->json('data.default_webhook_url'))->toBe('https://org-a.example.test/hook');
    expect($response->getContent())->not->toContain('Org B Secret');
    expect($response->getContent())->not->toContain('org-b-secret.example.test');
});
