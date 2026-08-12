<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation on every id-bearing /api/users endpoint
 * (backoffice-missing-pages D4).
 *
 * A foreign-org id MUST 404 (existence never leaked), not 403 — mirrors
 * AdminCrossTenantIsolationTest (C11) and the codebase's existing
 * 404-vs-403 discipline (bootstrap/app.php:84-89).
 *
 * NOTE — design/spec deviation, flagged rather than silently resolved: the
 * spec's scenario names FOUR id-bearing endpoints, including `GET
 * /api/users/{id}`. Design D4's route table + task 11.6 ("5 endpoints ...")
 * deliberately has only 5 routes total (index, store, update, deactivate,
 * activate) with NO single-resource GET — `GET /api/users` (the list) is
 * the only read, since the dataset is small (D1: "tens of rows"). This test
 * covers the THREE id-bearing endpoints that actually exist per design.
 *
 * REQ: Cross-Tenant Access Returns 404, Not 403
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;

test('every id-based /api/users endpoint 404s on a cross-org user, leaking nothing', function (string $method, string $suffix): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($orgA, 'admin');

    $userInOrgB = User::factory()->create([
        'organization_id' => $orgB->id,
        'email' => 'org-b-secret@example.test',
    ]);

    $url = "/api/users/{$userInOrgB->id}{$suffix}";
    $response = match ($method) {
        'patch' => $this->withToken($token)->patchJson($url, ['name' => 'Hacked']),
        'post' => $this->withToken($token)->postJson($url),
    };

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('org-b-secret@example.test');
})->with([
    'PATCH update' => ['patch', ''],
    'POST deactivate' => ['post', '/deactivate'],
    'POST activate' => ['post', '/activate'],
]);
