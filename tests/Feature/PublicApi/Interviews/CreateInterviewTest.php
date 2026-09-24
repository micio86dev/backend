<?php

declare(strict_types=1);

/**
 * `POST /v1/interviews` — BEAI Public API (public-api step 5), SPEC.md
 * §3.3 "Create interview — request". T-INT-001..010.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

/**
 * @return array{org: Organization, key: string}
 */
function intOrgWithScopedKey(array $abilities = ['interviews:write', 'interviews:read']): array
{
    // HostedInterviewUrlComposer falls back to this when
    // public_api.interview_url is unset (neither is configured in the test
    // env by default — mirrors EntryLinkMinTest's own pattern).
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create(['allowed_domains' => ['hr.acme.example']]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => $abilities,
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function intCreateProject(Organization $org, array $attributes = []): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $attributes): Project {
        $avatarTemplate = AvatarTemplate::query()->where('organization_id', $org->id)->first()
            ?? AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create(array_merge([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ], $attributes));
    });
}

function intValidPayload(Project $project, array $overrides = []): array
{
    return array_replace_recursive([
        'project_id' => PublicId::encode($project),
        'candidate' => [
            'candidate_ref' => 'acme-672-mrossi',
            'email' => 'mario.rossi@example.com',
            'display_name' => 'Mario Rossi',
        ],
    ], $overrides);
}

// ─── T-INT-001: create happy path ────────────────────────────────────────────

test('T-INT-001: POST /v1/interviews creates a pending enrolment and returns the contract shape', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'metadata' => ['ats_application_id' => 'A-4471'],
        ]));

    $response->assertCreated();
    $response->assertJsonPath('interview.status', 'pending');
    $response->assertJsonPath('interview.candidate_ref', 'acme-672-mrossi');
    $response->assertJsonPath('interview.email', 'mario.rossi@example.com');
    $response->assertJsonPath('interview.metadata.ats_application_id', 'A-4471');
    $response->assertJsonPath('interview.livemode', true);
    $response->assertJsonStructure(['interview', 'session_token', 'expires_at', 'hosted_url']);
    expect($response->json('session_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('hosted_url'))->toContain('/i/');

    $this->assertMatchesContract($response, 'POST', '/interviews');

    $participant = Participant::where('organization_id', $org->id)
        ->where('candidate_ref', 'acme-672-mrossi')
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe('in_attesa');
    expect($participant->metadata)->toBe(['ats_application_id' => 'A-4471']);
    expect($participant->session_token_jti)->not->toBeNull();

    expect($participant->interviewEvents()->pluck('type')->all())->toBe(['created', 'invited']);
});

// ─── T-INT-002..006: 422 validation branches ─────────────────────────────────

test('T-INT-002: missing candidate.email → 422 validation_failed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $payload = intValidPayload($project);
    unset($payload['candidate']['email']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', $payload);

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->assertProblemMatchesContract($response, 422);
});

test('T-INT-003: malformed candidate.email → 422 validation_failed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, ['candidate' => ['email' => 'not-an-email']]));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

test('T-INT-004: missing candidate.display_name → 422 validation_failed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $payload = intValidPayload($project);
    unset($payload['candidate']['display_name']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', $payload);

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

test('T-INT-005: unrecognised candidate.language format → 422 validation_failed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, ['candidate' => ['language' => 'italian']]));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

test('T-INT-006: metadata over the 20-key limit → 422 metadata_limit_exceeded', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $metadata = [];
    for ($i = 0; $i < 21; $i++) {
        $metadata['key'.$i] = 'value';
    }

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, ['metadata' => $metadata]));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.0.code', 'metadata_limit_exceeded');
});

// ─── T-INT-007: exit_redirect_url host not allowed ───────────────────────────

test('T-INT-007: exit_redirect_url host not in allowed_domains → 422 redirect_url_not_allowed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'exit_redirect_url' => 'https://not-allowed.example/done',
        ]));

    $response->assertStatus(422)->assertJsonPath('code', 'redirect_url_not_allowed');
    $this->assertProblemMatchesContract($response, 422);
});

test('an exit_redirect_url host IN allowed_domains is accepted', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'exit_redirect_url' => 'https://hr.acme.example/assessment/done',
        ]));

    $response->assertCreated();
    $response->assertJsonPath('interview.exit_redirect_url', 'https://hr.acme.example/assessment/done');
});

// ─── gga round 3 finding 2: length limits ────────────────────────────────────

test('gga finding 2: an exit_redirect_url over 2048 chars → 422 validation_failed, field named in errors[]', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    // 'https://hr.acme.example/' (24 chars, an ALLOWED host) + enough
    // padding to cross 2048 — the length rule must fire BEFORE the
    // allowed-domain check ever runs, so this URL stays on the allowed host
    // deliberately (isolates the assertion to length, not domain).
    $overLong = 'https://hr.acme.example/'.str_repeat('a', 2048);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'exit_redirect_url' => $overLong,
        ]));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field'))->toContain('exit_redirect_url');
});

test('gga finding 2: a candidate.email over 255 chars → 422 validation_failed', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $overLongEmail = str_repeat('a', 250).'@example.com'; // > 255 chars total

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['email' => $overLongEmail],
        ]));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field'))->toContain('candidate.email');
});

// ─── T-INT-008: project not active ───────────────────────────────────────────

test('T-INT-008: a draft project → 422 project_not_active', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org, ['status' => 'draft']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project));

    $response->assertStatus(422)->assertJsonPath('code', 'project_not_active');
    $this->assertProblemMatchesContract($response, 422);
});

test('a bad project_id prefix → 404 not_found', function (): void {
    ['key' => $rawKey] = intOrgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', [
            'project_id' => 'org_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'candidate' => ['candidate_ref' => 'x', 'email' => 'a@example.com', 'display_name' => 'A'],
        ]);

    $response->assertNotFound();
});

// ─── T-INT-009 / T-INT-010: duplicate enrolment ──────────────────────────────

test('T-INT-009: a second enrolment with the same email → 409 duplicate_enrolment', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project))
        ->assertCreated();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['candidate_ref' => 'a-different-ref'],
        ]));

    $response->assertStatus(409)->assertJsonPath('code', 'duplicate_enrolment');
    $this->assertProblemMatchesContract($response, 409);
});

test('T-INT-010: a second enrolment with the same candidate_ref → 409 duplicate_enrolment', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project))
        ->assertCreated();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['email' => 'a-different-email@example.com'],
        ]));

    $response->assertStatus(409)->assertJsonPath('code', 'duplicate_enrolment');
});

test('gga finding 7: a second enrolment with the same email in a DIFFERENT case → 409 duplicate_enrolment', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['email' => 'Ana@x.com'],
        ]))
        ->assertCreated();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['candidate_ref' => 'a-different-ref', 'email' => 'ana@x.com'],
        ]));

    $response->assertStatus(409)->assertJsonPath('code', 'duplicate_enrolment');
});

test('gga finding 7: the stored email is lower-cased regardless of the case the caller submitted', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project, [
            'candidate' => ['email' => 'MiXeD-Case@Example.COM'],
        ]));

    $response->assertCreated();
    $response->assertJsonPath('interview.email', 'mixed-case@example.com');
});

test('the same email in a DIFFERENT project is a separate enrolment, not a duplicate', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $projectA = intCreateProject($org);
    $projectB = intCreateProject($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($projectA))
        ->assertCreated();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($projectB))
        ->assertCreated();
});

// ─── gga finding 3: session-token mint failure must not leave a committed row ─

test('when public_api.session_secret is unset, POST /v1/interviews creates NO participant row', function (): void {
    ['org' => $org, 'key' => $rawKey] = intOrgWithScopedKey();
    $project = intCreateProject($org);

    config(['public_api.session_secret' => null]);

    $before = Participant::where('organization_id', $org->id)->count();

    // The minter fails loud (RuntimeException) — the request 500s, but the
    // whole enrolment (row + events) must have rolled back with it, not
    // left a committed participant with no usable session token.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', intValidPayload($project));

    $response->assertStatus(500);

    $after = Participant::where('organization_id', $org->id)->count();
    expect($after)->toBe($before);
});
