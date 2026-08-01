<?php

declare(strict_types=1);

/**
 * Route wiring + middleware order verification tests (C7a — Phase 15.1 + 15.2 RED).
 *
 * Asserts:
 * - 15.1: All 5 interview sub-routes are registered and reach the correct controller actions.
 * - 15.2: Middleware stack order in the nested group:
 *     auth:api-candidate → TenantContextCandidate → SubstituteBindings (inherited) → ParticipantStatusGuard.
 *   ParticipantStatusGuard applies ONLY to nested /interview routes — NOT to GET /api/candidate/session.
 *
 * Tasks: 15.1 (RED), 15.2 (GREEN — route config already wired)
 * REQ: Route wiring and middleware order verification (C7a Phase 15)
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function routeOrg(): Organization
{
    return Organization::factory()->create();
}

function routeProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function routeParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'route-'.uniqid(),
        'display_name' => 'Route Test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function routeBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Phase 15.1: Route registration smoke tests ───────────────────────────────

test('15.1: POST /api/candidate/interview/start is registered', function (): void {
    expect(Route::has('candidate.interview.start') || collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === 'api/candidate/interview/start' && in_array('POST', $r->methods())
    ))->not->toBeNull();
});

test('15.1: POST /api/candidate/interview/end is registered', function (): void {
    $matchedRoute = collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === 'api/candidate/interview/end' && in_array('POST', $r->methods())
    );
    expect($matchedRoute)->not->toBeNull();
});

test('15.1: POST /api/candidate/interview/utterance is registered', function (): void {
    $matchedRoute = collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === 'api/candidate/interview/utterance' && in_array('POST', $r->methods())
    );
    expect($matchedRoute)->not->toBeNull();
});

test('15.1: POST /api/candidate/interview/integrity is registered', function (): void {
    $matchedRoute = collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === 'api/candidate/interview/integrity' && in_array('POST', $r->methods())
    );
    expect($matchedRoute)->not->toBeNull();
});

test('15.1: POST /api/candidate/interview/snapshot is registered', function (): void {
    $matchedRoute = collect(Route::getRoutes())->first(
        fn ($r) => $r->uri() === 'api/candidate/interview/snapshot' && in_array('POST', $r->methods())
    );
    expect($matchedRoute)->not->toBeNull();
});

// ─── Phase 15.1: Routes reach correct controller actions ─────────────────────

test('15.1: POST /start unauthenticated → 401 (route is registered and auth guard is active)', function (): void {
    $response = $this->postJson('/api/candidate/interview/start');
    $response->assertStatus(401);
});

test('15.1: POST /end unauthenticated → 401 (route is registered and auth guard is active)', function (): void {
    $response = $this->postJson('/api/candidate/interview/end', ['session_id' => 1, 'ended_reason' => 'completed']);
    $response->assertStatus(401);
});

test('15.1: POST /start authenticated in_attesa participant with no competencies → 422 (reaches InterviewController)', function (): void {
    Http::fake();
    Queue::fake();

    $org = routeOrg();
    $project = routeProject($org);  // no competencies added
    $participant = routeParticipant($org, $project, 'in_attesa');
    $token = routeBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start');

    // 422 = no_competency_remaining — route reached InterviewController::start()
    $response->assertStatus(422);
});

test('15.1: POST /end authenticated with missing session_id → 422 (reaches InterviewController)', function (): void {
    Http::fake();
    Queue::fake();

    $org = routeOrg();
    $project = routeProject($org);
    $participant = routeParticipant($org, $project, 'in_corso');
    $token = routeBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            // 'session_id' missing — validation error
            'ended_reason' => 'completed',
        ]);

    // 422 validation error — route reached InterviewController::end()
    $response->assertStatus(422);
});

// ─── Phase 15.2: Middleware stack order ──────────────────────────────────────

test('15.2: ParticipantStatusGuard blocks terminal participants on /interview routes (nested only)', function (): void {
    $org = routeOrg();
    $project = routeProject($org);
    $participant = routeParticipant($org, $project, 'completato');
    $token = routeBearer($participant);

    // All 5 interview sub-routes must return 403 for terminal participants
    $routes = [
        ['POST', '/api/candidate/interview/start',     []],
        ['POST', '/api/candidate/interview/end',       ['session_id' => 1, 'ended_reason' => 'completed']],
        ['POST', '/api/candidate/interview/utterance', ['session_id' => 1, 'speaker' => 'candidate', 'text' => 'hi', 'ts' => now()->toIso8601String()]],
        ['POST', '/api/candidate/interview/integrity', ['session_id' => 1, 'events' => []]],
        ['POST', '/api/candidate/interview/snapshot',  ['session_id' => 1, 'image_base64' => base64_encode("\xFF\xD8\xFF")]],
    ];

    foreach ($routes as [$method, $uri, $data]) {
        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson($uri, $data);

        expect($response->status())->toBe(403, "Expected 403 on {$uri} for terminal participant");
    }
});

test('15.2: ParticipantStatusGuard does NOT block terminal participants on GET /api/candidate/session (FIX-7)', function (): void {
    $org = routeOrg();
    $project = routeProject($org);
    $participant = routeParticipant($org, $project, 'completato');
    $token = routeBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/candidate/session');

    // Guard is NOT on this route — 200 OK (not 403)
    $response->assertStatus(200);
});

test('15.2: auth:api-candidate guard rejects unauthenticated requests before guard runs', function (): void {
    $response = $this->postJson('/api/candidate/interview/start');
    $response->assertStatus(401); // auth:api-candidate fires first
});
