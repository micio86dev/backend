<?php

declare(strict_types=1);

/**
 * RecoverFailedParticipant + POST /api/participants/{id}/recover
 * (participant-error-recovery, design D1/D2/D4/D5/D6).
 *
 * THE CRUX (design D2): a status-only recovery is NOT enough and is actively
 * WORSE than today's lockout — `resolveNextCompetency()` treats `error` as
 * terminal-completed and skips that competency forever, and `/end`'s
 * completion CAS can then never reach `totalCompetencies`, stranding the
 * candidate in `in_corso` forever with scoring never dispatched. This file's
 * FIRST test is the non-negotiable full-cycle acceptance test for the fix:
 * fail at competency 2 of 3 -> recover -> re-enter -> finish 2 and 3 ->
 * participant reaches in_valutazione and FinalizeInterview is dispatched.
 *
 * REQ: Atomic Participant and Session Recovery from Errore,
 *      Recovery Refusal Guards,
 *      Recovery Authorization,
 *      Interim Recovery Audit Logging
 *      (openspec/changes/participant-error-recovery/specs/participant-sso/spec.md)
 */

use App\Enums\WebhookEventType;
use App\Exceptions\ParticipantTransitionException;
use App\Jobs\FinalizeInterview;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function recoveryOrg(): Organization
{
    return Organization::factory()->create();
}

function recoveryOperatorToken(Organization $org, string $role = 'operator'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

/**
 * @return array{Project, list<Competency>}
 */
function recoveryProjectWithCompetencies(Organization $org, int $count = 3): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
    $role = Role::factory()->create(['code' => $project->role_code]);

    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);

        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $comp->id,
            'text' => ['en' => "recovery fixture indicator {$i}"],
            'anchor_5' => ['en' => "Excellent {$i}"],
            'anchor_3' => ['en' => "Adequate {$i}"],
            'anchor_1' => ['en' => "Insufficient {$i}"],
            'position' => 0,
        ]);
        $ind->save();

        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function recoveryParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'recovery-'.uniqid(),
        'display_name' => 'Recovery Test Candidate',
        'email' => uniqid('cand-').'@example.test',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function recoveryBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── THE CRUX ────────────────────────────────────────────────────────────────

test('full cycle: fail at competency 2 of 3, recover, resume, finish 2 and 3 -> in_valutazione + FinalizeInterview dispatched (participant-error-recovery D2 crux)', function (): void {
    Http::fake([
        // /contexts: comp1 success, comp2 fails (Upstream 5xx), comp2 RETRY
        // after recovery succeeds, comp3 succeeds.
        '*liveavatar*/contexts*' => Http::sequence()
            ->push(['data' => ['id' => 'ctx-1']], 200)
            ->push(['error' => 'Internal Server Error'], 503)
            ->push(['data' => ['id' => 'ctx-2-retry']], 200)
            ->push(['data' => ['id' => 'ctx-3']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'session_token' => 'heygen-token-'.uniqid(),
            ],
        ], 200),
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200),
    ]);
    Queue::fake();

    $org = recoveryOrg();
    [$project, $comps] = recoveryProjectWithCompetencies($org, 3);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    $candidateToken = recoveryBearer($participant);
    $operatorToken = recoveryOperatorToken($org);

    $resolver = app(TenantResolver::class);

    // ── Competency 1: normal success ────────────────────────────────────────
    $start1 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start1->assertStatus(201);
    $session1Id = $start1->json('session_id');

    $end1 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session1Id,
            'ended_reason' => 'completed',
        ]);
    $end1->assertStatus(200);
    Queue::assertNotPushed(FinalizeInterview::class);

    // ── Competency 2: provider 5xx -> participant errore, session error ────
    $start2Fail = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start2Fail->assertStatus(502);

    $participant->refresh();
    expect($participant->status)->toBe('errore');

    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $failedSession = InterviewSession::where('participant_id', $participant->id)
        ->where('competency_code', $comps[1]->code)
        ->firstOrFail();
    expect($failedSession->status)->toBe('error');

    // Simulate a partial answer captured before the failure — proves the
    // recovery discards it (resume, not restart with contaminated transcript).
    Utterance::create([
        'interview_session_id' => $failedSession->id,
        'speaker' => 'candidate',
        'text' => 'partial answer before the provider failed',
        'ts' => now(),
    ]);

    // The candidate is now locked out — cannot self-serve past this.
    $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(403);

    // ── Operator recovers the participant ───────────────────────────────────
    $recover = $this->withToken($operatorToken)
        ->postJson("/api/participants/{$participant->id}/recover", [
            'reason' => 'candidate reported a connection drop',
        ]);

    $recover->assertStatus(200);
    $recover->assertJson([
        'status' => 'in_attesa',
        'competencies_reset' => [$comps[1]->code],
        'utterances_discarded' => 1,
    ]);

    $participant->refresh();
    expect($participant->status)->toBe('in_attesa');
    expect($participant->started_at)->not->toBeNull(); // preserved, not clobbered

    $resolver->setOrgId($org->id);
    $failedSession->refresh();
    expect($failedSession->status)->toBe('pending');
    expect($failedSession->provider_session_ref)->toBeNull();
    expect($failedSession->ended_reason)->toBeNull();
    expect(Utterance::where('interview_session_id', $failedSession->id)->count())->toBe(0);

    // Competency 1's session must be UNTOUCHED (resume, not restart).
    $session1 = InterviewSession::find($session1Id);
    expect($session1->status)->toBe('completed');

    // ── Candidate resumes: competency 2 retried, succeeds ───────────────────
    // resetAuthGuardState() (tests/Pest.php): the api-candidate RequestGuard
    // caches its resolved Participant on $this->user after the FIRST call —
    // the earlier failed /start (start2Fail) resolved and cached the
    // participant while it was still 'errore'. Without this reset, THIS
    // call would silently reuse that stale cached model instead of
    // re-resolving the now-recovered 'in_attesa' row.
    resetAuthGuardState();
    $start2Retry = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start2Retry->assertStatus(201);
    expect($start2Retry->json('session_id'))->toBe($failedSession->id);

    $participant->refresh();
    expect($participant->status)->toBe('in_corso'); // resumed, not stranded at in_attesa

    $end2 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $failedSession->id,
            'ended_reason' => 'completed',
        ]);
    $end2->assertStatus(200);
    Queue::assertNotPushed(FinalizeInterview::class); // only 2/3 done

    // ── Competency 3: normal success ────────────────────────────────────────
    $start3 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start3->assertStatus(201);
    $session3Id = $start3->json('session_id');

    $end3 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session3Id,
            'ended_reason' => 'completed',
        ]);
    $end3->assertStatus(200);

    // ── THE PROOF: the interview reaches in_valutazione, scoring dispatched ──
    $participant->refresh();
    expect($participant->status)->toBe('in_valutazione');
    Queue::assertPushed(FinalizeInterview::class, 1);
});

// ─── Refusal guards ──────────────────────────────────────────────────────────

test('recovery is refused with 409 nothing_to_recover when there is no error session', function (): void {
    $org = recoveryOrg();
    [$project] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);
    $operatorToken = recoveryOperatorToken($org);

    $response = $this->withToken($operatorToken)
        ->postJson("/api/participants/{$participant->id}/recover");

    $response->assertStatus(409)->assertJson(['reason' => 'nothing_to_recover']);
});

test('recovery is refused with 409 evaluation_already_delivered when the evaluation webhook was already sent', function (): void {
    $org = recoveryOrg();
    [$project, $comps] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);
    $operatorToken = recoveryOperatorToken($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'error',
    ]);

    WebhookDelivery::factory()->forParticipant($participant)->create([
        'event_type' => WebhookEventType::Evaluation,
    ]);

    $response = $this->withToken($operatorToken)
        ->postJson("/api/participants/{$participant->id}/recover");

    $response->assertStatus(409)->assertJson(['reason' => 'evaluation_already_delivered']);

    // Guard runs BEFORE any write — participant untouched.
    $participant->refresh();
    expect($participant->status)->toBe('errore');
});

test('recovery is refused with 409 not_failed for a live (in_corso) participant', function (): void {
    $org = recoveryOrg();
    [$project] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'in_corso']);
    $operatorToken = recoveryOperatorToken($org);

    $response = $this->withToken($operatorToken)
        ->postJson("/api/participants/{$participant->id}/recover");

    $response->assertStatus(409)->assertJson(['reason' => 'not_failed']);
});

// ─── Idempotency (D6) ────────────────────────────────────────────────────────

test('recovery is idempotent: a second call on an already-recovered (in_attesa) participant returns 200, not 409, and does not re-run the reset', function (): void {
    $org = recoveryOrg();
    [$project, $comps] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);
    $operatorToken = recoveryOperatorToken($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'error',
    ]);

    $first = $this->withToken($operatorToken)->postJson("/api/participants/{$participant->id}/recover");
    $first->assertStatus(200);
    expect($first->json('competencies_reset'))->toBe([$comps[0]->code]);

    $second = $this->withToken($operatorToken)->postJson("/api/participants/{$participant->id}/recover");
    $second->assertStatus(200);
    $second->assertJson([
        'status' => 'in_attesa',
        'competencies_reset' => [],
        'utterances_discarded' => 0,
    ]);
});

// ─── Authorization (D4): 403 before 404 ─────────────────────────────────────

test('viewer is denied with 403 before the participant is even resolved (no token leak)', function (): void {
    $org = recoveryOrg();
    [$project] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);
    $viewerToken = recoveryOperatorToken($org, 'viewer');

    // Even a non-existent id must 403 before it would otherwise 404 —
    // proves authorize() runs first.
    $this->withToken($viewerToken)
        ->postJson('/api/participants/999999/recover')
        ->assertStatus(403);

    $this->withToken($viewerToken)
        ->postJson("/api/participants/{$participant->id}/recover")
        ->assertStatus(403);
});

test('admin and operator can recover; cross-org participant id 404s', function (): void {
    $orgA = recoveryOrg();
    $orgB = recoveryOrg();
    [$projectA, $compsA] = recoveryProjectWithCompetencies($orgA, 1);
    $participantA = recoveryParticipant($orgA, $projectA, 'in_attesa');
    DB::table('participants')->where('id', $participantA->id)->update(['status' => 'errore']);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    InterviewSession::create([
        'participant_id' => $participantA->id,
        'project_id' => $projectA->id,
        'question_index' => 0,
        'competency_code' => $compsA[0]->code,
        'framework_version_id' => $projectA->framework_version_id,
        'provider' => 'heygen',
        'status' => 'error',
    ]);

    $adminTokenOrgB = recoveryOperatorToken($orgB, 'admin');

    // Cross-org: orgB admin cannot reach orgA's participant.
    $this->withToken($adminTokenOrgB)
        ->postJson("/api/participants/{$participantA->id}/recover")
        ->assertStatus(404);

    $adminTokenOrgA = recoveryOperatorToken($orgA, 'admin');
    $this->withToken($adminTokenOrgA)
        ->postJson("/api/participants/{$participantA->id}/recover")
        ->assertStatus(200);
});

// ─── Recover-vs-/start interleavings (D6) ────────────────────────────────────

test('recover racing /start: /start already committed in_corso -> recovery 409 not_failed', function (): void {
    // Models the interleaving where /start's write commits FIRST — recovery's
    // in-lock re-read then sees a live status and refuses (no new mechanism
    // needed: the ordinary "other status -> 409 not_failed" branch covers it).
    $org = recoveryOrg();
    [$project] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'in_corso']);
    $operatorToken = recoveryOperatorToken($org);

    $this->withToken($operatorToken)
        ->postJson("/api/participants/{$participant->id}/recover")
        ->assertStatus(409)
        ->assertJson(['reason' => 'not_failed']);
});

test('recover racing /start: a stale in-memory errore participant still cannot self-transition to in_corso after a concurrent recovery (fail-closed, no new mechanism)', function (): void {
    // Models the OTHER interleaving: recovery holds the lock and commits
    // errore -> in_attesa; a concurrent /start request that read the
    // participant BEFORE the recovery (in-memory original still 'errore')
    // then attempts errore -> in_corso directly. The existing booted() guard
    // (Participant::$allowedTransitions) rejects it fail-closed — the same
    // guard participant-error-recovery relies on everywhere else, no new
    // mechanism required.
    $org = recoveryOrg();
    [$project] = recoveryProjectWithCompetencies($org, 1);
    $participant = recoveryParticipant($org, $project, 'in_attesa');
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'errore']);

    // A second in-memory instance of the SAME row, hydrated while status is
    // still 'errore' — this stands in for the concurrent /start request's
    // stale read.
    $staleInstance = Participant::where('organization_id', $org->id)->findOrFail($participant->id);
    expect($staleInstance->getOriginal('status'))->toBe('errore');

    // Recovery commits errore -> in_attesa on the OTHER instance (out of
    // band, e.g. via a direct DB update standing in for the real action).
    DB::table('participants')->where('id', $participant->id)->update(['status' => 'in_attesa']);

    // The stale instance still believes its original was 'errore' and
    // attempts the illegal errore -> in_corso jump.
    expect(function () use ($staleInstance): void {
        $staleInstance->status = 'in_corso';
        $staleInstance->save();
    })->toThrow(ParticipantTransitionException::class);
});
