<?php

declare(strict_types=1);

/**
 * RED — 12.2: `/end` progress seam (C10, design.md D5, interview-session delta).
 *
 * `InterviewController::end()` runs an explicit `DB::transaction`
 * (`InterviewController.php:219-268`) with an established `afterCommit()` precedent
 * at `:264` (`FinalizeInterview::dispatch($pid)->afterCommit()`). The progress event
 * follows that SAME shape: emitted only AFTER `DB::transaction()` returns (i.e. only
 * after a successful COMMIT) — never inside the closure, never via a plain `throw`
 * path.
 *
 * This is the genuinely discriminating commit-vs-rollback pair the seam requires:
 *   - commits (non-last-competency success)  → EXACTLY one CompetencySessionEnded
 *     delivery row.
 *   - rolls back (`abort(409)` idempotency guard) → EXACTLY zero delivery rows.
 * A test asserting only the happy path would prove nothing about ordering — both
 * halves are asserted below.
 */

use App\Jobs\FinalizeInterview;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function c10EndWebhookProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create([
        'status' => 'active',
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_end_seam_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
}

/**
 * @return array{Project, list<Competency>}
 */
function c10EndWebhookProjectWithCompetencies(Organization $org, int $count = 2): array
{
    $project = c10EndWebhookProject($org);
    $competencies = [];
    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);
        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function c10EndWebhookParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'c10-end-'.uniqid(),
        'display_name' => 'C10 End Seam Candidate',
        'status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

function c10EndWebhookSession(
    Organization $org,
    Participant $participant,
    Project $project,
    string $code,
    string $status = 'in_corso',
): InterviewSession {
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
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => $status,
    ]);
}

function c10EndWebhookBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

test('COMMIT: non-last-competency /end produces exactly one progress delivery row (CompetencySessionEnded)', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
    ]);
    Queue::fake();

    $org = Organization::factory()->create();
    [$project, $comps] = c10EndWebhookProjectWithCompetencies($org, 2);
    $participant = c10EndWebhookParticipant($org, $project, 'in_corso');
    $session = c10EndWebhookSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = c10EndWebhookBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ])
        ->assertStatus(200);

    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);
});

test('COMMIT: last-competency /end dispatches the progress delivery alongside FinalizeInterview', function (): void {
    Http::fake([
        // @wire-source legacy-demo/src/pages/api/interview/end.ts:76-84 — real shape
        // is `data.transcript_data` (PR4); empty array here = genuinely no transcript.
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
    ]);
    Queue::fake();

    $org = Organization::factory()->create();
    [$project, $comps] = c10EndWebhookProjectWithCompetencies($org, 1);
    $participant = c10EndWebhookParticipant($org, $project, 'in_corso');
    $session = c10EndWebhookSession($org, $participant, $project, $comps[0]->code, 'in_corso');
    $token = c10EndWebhookBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ])
        ->assertStatus(200);

    Queue::assertPushed(FinalizeInterview::class, 1);
    expect(WebhookDelivery::where('participant_id', $participant->id)->count())->toBe(1);
});

test('ROLLBACK: abort(409) idempotency-guard rollback produces EXACTLY ZERO delivery rows', function (): void {
    Http::fake();
    Queue::fake();

    $org = Organization::factory()->create();
    [$project, $comps] = c10EndWebhookProjectWithCompetencies($org, 1);
    $participant = c10EndWebhookParticipant($org, $project, 'in_valutazione');

    // Session already completed — the FOR UPDATE guard aborts with 409, ROLLING BACK
    // the whole explicit transaction before any progress event could be emitted.
    $session = c10EndWebhookSession($org, $participant, $project, $comps[0]->code, 'completed');
    DB::table('interview_sessions')->where('id', $session->id)->update(['ended_at' => now()->subMinutes(5)]);

    $token = c10EndWebhookBearer($participant);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ])
        ->assertStatus(409);

    expect(WebhookDelivery::count())->toBe(0);
});

test('ROLLBACK-equivalent: abort(404) unowned session produces EXACTLY ZERO delivery rows', function (): void {
    Http::fake();
    Queue::fake();

    $org = Organization::factory()->create();
    [$project, $comps] = c10EndWebhookProjectWithCompetencies($org, 1);
    $participantX = c10EndWebhookParticipant($org, $project, 'in_corso');
    $participantY = c10EndWebhookParticipant($org, $project, 'in_corso');

    // Session belongs to X — resolveOwnedSession() 404s BEFORE the transaction even
    // starts (InterviewController.php:208, before :219), so there is nothing to roll
    // back; this proves the seam correctly never reaches the emission code either way.
    $sessionX = c10EndWebhookSession($org, $participantX, $project, $comps[0]->code, 'in_corso');
    $tokenY = c10EndWebhookBearer($participantY);

    $this->withHeaders(['Authorization' => 'Bearer '.$tokenY])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $sessionX->id,
            'ended_reason' => 'completed',
        ])
        ->assertStatus(404);

    expect(WebhookDelivery::count())->toBe(0);
});
