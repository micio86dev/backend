<?php

declare(strict_types=1);

/**
 * SSO Exchange 403 scenarios (C6 — Participant + SSO Ingress).
 *
 * Covers:
 * - inactive project → 403 generic
 * - before goes_live_at → 403 generic
 * - past deadline_at → 403 generic
 * - goes_live_at NULL passes
 * - deadline_at NULL passes
 * - role_code mismatch standard → 403 generic
 * - role_code non-null potential → 403 generic
 * - status=completato → 403 generic
 * - status=errore → 403 generic
 * - status=in_corso → 403 generic
 * - status=in_valutazione → 403 generic
 * - all 403 bodies are generic (no reason disclosed)
 * - jti consumed even when gate fails → replay → 401
 *
 * REQ: Public SSO Exchange — all 403 + security tradeoff
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeEx403Project(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

function mintEx403Token(Project $project, Organization $org, array $overrides = []): string
{
    return CandidateTokenFactory::mintSsoLink(array_merge([
        'candidate_ref' => 'cand-'.uniqid(),
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ], $overrides));
}

function forceParticipantStatus(Project $project, Organization $org, string $ref, string $status): void
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $ref,
        'display_name' => 'Test',
        'status' => 'in_attesa',
    ]);
    $p->save();

    DB::table('participants')
        ->where('id', $p->id)
        ->update(['status' => $status]);
}

// ---------------------------------------------------------------------------
// Entry gate 403s
// ---------------------------------------------------------------------------

test('inactive project → 403 generic body', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['status' => 'draft']);
    $token = mintEx403Token($project, $org);

    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    expect($response->json('message'))->toBe('Access denied.');
});

test('before goes_live_at → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['goes_live_at' => now()->addHour()]);
    $token = mintEx403Token($project, $org);

    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
    // All 403s are generic — checked in above test
});

test('past deadline_at → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['deadline_at' => now()->subHour()]);
    $token = mintEx403Token($project, $org);

    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
});

test('goes_live_at NULL → passes (200)', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['goes_live_at' => null]);
    $token = mintEx403Token($project, $org);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
});

test('deadline_at NULL → passes (200)', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['deadline_at' => null]);
    $token = mintEx403Token($project, $org);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();
});

// ---------------------------------------------------------------------------
// role_code gate 403s
// ---------------------------------------------------------------------------

test('role_code mismatch for standard project → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['role_code' => 'ICO']);
    $token = mintEx403Token($project, $org, ['role_code' => 'FLL']); // mismatch

    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    expect($response->json('message'))->toBe('Access denied.');
});

test('role_code non-null for potential project → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['assessment_type' => 'potential', 'role_code' => null]);
    $token = mintEx403Token($project, $org, ['role_code' => 'ICO']); // must be null

    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    expect($response->json('message'))->toBe('Access denied.');
});

// ---------------------------------------------------------------------------
// Blocked-status 403s (pre-flight read)
// ---------------------------------------------------------------------------

test('participant status=completato → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org);
    $ref = 'cand-completato';
    forceParticipantStatus($project, $org, $ref, 'completato');

    $token = mintEx403Token($project, $org, ['candidate_ref' => $ref]);
    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    expect($response->json('message'))->toBe('Access denied.');
});

test('participant status=errore → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org);
    $ref = 'cand-errore';
    forceParticipantStatus($project, $org, $ref, 'errore');

    $token = mintEx403Token($project, $org, ['candidate_ref' => $ref]);
    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
});

test('participant status=in_corso → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org);
    $ref = 'cand-in-corso';
    forceParticipantStatus($project, $org, $ref, 'in_corso');

    $token = mintEx403Token($project, $org, ['candidate_ref' => $ref]);
    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
});

test('participant status=in_valutazione → 403 generic', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org);
    $ref = 'cand-in-val';
    forceParticipantStatus($project, $org, $ref, 'in_valutazione');

    $token = mintEx403Token($project, $org, ['candidate_ref' => $ref]);
    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Security: jti consumed even when gate fails → replay → 401
// ---------------------------------------------------------------------------

test('jti consumed even when gate fails (inactive project) → replay → 401', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['status' => 'draft']);
    $token = mintEx403Token($project, $org);

    // First use: gate fails → 403
    $this->getJson('/api/sso/exchange?token='.$token)->assertStatus(403);

    // Replay of same token → jti consumed → 401 (NOT another 403)
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

test('all 403 bodies are generic — no info disclosure', function (): void {
    $org = Organization::factory()->create();
    $project = makeEx403Project($org, ['status' => 'draft']);
    $token = mintEx403Token($project, $org);

    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertStatus(403);
    // Body must be generic — must NOT reveal why access is denied
    $body = $response->json();
    expect($body['message'])->toBe('Access denied.');
    expect(array_keys($body))->not->toContain('reason');
    expect(array_keys($body))->not->toContain('status');
    expect(array_keys($body))->not->toContain('details');
});
