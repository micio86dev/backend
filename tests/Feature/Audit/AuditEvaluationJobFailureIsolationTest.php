<?php

declare(strict_types=1);

/**
 * RED — P3b.1-P3b.4: AuditEvaluationJob's per-competency `Throwable`
 * isolation (scoring-audit-jev, design D6, spec "A Judge Failure Writes an
 * Explicit Degraded Row and Never Throws Out of the Job"). A failure judging
 * one competency MUST NOT abort the run: every other competency's indicators
 * are still judged, the failed competency's indicators get `unavailable`
 * rows, and the run's own `status`/`failure_reason` records the outcome.
 *
 * Also covers the P3a review finding's recommended RED test: a genuinely
 * malformed vendor envelope through the REAL `TypesafeJevJudge` +
 * `JevResponseMapper` chain (not `FakeAuditJudge`'s canned throw) must be
 * caught by this same per-competency net and downgrade to `unavailable`,
 * proving `JevResponseMapper`'s new type guards (P3b) are reached from the
 * job, not merely unit-tested in isolation.
 */

use App\Contracts\AuditJudge;
use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditRunStatus;
use App\Enums\Audit\AuditVerdictStatus;
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
use App\Services\Audit\TypesafeJevJudge;
use App\Support\Tenancy\TenantResolver;
use App\Testing\FakeAuditJudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Five competencies, one judgeable indicator each (COL, PRS, STG, DRV, INS).
 *
 * @return array{evaluation: Evaluation, indicators: array<string, IndicatorScore>}
 */
function failureIsolationFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $indicators = [];
    foreach (['COL', 'PRS', 'STG', 'DRV', 'INS'] as $code) {
        $comp = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => $code]);
        $indicators[$code] = IndicatorScore::factory()->create([
            'competency_result_id' => $comp->id,
            'position' => 0,
            'score' => 4,
            'excerpts' => ["Verbatim excerpt for {$code}."],
        ]);
    }

    return ['evaluation' => $evaluation, 'indicators' => $indicators];
}

test('a mid-run judge failure on one competency isolates that competency — siblings still judged, run status partial', function (): void {
    $fixture = failureIsolationFixture();
    $fake = new FakeAuditJudge(defaultSupportProbability: 0.8);
    $fake->throwOn('STG');
    app()->instance(AuditJudge::class, $fake);

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    foreach (['COL', 'PRS', 'DRV', 'INS'] as $code) {
        $audit = IndicatorScoreAudit::withoutGlobalScopes()
            ->where('indicator_score_id', $fixture['indicators'][$code]->id)
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->status)->toBe(AuditVerdictStatus::Judged);
    }

    $stgAudit = IndicatorScoreAudit::withoutGlobalScopes()
        ->where('indicator_score_id', $fixture['indicators']['STG']->id)
        ->first();

    expect($stgAudit)->not->toBeNull()
        ->and($stgAudit->status)->toBe(AuditVerdictStatus::Unavailable)
        ->and($stgAudit->outcome_reason)->not->toBeNull()
        ->and($stgAudit->support_probability)->toBeNull();

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();
    expect($run->status)->toBe(AuditRunStatus::Partial)
        ->and($run->failure_reason)->not->toBeNull()
        ->and($run->indicators_unavailable)->toBe(1)
        ->and($run->indicators_judged)->toBe(4);
});

test('the judge throwing for EVERY competency yields status failed, every indicator unavailable, and the job does not throw', function (): void {
    $fixture = failureIsolationFixture();
    $fake = new FakeAuditJudge;
    foreach (['COL', 'PRS', 'STG', 'DRV', 'INS'] as $code) {
        $fake->throwOn($code);
    }
    app()->instance(AuditJudge::class, $fake);

    expect(fn () => AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null))
        ->not->toThrow(Throwable::class);

    foreach ($fixture['indicators'] as $indicator) {
        $audit = IndicatorScoreAudit::withoutGlobalScopes()
            ->where('indicator_score_id', $indicator->id)
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->status)->toBe(AuditVerdictStatus::Unavailable);
    }

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();
    expect($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->failure_reason)->not->toBeNull()
        ->and($run->indicators_unavailable)->toBe(5)
        ->and($run->indicators_judged)->toBe(0);
});

test('a genuinely malformed envelope through the REAL TypesafeJevJudge/JevResponseMapper chain is caught and downgrades to unavailable', function (): void {
    $fixture = failureIsolationFixture();

    // A malformed "usage" field — JevResponseMapper (P3b) now throws
    // AuditJudgeException for this rather than an unguarded cast escaping
    // as a raw ErrorException (carried-forward P3a review finding #2).
    Http::fake(['*' => Http::response([
        'answers' => [],
        'usage' => 'not-an-object',
    ], 200)]);

    app()->instance(AuditJudge::class, new TypesafeJevJudge);

    expect(fn () => AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null))
        ->not->toThrow(Throwable::class);

    foreach ($fixture['indicators'] as $indicator) {
        $audit = IndicatorScoreAudit::withoutGlobalScopes()
            ->where('indicator_score_id', $indicator->id)
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->status)->toBe(AuditVerdictStatus::Unavailable)
            ->and($audit->outcome_reason)->toBe(AuditOutcomeReason::JudgeHttpError);
    }

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();
    expect($run->status)->toBe(AuditRunStatus::Failed);
});

test('a persisted indicator row whose excerpts JSON decodes to PHP null does not kill the run — the run survives and sibling competencies stay judged', function (): void {
    // RED — P4 review finding #1: AuditSubject construction previously ran
    // OUTSIDE the per-competency try/catch. `excerpts` is a NOT NULL `json`
    // column (2026_07_22_000003_create_indicator_scores_table.php:57), so it
    // can never hold a SQL NULL — but it CAN hold the four-byte JSON literal
    // `null`, which is a value distinct from `[]` and is NOT caught by
    // partition()'s `excerpts === []` skip check (D5). The `array` cast then
    // decodes it back to PHP `null`, and building AuditSubject
    // (`excerpts: array_values($indicator->excerpts)`) threw an uncaught
    // TypeError that escaped the per-competency try/catch entirely, killing
    // the whole job (`job_killed`) and discarding every verdict already
    // paid for — contradicting the documented guarantee that one
    // competency's failure never aborts the run.
    $fixture = failureIsolationFixture();

    DB::table('indicator_scores')
        ->where('id', $fixture['indicators']['STG']->id)
        ->update(['excerpts' => 'null']);

    $fake = new FakeAuditJudge(defaultSupportProbability: 0.8);
    app()->instance(AuditJudge::class, $fake);

    expect(fn () => AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null))
        ->not->toThrow(Throwable::class);

    $stgAudit = IndicatorScoreAudit::withoutGlobalScopes()
        ->where('indicator_score_id', $fixture['indicators']['STG']->id)
        ->first();

    expect($stgAudit)->not->toBeNull()
        ->and($stgAudit->status)->toBe(AuditVerdictStatus::Unavailable)
        ->and($stgAudit->outcome_reason)->not->toBeNull();

    foreach (['COL', 'PRS', 'DRV', 'INS'] as $code) {
        $audit = IndicatorScoreAudit::withoutGlobalScopes()
            ->where('indicator_score_id', $fixture['indicators'][$code]->id)
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->status)->toBe(AuditVerdictStatus::Judged);
    }

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();
    expect($run->status)->toBe(AuditRunStatus::Partial)
        ->and($run->indicators_judged)->toBe(4)
        ->and($run->indicators_unavailable)->toBe(1);
});
