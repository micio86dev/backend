<?php

declare(strict_types=1);

/**
 * ai_requests persistence in the scoring loop (C9 D1, amended by C13 D1).
 *
 * Verifies:
 * (a) ai_requests row persisted with evaluation_id (never null) when scored.
 * (b) Unscorable competency (role_no_bars) → no ai_requests row. Still true:
 *     an unscorable competency makes NO provider call, so there is nothing to
 *     bill and nothing to record.
 *
 * ── What changed, and a note on what was never here ──────────────────────────
 *
 * This docblock previously advertised a third case: "(c) ai_requests +
 * CompetencyResult written in the same DB transaction (roll-back test)",
 * referencing C9's D2 CW "same-txn".
 *
 * Two things are true about that line. First, **no such test was ever
 * written** — only (a) and (b) exist below. The invariant lived in prose and in
 * a docblock, and nothing enforced it. Second, C13 D1 deliberately REVERSES the
 * invariant itself: the ai_requests row is now written OUTSIDE the results
 * transaction, because a provider call is external, irreversible and billed
 * while the results are local and revocable. Coupling them erased the record of
 * money already spent whenever the transaction rolled back.
 *
 * That combination is worth stating rather than quietly deleting the line. An
 * invariant asserted in a comment and never tested is exactly the kind that
 * gets violated for months without anyone noticing — and this one was, on the
 * failure paths, where four classes of billed call left no trace at all.
 *
 * The new behaviour is covered by tests/Feature/Jobs/AiRequestCostRecordTest.php.
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
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
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

test('(c) ai_requests row carries the derived fingerprint for a successful call (A3.4, D6)', function (): void {
    $org = aiLogOrg();
    $project = aiLogProject($org);
    $participant = aiLogParticipant($org, $project);

    $setup = setupScoringCompetency($org, $project, $participant, 'COL');
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

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    $aiRequest = AiRequest::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();

    expect($aiRequest->response_bytes)->toBe(strlen($llmJson))
        ->and($aiRequest->response_fenced)->toBeFalse()
        ->and($aiRequest->response_sha256)->toBe(hash('sha256', $llmJson))
        ->and($aiRequest->response_sha256)->toMatch('/^[0-9a-f]{64}$/');
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
