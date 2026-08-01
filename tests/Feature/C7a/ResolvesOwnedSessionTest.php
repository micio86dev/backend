<?php

declare(strict_types=1);

/**
 * ResolvesOwnedSession trait feature tests (C7a — Interview Session Mechanics).
 *
 * Tests the trait via a real HTTP request context using a registered test route
 * that calls resolveOwnedSession internally, so auth()->id() returns the correct
 * authenticated participant.
 *
 * Asserts:
 * - Returns session when participant_id matches auth user AND org scoping is correct.
 * - Throws 404 when participant_id mismatches (same org, different participant).
 * - Throws 404 when org_id mismatches (cross-tenant).
 * - Throws 404 for nonexistent session.
 * - Method is protected (not private — must be usable across 4 controllers via trait).
 *
 * Tasks: 4.1 (RED)
 * REQ: Session ownership resolver (ResolvesOwnedSession trait)
 */

use App\Http\Controllers\Candidate\Concerns\ResolvesOwnedSession;
use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextCandidate;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Route;

// ─── Test controller (uses the trait) ────────────────────────────────────────

class TestResolverController
{
    use ResolvesOwnedSession;

    public function expose(int $id): InterviewSession
    {
        return $this->resolveOwnedSession($id);
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeResolverOrg(): Organization
{
    return Organization::factory()->create();
}

function makeResolverProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function makeResolverParticipant(Organization $org, Project $project, string $suffix = ''): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'res-'.($suffix ?: uniqid()),
        'display_name' => 'Resolver Test',
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}

function makeResolverSession(Organization $org, Participant $participant, Project $project, string $code = 'PRS'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'in_corso',
    ]);
}

function mintCandidateBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Register test route ──────────────────────────────────────────────────────

beforeEach(function (): void {
    Route::middleware([
        'auth:api-candidate',
        TenantContextCandidate::class,
    ])
        ->withoutMiddleware(TenantContext::class)
        ->get('/test-resolve-session/{id}', function (int $id) {
            $controller = new TestResolverController;
            $session = $controller->expose($id);

            return response()->json(['session_id' => $session->id]);
        });
});

// ─── Tests ───────────────────────────────────────────────────────────────────

test('resolveOwnedSession returns session when participant_id matches auth user', function (): void {
    $org = makeResolverOrg();
    $project = makeResolverProject($org);
    $participant = makeResolverParticipant($org, $project);
    $session = makeResolverSession($org, $participant, $project);
    $token = mintCandidateBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/test-resolve-session/'.$session->id);

    $response->assertOk();
    expect($response->json('session_id'))->toBe($session->id);
});

test('resolveOwnedSession returns 404 when participant_id mismatches (same org)', function (): void {
    $org = makeResolverOrg();
    $project = makeResolverProject($org);
    $participantX = makeResolverParticipant($org, $project, 'x');
    $participantY = makeResolverParticipant($org, $project, 'y');

    // Session belongs to X
    $session = makeResolverSession($org, $participantX, $project);

    // Authenticated as Y (same org, different participant)
    $token = mintCandidateBearer($participantY);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/test-resolve-session/'.$session->id);

    $response->assertNotFound();
});

test('resolveOwnedSession returns 404 for cross-tenant session (different org)', function (): void {
    $orgA = makeResolverOrg();
    $orgB = makeResolverOrg();
    $projectA = makeResolverProject($orgA);
    $projectB = makeResolverProject($orgB);
    $participantA = makeResolverParticipant($orgA, $projectA, 'a');
    $participantB = makeResolverParticipant($orgB, $projectB, 'b');

    // Session belongs to orgB/participantB
    $sessionB = makeResolverSession($orgB, $participantB, $projectB);

    // Authenticated as participantA (orgA) — TenantScoped makes sessionB invisible
    $token = mintCandidateBearer($participantA);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/test-resolve-session/'.$sessionB->id);

    $response->assertNotFound();
});

test('resolveOwnedSession returns 404 for nonexistent session', function (): void {
    $org = makeResolverOrg();
    $project = makeResolverProject($org);
    $participant = makeResolverParticipant($org, $project);
    $token = mintCandidateBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/test-resolve-session/99999');

    $response->assertNotFound();
});

test('resolveOwnedSession is protected (not private — usable across 4 controllers via trait)', function (): void {
    $reflection = new ReflectionClass(TestResolverController::class);
    $method = $reflection->getMethod('resolveOwnedSession');

    expect($method->isPrivate())->toBeFalse('resolveOwnedSession MUST NOT be private — shared across 4 controllers');
    expect($method->isProtected() || $method->isPublic())->toBeTrue();
});
