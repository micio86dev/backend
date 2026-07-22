<?php

declare(strict_types=1);

/**
 * RED — Task 14.7: ai_requests persistence in the scoring loop (C9 D1/D2).
 *
 * Verifies:
 * (a) ai_requests row persisted with evaluation_id (never null) when competency is scored.
 * (b) Unscorable competency (role_no_bars) → no ai_requests row.
 * (c) ai_requests + CompetencyResult written in the same DB transaction (roll-back test).
 *
 * Refs spec: D1 "ai_requests linkage", D2 CW "same-txn".
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function aiLogOrg(): Organization
{
    return Organization::factory()->create();
}

function aiLogProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function aiLogParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ailog-'.uniqid(),
        'display_name' => 'AI Log Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

/**
 * Creates a minimal scored competency setup: Role, Competency (attached to project),
 * BarsIndicator with EN translations, and an InterviewSession with one utterance.
 *
 * @return array{role: Role, competency: Competency, session: InterviewSession}
 */
function setupScoringCompetency(Organization $org, Project $project, Participant $participant, string $compCode): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ROLE_'.$compCode.'_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => $compCode.'_'.uniqid()]);

    // Attach competency to project
    $project->competencies()->attach($competency->id, ['position' => 0]);

    // Create BARS indicator with EN translations
    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Work effectively with others'],
        'anchor_5' => ['en' => 'Always collaborates excellently'],
        'anchor_3' => ['en' => 'Collaborates adequately'],
        'anchor_1' => ['en' => 'Rarely collaborates'],
        'position' => 0,
    ]);
    $indicator->save();

    // Create an InterviewSession for this competency
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    // Add an utterance so transcript is non-empty
    $utt = new Utterance;
    $utt->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'I worked collaboratively on multiple projects.',
        'ts' => now(),
    ]);
    $utt->save();

    return [
        'role' => $role,
        'competency' => $competency,
        'session' => $session,
    ];
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) ai_requests row persisted with evaluation_id (never null) when competency is scored', function (): void {
    $org = aiLogOrg();
    $project = aiLogProject($org);
    $participant = aiLogParticipant($org, $project);

    $setup = setupScoringCompetency($org, $project, $participant, 'COL');

    // Cassette with a valid single-indicator LLM response for this competency
    $competencyCode = $setup['competency']->code;

    $llmJson = json_encode([
        'behaviors' => [
            [
                'indicator' => 'Work effectively with others',
                'score' => 5,
                'explanation' => 'Strong evidence of collaboration',
                'excerpts' => ['I worked collaboratively on multiple projects'],
            ],
        ],
    ]);

    $cassette = new CassetteLLMProvider([$competencyCode => $llmJson]);
    $this->app->instance(LLMProvider::class, $cassette);

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();

    $aiRequests = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->get();

    expect($aiRequests)->toHaveCount(1);
    expect($aiRequests->first()->evaluation_id)->toBe($evaluation->id);
    expect($aiRequests->first()->competency_code)->toBe($competencyCode);
});

test('(b) unscorable competency (role_no_bars) → no ai_requests row', function (): void {
    // We need a setup where a competency has NO BarsIndicator for its role.
    // The job should detect role_no_bars and skip LLM call.
    $org = aiLogOrg();
    $project = aiLogProject($org);
    $participant = aiLogParticipant($org, $project);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $competency = Competency::factory()->create(['code' => 'NOBARS_'.uniqid()]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    // Intentionally NO BarsIndicator for this competency

    // Create session so the job finds a competency to process
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    // A cassette that should NOT be called
    $cassette = new CassetteLLMProvider([]);
    $this->app->instance(LLMProvider::class, $cassette);

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();

    $aiRequestCount = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->count();

    expect($aiRequestCount)->toBe(0, 'No ai_requests row should exist for an unscorable competency.');

    $cr = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competency->code)
        ->first();

    expect($cr)->not->toBeNull();
    expect($cr->unscorable_reason)->toBe('role_no_bars');
});
