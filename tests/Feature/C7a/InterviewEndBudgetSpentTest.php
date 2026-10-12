<?php

declare(strict_types=1);

/**
 * POST /api/candidate/interview/end with `budget_spent: true`.
 *
 * When the whole interview's time is spent, the question in progress ends normally, but no
 * further competency starts. The server settles the interview on what was said, the same way
 * an abandoned interview is settled: the participant leaves `in_corso`, and the evaluation
 * reports the partial interview as pending rather than leaving the candidate stuck.
 */

use App\Jobs\FinalizeInterview;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function budgetOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * @return array{Project, list<Competency>}
 */
function budgetProject(Organization $org, int $count): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);

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

function budgetParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'budget-'.uniqid(),
        'display_name' => 'Budget Test Candidate',
        'email' => uniqid('budget-').'@example.test',
        'status' => 'in_corso',
    ]);
    $p->save();

    return $p->fresh();
}

function budgetSession(Organization $org, Participant $participant, Project $project, string $code): InterviewSession
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
        'provider' => 'tavus',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => 'in_corso',
    ]);
}

test('budget spent on /end: next_action is done even with competencies left, and the participant is settled', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
    ]);
    Queue::fake();

    $org = budgetOrg();
    [$project, $comps] = budgetProject($org, 2);
    $participant = budgetParticipant($org, $project);
    $session = budgetSession($org, $participant, $project, $comps[0]->code);
    // The candidate did speak in the question that ran out of time.
    Utterance::create([
        'interview_session_id' => $session->id,
        'provider_session_ref' => $session->provider_session_ref,
        'speaker' => 'candidate',
        'text' => 'Ho lavorato molto sulla collaborazione.',
        'ts' => now(),
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.CandidateTokenFactory::mintCandidateToken($participant)])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'timeout',
            'budget_spent' => true,
        ]);

    $response->assertStatus(200)->assertJsonPath('next_action', 'done');

    expect($participant->refresh()->status)->toBe('in_valutazione');
    Queue::assertPushed(FinalizeInterview::class);
});

test('budget not spent on /end: the directive is unchanged and the participant stays in_corso', function (): void {
    Http::fake([
        '*liveavatar*/sessions/*/transcript*' => Http::response(['data' => ['transcript_data' => []]], 200),
    ]);
    Queue::fake();

    $org = budgetOrg();
    [$project, $comps] = budgetProject($org, 2);
    $participant = budgetParticipant($org, $project);
    $session = budgetSession($org, $participant, $project, $comps[0]->code);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.CandidateTokenFactory::mintCandidateToken($participant)])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $session->id,
            'ended_reason' => 'completed',
        ]);

    $response->assertStatus(200)->assertJsonPath('next_action', 'continue');
    expect($participant->refresh()->status)->toBe('in_corso');
    Queue::assertNotPushed(FinalizeInterview::class);
});
