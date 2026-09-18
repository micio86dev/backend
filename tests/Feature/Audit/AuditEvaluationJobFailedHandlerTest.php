<?php

declare(strict_types=1);

/**
 * RED (documents D6's accepted ceiling) — P3b.13-P3b.14:
 * `AuditEvaluationJob::failed()` — a forced invocation simulating a worker
 * kill/timeout. Writes a best-effort `failed` run row with all four
 * counters at 0 (satisfies the coverage CHECK: `0 = 0+0+0+0`),
 * `failure_reason = 'job_killed'`, and releases the lock — the cost of
 * calls already made before the kill is lost; this is the documented
 * ceiling (design D6), not a defect. Mirrors
 * `ScoreEvaluationJobFailedTest.php`'s own shape.
 */

use App\Enums\Audit\AuditRunStatus;
use App\Jobs\AuditEvaluationJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Cache;

test('failed() writes a best-effort failed run row with all counters at zero, job_killed reason, and no indicator rows', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'COL']);

    $lockKey = "audit:evaluation:{$evaluation->id}";
    $lock = Cache::lock($lockKey, 600);
    expect($lock->get())->toBeTrue();
    $owner = $lock->owner();

    $job = new AuditEvaluationJob($evaluation->id, null, $owner);
    $job->failed(new RuntimeException('Simulated worker kill mid-run.'));

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->failure_reason)->toBe('job_killed')
        ->and($run->indicators_total)->toBe(0)
        ->and($run->indicators_judged)->toBe(0)
        ->and($run->indicators_skipped)->toBe(0)
        ->and($run->indicators_unavailable)->toBe(0)
        ->and($run->indicators_malformed)->toBe(0)
        ->and($run->input_tokens)->toBe(0)
        ->and($run->output_tokens)->toBe(0)
        ->and($run->estimated_cost_usd)->toBeNull()
        ->and($run->latency_ms)->toBe(0)
        ->and($run->organization_id)->toBe($org->id);

    // The cost of calls already made before the kill IS lost — the
    // documented ceiling, not a defect. Zero indicator rows, always.
    expect(IndicatorScoreAudit::withoutGlobalScopes()->where('audit_run_id', $run->id)->count())->toBe(0);

    // The lock the job was handed must be released.
    expect(Cache::lock($lockKey, 60)->get())->toBeTrue();
});

test('failed() skips the tenant-scoped write and still releases the lock when the evaluation cannot be found', function (): void {
    $missingEvaluationId = 999_999_998;
    $lockKey = "audit:evaluation:{$missingEvaluationId}";
    $lock = Cache::lock($lockKey, 600);
    expect($lock->get())->toBeTrue();
    $owner = $lock->owner();

    $job = new AuditEvaluationJob($missingEvaluationId, null, $owner);

    expect(fn () => $job->failed(new RuntimeException('Simulated worker kill.')))->not->toThrow(Throwable::class);

    expect(IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $missingEvaluationId)->count())->toBe(0);

    // Re-acquiring the same lock must succeed — proves it was released.
    expect(Cache::lock($lockKey, 60)->get())->toBeTrue();
});
