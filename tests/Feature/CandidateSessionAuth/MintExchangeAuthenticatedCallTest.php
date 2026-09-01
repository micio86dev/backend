<?php

declare(strict_types=1);

/**
 * The genuine chain — candidate-session-auth D7.1 (Task 4.1).
 *
 * Mint an sso-link → GET /api/sso/exchange → take the returned candidate JWT
 * → GET /api/candidate/session AND POST /api/candidate/interview/start, both
 * authenticated with that SAME token. Nothing in the AUTH CHAIN is mocked:
 * no `CandidateTokenFactory::mintCandidateToken()` shortcut, no manually
 * constructed bearer token — the token used for every candidate call below
 * is exactly the `access_token` the real `/sso/exchange` endpoint returned.
 *
 * `Http::fake()` still stands in for the external HeyGen provider — that is
 * an unrelated third-party SaaS dependency, not part of the auth chain this
 * test exists to prove, and faking it is standard practice across every
 * other C7a `/start` test (see `InterviewStartTest.php`).
 *
 * HONESTY NOTE (do not remove): this test is expected to be GREEN on first
 * run. The exchange, the mint, the guard, `/candidate/session`, and the
 * `/start` resume path all already do exactly what is needed — nothing here
 * was built to make this test pass. It is a CHARACTERISATION test that locks
 * in that the API half of candidate-session-auth was never the defect; the
 * defect was entirely in the frontend never attaching the header. Calling
 * this RED-then-GREEN TDD would be a lie.
 *
 * MUST be run as an exact file, never `--filter`:
 *   ./vendor/bin/pest tests/Feature/CandidateSessionAuth/MintExchangeAuthenticatedCallTest.php
 * (`php artisan test --filter` has been observed fabricating passes in this repo.)
 *
 * REQ: Genuine authentication is exercised by tests, not mocked
 *      (candidate-session-auth spec.md)
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fixtures — mirrors SsoExchangeHappyPathTest.php (mint/exchange) and
// InterviewStartTest.php (project/competency/role seeding for /start).
// ---------------------------------------------------------------------------

function chainOrg(): Organization
{
    return Organization::factory()->create();
}

function chainProject(Organization $org): Project
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

/** Seeds one competency + a Role/BarsIndicator so /start's composer can succeed. */
function chainCompetency(Organization $org, Project $project): Competency
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => $project->role_code]);
    $competency = Competency::factory()->create();

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 1,
    ]);

    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Genuine-chain fixture indicator'],
        'anchor_5' => ['en' => 'Excellent'],
        'anchor_3' => ['en' => 'Adequate'],
        'anchor_1' => ['en' => 'Insufficient'],
        'position' => 0,
    ]);
    $indicator->save();

    return $competency;
}

function mintChainSsoLink(Project $project, Organization $org, string $ref = 'cand-chain-001'): string
{
    return CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $ref,
        'display_name' => 'Genuine Chain Candidate',
        'email' => uniqid('cand-').'@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);
}

function chainHeygenSuccessResponse(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-chain-001']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-chain-'.uniqid(),
                'session_token' => 'heygen-token-chain-'.uniqid(),
                'url' => 'https://webrtc.heygen.com/test',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200), // teardown
    ];
}

// ---------------------------------------------------------------------------
// The genuine chain
// ---------------------------------------------------------------------------

test('mint → exchange → GET /candidate/session, authenticated with the EXCHANGED token', function (): void {
    $org = chainOrg();
    $project = chainProject($org);
    $ssoLink = mintChainSsoLink($project, $org, 'cand-chain-session');

    // ── Exchange — the real endpoint, nothing mocked ──────────────────────
    $exchangeResponse = $this->getJson('/api/sso/exchange?token='.$ssoLink);
    $exchangeResponse->assertOk()->assertJsonStructure(['access_token']);

    $candidateToken = $exchangeResponse->json('access_token');
    expect($candidateToken)->toBeString()->not->toBeEmpty();

    // ── Authenticated call, using EXACTLY the token the exchange returned ──
    $sessionResponse = $this
        ->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->getJson('/api/candidate/session');

    $sessionResponse->assertOk();
    expect($sessionResponse->json('data.candidate_ref'))->toBe('cand-chain-session');
});

test('mint → exchange → POST /candidate/interview/start, authenticated with the EXCHANGED token', function (): void {
    Http::fake(chainHeygenSuccessResponse());
    Queue::fake();

    $org = chainOrg();
    $project = chainProject($org);
    chainCompetency($org, $project);
    $ssoLink = mintChainSsoLink($project, $org, 'cand-chain-start');

    // ── Exchange — the real endpoint, nothing mocked ──────────────────────
    $exchangeResponse = $this->getJson('/api/sso/exchange?token='.$ssoLink);
    $exchangeResponse->assertOk();
    $candidateToken = $exchangeResponse->json('access_token');

    // ── The first authenticated candidate call after exchange ──────────────
    $startResponse = $this
        ->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');

    $startResponse->assertStatus(201);
    $startResponse->assertJsonStructure(['session_id', 'provider', 'question_context']);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $session = InterviewSession::first();
    expect($session)->not->toBeNull();
    expect($session->status)->toBe('in_corso');

    $participant = Participant::where('candidate_ref', 'cand-chain-start')->first();
    expect($participant)->not->toBeNull();
    expect($participant->status)->toBe('in_corso');
});

test('no Authorization header at all → GET /candidate/session and POST /start both 401 (the defect this whole change fixes)', function (): void {
    // No exchange, no token — proves the guard is genuinely enforced, not
    // bypassed by test scaffolding. This is the exact call shape the
    // frontend made before candidate-session-auth: unauthenticated.
    $sessionResponse = $this->getJson('/api/candidate/session');
    $sessionResponse->assertStatus(401);

    $startResponse = $this->postJson('/api/candidate/interview/start');
    $startResponse->assertStatus(401);
});
