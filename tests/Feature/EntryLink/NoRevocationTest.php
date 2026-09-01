<?php

declare(strict_types=1);

/**
 * "No Revocation Semantics" — operator-interview-link.
 *
 * Minting a new entry link for a participant MUST NOT invalidate any
 * previously minted, unexpired entry link for that same participant: there
 * is no mechanism that consumes a jti before its own exchange or expiry.
 * This is the exact property a future change would break by accident — e.g.
 * someone adding jti consumption at MINT time (instead of only at exchange)
 * would make a second mint silently kill the first, and nothing would
 * notice without a test that actually exchanges the OLDER token after a
 * newer one has been minted.
 *
 * REQ: No Revocation Semantics — "A superseded link remains valid until its
 *      own expiry"
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function noRevocationOperator(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function noRevocationProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(array_merge([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

/**
 * Extracts the raw sso-link JWT from a composed entry_url
 * (`{origin}/interview/{token}` or `{origin}/{lang}/interview/{token}`) —
 * the token is always the final path segment.
 */
function tokenFromEntryUrl(string $entryUrl): string
{
    $segments = explode('/', rtrim($entryUrl, '/'));

    return end($segments);
}

test('a superseded link remains valid until its own expiry — the OLDER token still exchanges', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = noRevocationProject($org);
    $token = noRevocationOperator($org);

    // First mint — the "older" link. No participant row exists yet (the
    // mint writes nothing), so nothing here can trip the terminal-status
    // gate for the second mint below.
    $first = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'superseded-cand',
        'display_name' => 'Mario Rossi',
        'email' => uniqid('cand-').'@example.test',
    ]);
    $first->assertStatus(201);
    $olderJwt = tokenFromEntryUrl($first->json('entry_url'));

    // Second mint for the SAME candidate — the "newer" link. There is no
    // revocation mechanism; this must not invalidate the first.
    $second = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'superseded-cand',
        'display_name' => 'Mario Rossi',
        'email' => uniqid('cand-').'@example.test',
    ]);
    $second->assertStatus(201);
    $newerJwt = tokenFromEntryUrl($second->json('entry_url'));

    expect($olderJwt)->not->toBe($newerJwt);

    // The OLDER (superseded) token still exchanges successfully — proving
    // the newer mint did not consume or invalidate it.
    $this->getJson('/api/sso/exchange?token='.$olderJwt)->assertOk();
});

test('after the OLDER token is exchanged, the NEWER (still-unexchanged) token remains independently valid', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = noRevocationProject($org);
    $token = noRevocationOperator($org);

    $first = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'superseded-cand-2',
        'display_name' => 'Mario Rossi',
        'email' => uniqid('cand-').'@example.test',
    ]);
    $first->assertStatus(201);
    $olderJwt = tokenFromEntryUrl($first->json('entry_url'));

    $second = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'superseded-cand-2',
        'display_name' => 'Mario Rossi',
        'email' => uniqid('cand-').'@example.test',
    ]);
    $second->assertStatus(201);
    $newerJwt = tokenFromEntryUrl($second->json('entry_url'));

    // Exchanging the older link first (idempotent upsert, same candidate_ref —
    // SsoExchangeHappyPathTest's own precedent) must not touch the newer
    // link's own, independent jti.
    $this->getJson('/api/sso/exchange?token='.$olderJwt)->assertOk();

    $this->getJson('/api/sso/exchange?token='.$newerJwt)->assertOk();
});
