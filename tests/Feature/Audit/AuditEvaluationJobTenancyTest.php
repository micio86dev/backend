<?php

declare(strict_types=1);

/**
 * RED (correctness-critical bar, ~95%) — P3a.19/21: AuditEvaluationJob's
 * tenancy scoping and lock-release discipline (scoring-audit-jev, design D7,
 * the Multi-Tenancy table). Mirrors ScoreEvaluationJobTenancyTest.php's own
 * shape: the job derives its org from the aggregate root
 * (`Evaluation::withoutGlobalScopes()->find()`), never from ambient
 * `TenantResolver` state (`Queue::before` already reset it to null) and
 * never from the job's serialized payload.
 */

use App\Contracts\AuditJudge;
use App\Jobs\AuditEvaluationJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use App\Testing\FakeAuditJudge;
use Illuminate\Support\Facades\Cache;

/**
 * @return array{org: Organization, evaluation: Evaluation, judgeable: IndicatorScore}
 */
function auditJobTenancyFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'COL']);
    $judgeable = IndicatorScore::factory()->create([
        'competency_result_id' => $competencyResult->id,
        'position' => 0,
        'score' => 4,
        'excerpts' => ['A verbatim excerpt.'],
    ]);

    return ['org' => $org, 'evaluation' => $evaluation, 'judgeable' => $judgeable];
}

test('ambient holds a foreign org — the run and its audit rows still carry the evaluation\'s own org', function (): void {
    $fixture = auditJobTenancyFixture();
    $foreignOrg = Organization::factory()->create();

    app()->instance(AuditJudge::class, new FakeAuditJudge);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($foreignOrg->id);
    $resolver->setBypass(false);

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->organization_id)->toBe($fixture['org']->id)
        ->and($run->organization_id)->not->toBe($foreignOrg->id);

    $audit = IndicatorScoreAudit::withoutGlobalScopes()->where('audit_run_id', $run->id)->first();
    expect($audit->organization_id)->toBe($fixture['org']->id);
});

test('a foreign org\'s ambient-scoped read sees nothing, even though the rows exist', function (): void {
    $fixture = auditJobTenancyFixture();
    app()->instance(AuditJudge::class, new FakeAuditJudge);

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    expect(IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->count())->toBe(1);

    $foreignOrg = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($foreignOrg->id);
    $resolver->setBypass(false);

    // Ambient global scope — a foreign org must see zero rows.
    expect(IndicatorScoreAuditRun::count())->toBe(0)
        ->and(IndicatorScoreAudit::count())->toBe(0);
});

test('the lock is released in a finally block even when the evaluation cannot be found', function (): void {
    app()->instance(AuditJudge::class, new FakeAuditJudge);

    $missingEvaluationId = 999_999_999;
    $lockKey = "audit:evaluation:{$missingEvaluationId}";
    $lock = Cache::lock($lockKey, 60);
    expect($lock->get())->toBeTrue();
    $owner = $lock->owner();
    $lock->release();

    $lock = Cache::lock($lockKey, 60, $owner);
    expect($lock->get())->toBeTrue();

    expect(fn () => AuditEvaluationJob::dispatch($missingEvaluationId, null, $owner))->not->toThrow(Throwable::class);

    // Re-acquiring the same lock must succeed — proves it was released in
    // the `finally` block despite the job returning early (no evaluation).
    expect(Cache::lock($lockKey, 60)->get())->toBeTrue();
});

test('a null lockOwner is a safe no-op on release — the job never throws for a controller-less dispatch', function (): void {
    $fixture = auditJobTenancyFixture();
    app()->instance(AuditJudge::class, new FakeAuditJudge);

    expect(fn () => AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null))->not->toThrow(Throwable::class);
});
