<?php

declare(strict_types=1);

/**
 * SSO Exchange happy path tests (C6 — Participant + SSO Ingress).
 *
 * Covers:
 * - happy path: valid sso-link → 200 + candidate JWT, jti consumed, participant in_attesa
 * - re-exchange while in_attesa → 200 + exactly one row + updated display_name
 *
 * REQ: Public SSO Exchange — happy path + idempotent upsert
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Tymon\JWTAuth\Facades\JWTAuth;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeExchangeProject(Organization $org, array $attrs = []): Project
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

function mintValidSsoLink(Project $project, Organization $org, string $ref = 'cand-001', string $display = 'Test Candidate'): string
{
    return CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name' => $display,
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);
}

// ---------------------------------------------------------------------------
// Happy path
// ---------------------------------------------------------------------------

test('happy path: valid sso-link → 200 + candidate JWT', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org);
    $token = mintValidSsoLink($project, $org, 'cand-happy');

    $response = $this->getJson('/api/sso/exchange?token='.$token);

    $response->assertOk()
        ->assertJsonStructure(['access_token']);

    // Validate the candidate JWT
    $candidateToken = $response->json('access_token');
    $payload = JWTAuth::setToken($candidateToken)->getPayload();
    expect($payload->get('typ'))->toBe('candidate');
    expect($payload->get('candidate_ref'))->toBe('cand-happy');
});

test('happy path: participant is created with status=in_attesa', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org);
    $token = mintValidSsoLink($project, $org, 'cand-attesa');

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    $participant = Participant::where('project_id', $project->id)
        ->where('candidate_ref', 'cand-attesa')
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe('in_attesa');
    expect($participant->display_name)->toBe('Test Candidate');
    expect($participant->organization_id)->toBe($org->id);
});

test('happy path: jti consumed after exchange (replay → 401)', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org);
    $token = mintValidSsoLink($project, $org, 'cand-jti-test');

    // First exchange — succeeds
    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    // Same token again — jti already consumed → 401
    $this->getJson('/api/sso/exchange?token='.$token)->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Idempotent upsert
// ---------------------------------------------------------------------------

test('re-exchange while in_attesa → 200 + exactly one row + display_name updated', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org);

    // First exchange — creates the participant
    $token1 = mintValidSsoLink($project, $org, 'cand-idempotent', 'First Name');
    $this->getJson('/api/sso/exchange?token='.$token1)->assertOk();

    // Second exchange with updated display_name — should update, not create new row
    $token2 = mintValidSsoLink($project, $org, 'cand-idempotent', 'Second Name');
    $this->getJson('/api/sso/exchange?token='.$token2)->assertOk();

    // Exactly one row
    $count = Participant::where('project_id', $project->id)
        ->where('candidate_ref', 'cand-idempotent')
        ->count();
    expect($count)->toBe(1);

    // display_name updated
    $participant = Participant::where('project_id', $project->id)
        ->where('candidate_ref', 'cand-idempotent')
        ->first();
    expect($participant->display_name)->toBe('Second Name');
    expect($participant->status)->toBe('in_attesa');
});

test('language resolved from sso-link lang claim', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org, ['language' => 'it']);

    // lang claim is 'es'
    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-lang-es',
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'es', // explicit lang
    ]);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    $participant = Participant::where('candidate_ref', 'cand-lang-es')->first();
    expect($participant->language)->toBe('es');
});

test('language falls back to project.language when lang claim absent', function (): void {
    $org = Organization::factory()->create();
    $project = makeExchangeProject($org, ['language' => 'it']);

    $token = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'cand-lang-fallback',
        'display_name' => 'Test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => null, // no lang
    ]);

    $this->getJson('/api/sso/exchange?token='.$token)->assertOk();

    $participant = Participant::where('candidate_ref', 'cand-lang-fallback')->first();
    // Falls back to project.language = 'it'
    expect($participant->language)->toBe('it');
});
