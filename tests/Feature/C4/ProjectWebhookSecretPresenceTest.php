<?php

declare(strict_types=1);

/**
 * `webhook_secret` is write-only by design — hidden, encrypted, never returned.
 * But "never returned" and "never knowable" are different things: the edit form
 * has to tell an operator whether a secret is already configured, or it renders
 * "not set" over a project that has one and invites them to believe none exists.
 *
 * `OrganizationResource` already solved this with `has_default_webhook_secret`
 * (a presence boolean, never the value). Projects need the same.
 *
 * REQ: Project webhook secret is write-only
 *      (openspec/changes/backoffice-missing-pages/specs/admin-backoffice/spec.md)
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;

test('the project resource reports whether a webhook secret is configured, without exposing it', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    [$withSecret, $withoutSecret] = TenantContextScope::runFor($org->id, function () use ($org): array {
        $with = Project::factory()->create([
            'organization_id' => $org->id,
            'webhook_url' => 'https://example.test/hook',
            'webhook_secret' => 'super-secret-value',
        ]);
        $without = Project::factory()->create(['organization_id' => $org->id]);

        return [$with, $without];
    });

    $configured = $this->withToken($token)->getJson("/api/projects/{$withSecret->id}");
    $configured->assertOk()
        ->assertJsonPath('data.has_webhook_secret', true)
        ->assertJsonMissingPath('data.webhook_secret');

    expect($configured->getContent())->not->toContain('super-secret-value');

    $this->withToken($token)->getJson("/api/projects/{$withoutSecret->id}")
        ->assertOk()
        ->assertJsonPath('data.has_webhook_secret', false);
});
