<?php

declare(strict_types=1);

/**
 * SSO Exchange project scope tests (C6 — Participant + SSO Ingress).
 *
 * Asserts:
 * - exchange uses Project::withoutGlobalScope('tenant') (plain findOrFail fails)
 * - soft-deleted project → 401 (SoftDeletingScope still active)
 * - withoutGlobalScopes() plural would also strip SoftDeletes (NOT used)
 *
 * REQ: Public SSO Exchange — scenario "Project resolved via withoutGlobalScope('tenant') at public exchange"
 *       + tenancy delta
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;

function makeExchangeProjectScope(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);
}

test('plain findOrFail on Project at public exchange would fail without TenantResolver set', function (): void {
    // This test demonstrates WHY withoutGlobalScope('tenant') is required.
    // At the public exchange endpoint, TenantResolver is NOT set (org = null).
    // Project::findOrFail($id) would use the TenantScoped global scope → WHERE org_id=null → 0 rows.
    // We verify this by NOT setting the resolver and confirming plain findOrFail returns null.
    $org = Organization::factory()->create();
    $project = makeExchangeProjectScope($org);

    // Reset the resolver (simulating public endpoint with no auth)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    // Plain findOrFail with null resolver → not found (TenantScoped filters by org_id=null)
    $found = null;
    try {
        $found = Project::findOrFail($project->id);
    } catch (ModelNotFoundException) {
        // expected
    }

    expect($found)->toBeNull('Plain findOrFail should fail without TenantResolver set');
});

test('withoutGlobalScope("tenant") finds the project at public exchange', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProjectScope($org);

    // Reset resolver (simulating public endpoint)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    // withoutGlobalScope('tenant') bypasses TenantScoped but keeps SoftDeletingScope
    $found = Project::withoutGlobalScope('tenant')->find($project->id);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($project->id);
});

test('soft-deleted project → 401 at exchange (SoftDeletingScope still active)', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProjectScope($org);

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-softdel-scope',
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    // Soft-delete the project
    $project->delete();

    // Exchange must return 401 — soft-deleted project is NOT findable
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('exchange returns 200 for active (non-deleted) project', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProjectScope($org);

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-active-scope',
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
});
