<?php

declare(strict_types=1);

/**
 * Scoring retry branch (scoring-retry-rt-b, design D8/D9, slice PR2a).
 *
 * A participant whose Evaluation is `pending` and whose single retry was
 * authorized (`evaluations.retry_attempt = true`, set by AuthorizeEvaluationRetry)
 * re-interviews its INVALID competencies and lands back in `in_valutazione`.
 * ScoreEvaluationJob must then, in one transaction, delete the invalid results
 * and flip the Evaluation to `processing`, re-score only the competencies that
 * have no result, and finish with a definitive `completed` whatever the ratio.
 *
 * The database row is authoritative: the job payload flag is only a hint.
 * Every case drives the real job with the cassette LLM provider; no mocks of
 * the code under test.
 *
 * REQ: Retry - Single Re-Interview of a `pending` Evaluation (RT-B),
 *      ScoreEvaluationJob start-of-job guard
 *      (openspec/changes/scoring-retry-rt-b/specs/scoring-engine/spec.md)
 */

use App\Contracts\LLMProvider;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Jobs\ScoreEvaluationJob;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * One organization, one standard project of `$o['competencies']` competencies,
 * each with a BARS indicator, a completed session and a candidate utterance
 * `Answer for {code}.`; an Evaluation with a result per competency, the first
 * `$o['valid']` valid and the rest unscorable. Every result carries one
 * indicator score so the cascade of a delete is observable.
 *
 * @param  array<string, mixed>  $o
 * @return array{
 *     org: Organization,
 *     project: Project,
 *     participant: Participant,
 *     evaluation: Evaluation,
 *     codes: list<string>,
 *     validCodes: list<string>,
 *     invalidCodes: list<string>,
 * }
 */
function retryScoringWorld(array $o = []): array
{
    $o += [
        'competencies' => 10,
        'valid' => 6,
        'participant' => 'in_valutazione',
        'evaluation' => 'pending',
        'retryAttempt' => true,
    ];

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ROLE_RS_'.uniqid()]);
    $project = Project::factory()->create(['status' => 'active', 'language' => 'en', 'role_code' => $role->code]);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'rs-'.uniqid(),
        'display_name' => 'Retry Scoring Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $o['participant'],
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $evaluationFactory = Evaluation::factory();
    $evaluationFactory = match ($o['evaluation']) {
        'completed' => $evaluationFactory->completed(),
        'processing' => $evaluationFactory->processing(),
        default => $evaluationFactory->pending(),
    };
    $evaluation = $evaluationFactory->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $project->framework_version_id,
        'retry_attempt' => $o['retryAttempt'],
    ]);

    $codes = [];
    for ($i = 0; $i < $o['competencies']; $i++) {
        $competency = Competency::factory()->create(['code' => 'RS'.$i.'_'.uniqid()]);
        $code = (string) $competency->code;
        $codes[] = $code;
        $project->competencies()->attach($competency->id, ['position' => $i]);

        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => 'Indicator for '.$code],
            'anchor_5' => ['en' => 'Excellent'],
            'anchor_3' => ['en' => 'Good'],
            'anchor_1' => ['en' => 'Poor'],
            'position' => 0,
        ]);
        $indicator->save();

        $session = InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => $i,
            'competency_code' => $code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'fake',
            'status' => 'completed',
        ]);

        $utterance = new Utterance;
        $utterance->forceFill([
            'organization_id' => $org->id,
            'interview_session_id' => $session->id,
            'speaker' => 'Candidate',
            'text' => 'Answer for '.$code.'.',
            'ts' => now()->addSeconds($i),
        ]);
        $utterance->save();

        $result = CompetencyResult::factory();
        $result = $i < $o['valid'] ? $result->valid() : $result->unscorable();
        $result = $result->create(['evaluation_id' => $evaluation->id, 'competency_code' => $code]);
        IndicatorScore::factory()->create(['competency_result_id' => $result->id]);
    }

    return [
        'org' => $org,
        'project' => $project,
        'participant' => $participant,
        'evaluation' => $evaluation,
        'codes' => $codes,
        'validCodes' => array_slice($codes, 0, $o['valid']),
        'invalidCodes' => array_slice($codes, $o['valid']),
    ];
}

/** A cassette response scoring one indicator 5 with a verbatim excerpt of the candidate answer. */
function retryScoringResponse(string $code): string
{
    return json_encode(['behaviors' => [[
        'indicator' => 'Indicator for '.$code,
        'score' => 5,
        'explanation' => 'Clear evidence.',
        'excerpts' => ['Answer for '.$code.'.'],
    ]]], JSON_THROW_ON_ERROR);
}

/**
 * @param  list<string>  $codes
 * @param  array<string, string>  $overrides
 */
function retryScoringCassette(array $codes, array $overrides = []): CassetteLLMProvider
{
    $entries = [];
    foreach ($codes as $code) {
        $entries[$code] = $overrides[$code] ?? retryScoringResponse($code);
    }

    $cassette = new CassetteLLMProvider($entries);
    app()->instance(LLMProvider::class, $cassette);

    return $cassette;
}

/**
 * Raw competency_results and indicator_scores rows of an evaluation, keyed by code,
 * timestamps included, to prove "byte-for-byte unchanged".
 *
 * @param  list<string>  $codes
 * @return array<string, array{result: object, indicators: list<object>}>
 */
function retryScoringSnapshot(int $evaluationId, array $codes): array
{
    $snapshot = [];
    foreach ($codes as $code) {
        $result = DB::table('competency_results')
            ->where('evaluation_id', $evaluationId)
            ->where('competency_code', $code)
            ->first();
        $snapshot[$code] = [
            'result' => $result,
            'indicators' => $result === null ? [] : DB::table('indicator_scores')
                ->where('competency_result_id', $result->id)
                ->orderBy('id')
                ->get()
                ->all(),
        ];
    }

    return $snapshot;
}

function retryScoringEvaluation(int $participantId): Evaluation
{
    return Evaluation::withoutGlobalScopes()->where('participant_id', $participantId)->firstOrFail();
}

// ─── 6.2 / 6.3  merge + re-score ─────────────────────────────────────────────

test('merge: only the invalid competencies are re-scored, valid results and the evaluation row survive untouched', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    $cassette = retryScoringCassette($w['invalidCodes']);

    $validBefore = retryScoringSnapshot($w['evaluation']->id, $w['validCodes']);
    $invalidBefore = retryScoringSnapshot($w['evaluation']->id, $w['invalidCodes']);
    $oldInvalidResultIds = array_map(fn (array $s): int => $s['result']->id, $invalidBefore);
    $oldInvalidIndicatorIds = collect($invalidBefore)->pluck('indicators')->flatten()->pluck('id')->all();
    expect($oldInvalidResultIds)->toHaveCount(4)
        ->and($oldInvalidIndicatorIds)->toHaveCount(4);

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    // No duplicate LLM call: exactly the four invalid competencies were sent.
    expect($cassette->callCount())->toBe(4)
        ->and($cassette->getRequestedCompetencyCodes())->toEqualCanonicalizing($w['invalidCodes']);

    // Valid results and their indicator scores: identical rows, ids and timestamps included.
    expect(retryScoringSnapshot($w['evaluation']->id, $w['validCodes']))->toEqual($validBefore);

    // The invalid ones were replaced: old rows (and their indicator scores) are gone, new valid ones exist.
    expect(DB::table('competency_results')->whereIn('id', $oldInvalidResultIds)->count())->toBe(0)
        ->and(DB::table('indicator_scores')->whereIn('id', $oldInvalidIndicatorIds)->count())->toBe(0);
    foreach ($w['invalidCodes'] as $code) {
        $result = DB::table('competency_results')->where('evaluation_id', $w['evaluation']->id)->where('competency_code', $code)->first();
        expect($result)->not->toBeNull()
            ->and((bool) $result->valid)->toBeTrue()
            ->and((float) $result->score)->toBe(5.0);
    }

    // The same Evaluation row, updated in place.
    expect(Evaluation::withoutGlobalScopes()->where('participant_id', $w['participant']->id)->count())->toBe(1);
    $evaluation = retryScoringEvaluation($w['participant']->id);
    expect($evaluation->id)->toBe($w['evaluation']->id)
        ->and($evaluation->status)->toBe(EvaluationStatus::Completed)
        ->and($evaluation->retry_attempt)->toBeTrue()
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('completato');

    Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
    Event::assertDispatched(EvaluationCompleted::class, fn (EvaluationCompleted $e): bool => $e->evaluationId === $w['evaluation']->id);
});

test('versions: a retry records the model and prompt versions of the retry run and keeps the pinned framework version', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    retryScoringCassette($w['invalidCodes']);
    DB::table('evaluations')->where('id', $w['evaluation']->id)->update(['model_version' => 'model-first-run', 'prompt_version' => 'prompt-first-run']);
    $frameworkBefore = (int) DB::table('evaluations')->where('id', $w['evaluation']->id)->value('framework_version_id');
    config(['scoring.model_version' => 'model-retry-run', 'scoring.prompt_version' => 'prompt-retry-run']);

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    $evaluation = retryScoringEvaluation($w['participant']->id);
    expect($evaluation->model_version)->toBe('model-retry-run')
        ->and($evaluation->prompt_version)->toBe('prompt-retry-run')
        ->and((int) $evaluation->framework_version_id)->toBe($frameworkBefore);
});

test('definitive outcome: a retry below the 90 percent gate still ends completed, never pending', function (): void {
    Event::fake([EvaluationCompleted::class, EvaluationFailed::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    // The re-interview answers cannot be parsed: the four invalid competencies stay unscorable (6/10 valid).
    retryScoringCassette($w['invalidCodes'], array_fill_keys($w['invalidCodes'], 'not json at all'));

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    $evaluation = retryScoringEvaluation($w['participant']->id);
    $validCount = CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->where('valid', true)->count();

    expect($validCount)->toBe(6)
        ->and($evaluation->status)->toBe(EvaluationStatus::Completed)
        ->and($evaluation->retry_attempt)->toBeTrue()
        ->and($evaluation->evaluated_at)->not->toBeNull()
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('completato');
    Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
    Event::assertNotDispatched(EvaluationFailed::class);
});

test('control: a first-attempt run below the gate still ends pending', function (): void {
    // Pins that the forced `completed` is keyed on retry_attempt and not a general relaxation.
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6, 'evaluation' => 'processing', 'retryAttempt' => false]);
    retryScoringCassette($w['invalidCodes'], array_fill_keys($w['invalidCodes'], 'not json at all'));
    // A processing first run resumes: drop the invalid rows so the loop scores them again (and fails to parse).
    CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $w['evaluation']->id)->where('valid', false)->delete();

    (new ScoreEvaluationJob($w['participant']->id))->handle();

    expect(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending);
});

test('merge atomicity: a failure after the delete and before the status flip leaves results and status untouched', function (): void {
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    retryScoringCassette($w['invalidCodes']);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);

    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'update "evaluations"')) {
            throw new RuntimeException('injected failure between the delete and the flip');
        }
    });

    expect(fn () => (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle())
        ->toThrow(RuntimeException::class, 'injected failure');
    $armed = false;

    expect(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before)
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('in_valutazione');
});

// ─── 6.4  guard table (design D8) ────────────────────────────────────────────

test('guard: processing + retry_attempt resumes on the resume-skip path without re-merging', function (): void {
    Event::fake([EvaluationCompleted::class]);
    // A crash after the merge left only valid results; one stale invalid row is planted to prove no re-merge runs.
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6, 'evaluation' => 'processing']);
    $cassette = retryScoringCassette([]);
    $staleBefore = retryScoringSnapshot($w['evaluation']->id, $w['invalidCodes']);

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    expect($cassette->callCount())->toBe(0)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['invalidCodes']))->toEqual($staleBefore);
    $evaluation = retryScoringEvaluation($w['participant']->id);
    // D9: a processing + retry_attempt evaluation is forced completed, whatever the ratio.
    expect($evaluation->status)->toBe(EvaluationStatus::Completed)
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('completato');
    Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
});

test('guard: pending + retry_attempt while the participant has not re-interviewed is a logged no-op', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['participant' => 'in_attesa']);
    $cassette = retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);
    Log::spy();

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'ScoreEvaluationJob: retry not scored — participant has not re-interviewed'
            && $context['participant_id'] === $w['participant']->id
            && $context['participant_status'] === 'in_attesa')
        ->once();
    expect($cassette->callCount())->toBe(0)
        ->and(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before)
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('in_attesa');
    Event::assertNotDispatched(EvaluationCompleted::class);
});

test('guard: completed + retry_attempt is a logged no-op with no LLM call, write or event', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['evaluation' => 'completed', 'participant' => 'completato']);
    $cassette = retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);
    $evaluatedAt = DB::table('evaluations')->where('id', $w['evaluation']->id)->value('evaluated_at');
    Log::spy();

    // Payload true and payload false behave the same: the superseded or duplicated dispatch is dropped.
    foreach ([true, false] as $payloadFlag) {
        (new ScoreEvaluationJob($w['participant']->id, retryAttempt: $payloadFlag))->handle();
    }

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'ScoreEvaluationJob: retry already completed — no-op'
            && $context['evaluation_id'] === $w['evaluation']->id)
        ->twice();
    expect($cassette->callCount())->toBe(0)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before)
        ->and(DB::table('evaluations')->where('id', $w['evaluation']->id)->value('evaluated_at'))->toBe($evaluatedAt);
    Event::assertNotDispatched(EvaluationCompleted::class);
});

test('guard: a payload flag without a retry authorization in the database is a logged no-op', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['retryAttempt' => false]);
    $cassette = retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);
    Log::spy();

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'ScoreEvaluationJob: retry flag without a database authorization — no-op'
            && $context['evaluation_id'] === $w['evaluation']->id)
        ->once();
    // It is the retry-specific no-op, not the first-attempt "already terminal" one on top.
    Log::shouldNotHaveReceived('info', ['ScoreEvaluationJob: Evaluation already terminal — no-op', Mockery::any()]);
    expect($cassette->callCount())->toBe(0)
        ->and(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before);
    Event::assertNotDispatched(EvaluationCompleted::class);
});

test('guard: a database retry authorization with a payload flag of false still merges, with a warning', function (): void {
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 8]);
    $cassette = retryScoringCassette($w['invalidCodes']);
    Log::spy();

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: false))->handle();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'ScoreEvaluationJob: database has a retry authorization but the job payload flag is false — merging anyway'
            && $context['evaluation_id'] === $w['evaluation']->id)
        ->once();
    expect($cassette->callCount())->toBe(2)
        ->and(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Completed)
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('completato');
    Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
});

test('guard: a first-attempt pending evaluation with both flags false stays the unchanged terminal no-op', function (): void {
    $w = retryScoringWorld(['retryAttempt' => false]);
    $cassette = retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);

    (new ScoreEvaluationJob($w['participant']->id))->handle();

    expect($cassette->callCount())->toBe(0)
        ->and(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before);
});

test('the retry merge reads the evaluation FOR UPDATE inside its transaction', function (): void {
    // One connection cannot show contention, so the lock is pinned the way the PR1b action pins its own:
    // the statement text, issued while a transaction is open.
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 4, 'valid' => 2]);
    retryScoringCassette($w['invalidCodes']);
    $lockLevels = [];
    DB::listen(function ($query) use (&$lockLevels): void {
        if (str_contains($query->sql, 'from "evaluations"') && str_contains($query->sql, 'for update')) {
            $lockLevels[] = DB::transactionLevel();
        }
    });
    $baseLevel = DB::transactionLevel();

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    expect($lockLevels)->toHaveCount(1)
        ->and($lockLevels[0])->toBeGreaterThan($baseLevel);
});

test('the retry merge runs only when the evaluation is still pending under the row lock', function (): void {
    // A concurrent job flipped the evaluation to processing between the guard read and the lock:
    // the second job must not delete anything.
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);

    $flipped = false;
    DB::listen(function ($query) use (&$flipped, $w): void {
        // Right after the guard's plain read (before the merge takes its `for update` lock),
        // the racing job completes the evaluation.
        if (! $flipped
            && str_starts_with($query->sql, 'select')
            && str_contains($query->sql, 'from "evaluations"')
            && ! str_contains($query->sql, 'for update')) {
            $flipped = true;
            DB::table('evaluations')->where('id', $w['evaluation']->id)->update(['status' => 'completed']);
        }
    });

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    expect($flipped)->toBeTrue()
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before);
    Event::assertNotDispatched(EvaluationCompleted::class);
});

test('the retry merge runs only while the participant is still in_valutazione under its row lock', function (): void {
    // The candidate (or an operator) moved the participant between the guard and the merge:
    // nothing may be deleted, and the participant is locked BEFORE the evaluation, the order the
    // authorization action uses, so the two can never deadlock each other.
    Event::fake([EvaluationCompleted::class]);
    $w = retryScoringWorld(['competencies' => 10, 'valid' => 6]);
    $cassette = retryScoringCassette([]);
    $before = retryScoringSnapshot($w['evaluation']->id, $w['codes']);

    $flipped = false;
    $lockOrder = [];
    DB::listen(function ($query) use (&$flipped, &$lockOrder, $w): void {
        if (! $flipped
            && str_starts_with($query->sql, 'select')
            && str_contains($query->sql, 'from "evaluations"')
            && ! str_contains($query->sql, 'for update')) {
            $flipped = true;
            DB::table('participants')->where('id', $w['participant']->id)->update(['status' => 'in_corso']);
        }

        if (str_contains($query->sql, 'for update')) {
            $lockOrder[] = str_contains($query->sql, 'from "participants"') ? 'participants' : (str_contains($query->sql, 'from "evaluations"') ? 'evaluations' : 'other');
        }
    });

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    expect($flipped)->toBeTrue()
        ->and($cassette->callCount())->toBe(0)
        ->and(retryScoringSnapshot($w['evaluation']->id, $w['codes']))->toEqual($before)
        ->and(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Pending)
        ->and($lockOrder[0] ?? null)->toBe('participants');
    Event::assertNotDispatched(EvaluationCompleted::class);
});

// ─── 6.5  forced completed on an emptied composition (D9) ────────────────────

test('a retry on a project with zero scorable competencies ends completed, never errore', function (): void {
    Event::fake([EvaluationCompleted::class, EvaluationFailed::class]);
    $w = retryScoringWorld(['competencies' => 0, 'valid' => 0]);
    retryScoringCassette([]);

    (new ScoreEvaluationJob($w['participant']->id, retryAttempt: true))->handle();

    expect(retryScoringEvaluation($w['participant']->id)->status)->toBe(EvaluationStatus::Completed)
        ->and(DB::table('participants')->where('id', $w['participant']->id)->value('status'))->toBe('completato');
    Event::assertDispatchedTimes(EvaluationCompleted::class, 1);
    Event::assertNotDispatched(EvaluationFailed::class);
});

// ─── PR2b pick-up ────────────────────────────────────────────────────────────

// Owned by slice PR2b (tasks 7.1-7.8), deliberately NOT implemented in PR2a:
test('PR2b: failed() and endParticipantUnresolvable() finalize a retry as completed, never errore')->todo();
test('PR2b: the retry completion webhook is delivered under the {evaluation_id}:retry dedupe key')->todo();
test('PR2b: retry-era progress webhooks use the :retry dedupe suffix')->todo();
test('PR2b: RecoverFailedParticipant admits an interview-stage errore during an in-flight retry')->todo();
