<?php

declare(strict_types=1);

/**
 * ScoreEvaluationJob tenant-context establishment tests (queued-job-tenancy PR2).
 *
 * Proves the retrofit: ScoreEvaluationJob re-derives its tenant context from the
 * PARTICIPANT'S OWN organization_id (never from ambient TenantResolver state,
 * never from bypass) before performing any tenant-scoped write, and never leaks
 * that context into a subsequently-dispatched job.
 *
 * Dispatcher-based per D5 test discipline: every test here uses ::dispatch(),
 * never ->handle() directly, so Queue::before actually fires.
 *
 * REQ: Queued-Job Tenant Context Establishment (openspec/specs/tenancy/spec.md)
 */

use App\Contracts\LLMProvider;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function scoreTenancyOrg(): Organization
{
    return Organization::factory()->create();
}

function scoreTenancyProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function scoreTenancyParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'tenancy-'.uniqid(),
        'display_name' => 'Tenancy Retrofit Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

/**
 * Minimal scored-competency setup producing all 4 write types when the pipeline
 * runs to completion: Evaluation, CompetencyResult, IndicatorScore, AiRequest.
 * Mirrors tests/Feature/Jobs/AiRequestLoggingTest.php::setupScoringCompetency().
 */
function scoreTenancySetupCompetency(Organization $org, Project $project, Participant $participant): string
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ROLE_TENANCY_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'TEN_'.uniqid()]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

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

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    $utt = new Utterance;
    $utt->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'I worked collaboratively on multiple projects.',
        'ts' => now(),
    ]);
    $utt->save();

    return $competency->code;
}

function scoreTenancyBindCassette(string $competencyCode): void
{
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
    app()->instance(LLMProvider::class, $cassette);
}

/**
 * organization_id is NOT NULL and FK-constrained (foreignId()->constrained(), no
 * ->nullable() — verified at database/migrations/2026_07_20_000001_create_participants_table.php:32-34).
 * There is no legitimate way to persist an "unresolvable org" participant through
 * Eloquent or a normal insert. Temporarily disable the FK trigger to simulate the
 * should-never-happen corrupted-data state that the fail-closed guard (task 6.1)
 * must still handle without throwing.
 */
function scoreTenancyUnresolvableOrgParticipant(Project $project): int
{
    $candidateRef = 'unresolvable-'.uniqid();

    DB::statement('ALTER TABLE participants DISABLE TRIGGER ALL');

    try {
        DB::table('participants')->insert([
            'organization_id' => 0,
            'project_id' => $project->id,
            'candidate_ref' => $candidateRef,
            'display_name' => 'Unresolvable Org Test',
            'status' => 'in_valutazione',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        DB::statement('ALTER TABLE participants ENABLE TRIGGER ALL');
    }

    return (int) DB::table('participants')->where('candidate_ref', $candidateRef)->value('id');
}

/**
 * Minimal capturing job for the no-leak scenario (5). Deliberately NOT reusing
 * TenancyStateCapturingJob from tests/Feature/C2/Isolation/QueueTenancyResetTest.php
 * by class reference — that class is declared globally in another test file and
 * would only be defined when both files happen to load in the same process. This
 * file must be runnable in isolation, so it declares its own capturing job with
 * the same shape (mirrors QueueTenancyResetTest.php:31-56).
 */
class ScoreEvaluationJobTenancyCapturingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array{orgId: int|null, bypass: bool} */
    public static array $capturedState = [];

    public function handle(): void
    {
        $resolver = app(TenantResolver::class);

        static::$capturedState = [
            'orgId' => $resolver->getOrgId(),
            'bypass' => $resolver->isBypass(),
        ];
    }
}

beforeEach(function (): void {
    ScoreEvaluationJobTenancyCapturingJob::$capturedState = [];
});

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(1) ambient null (post Queue::before) — all 4 written rows carry the participant org', function (): void {
    $org = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participant = scoreTenancyParticipant($org, $project);
    $competencyCode = scoreTenancySetupCompetency($org, $project, $participant);
    scoreTenancyBindCassette($competencyCode);

    // Ambient is already null in a fresh test — nothing to set. dispatch() still
    // goes through Queue::before, matching production.
    ScoreEvaluationJob::dispatch($participant->id);

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    expect($evaluation)->not->toBeNull();
    expect($evaluation->organization_id)->toBe($org->id);

    $competencyResult = CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();
    expect($competencyResult)->not->toBeNull();
    expect($competencyResult->organization_id)->toBe($org->id);

    $indicatorScore = IndicatorScore::withoutGlobalScopes()->where('competency_result_id', $competencyResult->id)->first();
    expect($indicatorScore)->not->toBeNull();
    expect($indicatorScore->organization_id)->toBe($org->id);

    $aiRequest = AiRequest::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();
    expect($aiRequest)->not->toBeNull();
    expect($aiRequest->organization_id)->toBe($org->id);
});

test('(2) ambient holds a foreign org — all 4 written rows still carry the participant org', function (): void {
    $org = scoreTenancyOrg();
    $foreignOrg = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participant = scoreTenancyParticipant($org, $project);
    $competencyCode = scoreTenancySetupCompetency($org, $project, $participant);
    scoreTenancyBindCassette($competencyCode);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($foreignOrg->id);
    $resolver->setBypass(false);

    ScoreEvaluationJob::dispatch($participant->id);

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    expect($evaluation)->not->toBeNull();
    expect($evaluation->organization_id)->toBe($org->id);

    $competencyResult = CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();
    expect($competencyResult)->not->toBeNull();
    expect($competencyResult->organization_id)->toBe($org->id);

    $indicatorScore = IndicatorScore::withoutGlobalScopes()->where('competency_result_id', $competencyResult->id)->first();
    expect($indicatorScore)->not->toBeNull();
    expect($indicatorScore->organization_id)->toBe($org->id);

    $aiRequest = AiRequest::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();
    expect($aiRequest)->not->toBeNull();
    expect($aiRequest->organization_id)->toBe($org->id);
});

test('(3) ambient bypass=true — rows carry the participant org and isBypass() is observed false inside the job', function (): void {
    $org = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participant = scoreTenancyParticipant($org, $project);
    $competencyCode = scoreTenancySetupCompetency($org, $project, $participant);
    scoreTenancyBindCassette($competencyCode);

    // EvaluationCompleted fires INSIDE the TenantContextScope boundary (design D3),
    // so a listener observing resolver state there proves bypass was cleared for
    // the whole pipeline, not merely for the final write.
    $capturedBypass = null;
    Event::listen(EvaluationCompleted::class, function () use (&$capturedBypass): void {
        $capturedBypass = app(TenantResolver::class)->isBypass();
    });

    $resolver = app(TenantResolver::class);
    $resolver->setBypass(true);

    ScoreEvaluationJob::dispatch($participant->id);

    expect($capturedBypass)->toBeFalse('bypass must be false throughout the job, even though ambient bypass was true');

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    expect($evaluation)->not->toBeNull();
    expect($evaluation->organization_id)->toBe($org->id);
});

test('(4) participant org unresolvable — zero rows written, no exception, error logged', function (): void {
    $org = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participantId = scoreTenancyUnresolvableOrgParticipant($project);

    Log::spy();

    // Must not throw — the job fails closed, not loud.
    ScoreEvaluationJob::dispatch($participantId);

    Log::shouldHaveReceived('error')->atLeast()->once();

    expect(Evaluation::withoutGlobalScopes()->where('participant_id', $participantId)->count())->toBe(0);
});

test('(5) no leak — dispatching ScoreEvaluationJob does not bleed context into the next job', function (): void {
    $org = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participant = scoreTenancyParticipant($org, $project);
    $competencyCode = scoreTenancySetupCompetency($org, $project, $participant);
    scoreTenancyBindCassette($competencyCode);

    ScoreEvaluationJob::dispatch($participant->id);

    ScoreEvaluationJobTenancyCapturingJob::dispatch();

    expect(ScoreEvaluationJobTenancyCapturingJob::$capturedState['orgId'])->toBeNull(
        'a job dispatched after ScoreEvaluationJob must not inherit its org'
    );
    expect(ScoreEvaluationJobTenancyCapturingJob::$capturedState['bypass'])->toBeFalse(
        'a job dispatched after ScoreEvaluationJob must not inherit bypass=true'
    );
});

test('(6) failed() with an unresolvable participant org — no exception, error logged, EvaluationFailed still emitted unwrapped', function (): void {
    Event::fake([EvaluationFailed::class]);
    Log::spy();

    $org = scoreTenancyOrg();
    $project = scoreTenancyProject($org);
    $participantId = scoreTenancyUnresolvableOrgParticipant($project);

    $job = new ScoreEvaluationJob($participantId);

    // Must not throw — D9 "ALWAYS emit" outranks context; the event fires unwrapped
    // when the org cannot be derived (design D3).
    $job->failed(new RuntimeException('Simulated queue exhaustion'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'cannot derive organization context in failed()'))
        ->once();

    Event::assertDispatched(EvaluationFailed::class, fn (EvaluationFailed $event): bool => $event->participantId === $participantId);
});
