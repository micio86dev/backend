<?php

declare(strict_types=1);

/**
 * RED+GREEN — B2b.6: pinning test for the non-default gate-policy flag
 * (C13, design.md D7 Open Question #1, D7's "One documented consequence,
 * under a non-default flag").
 *
 * Before B2b, a competency where every indicator failed validation was
 * discarded WHOLE via the single, competency-wide `try` — it persisted
 * `unscorable_reason = 'llm_parse_error'` with ZERO IndicatorScore rows.
 * Under `gate.count_unscorable_against_total = false`, that competency was
 * therefore EXCLUDED from `resolveEvaluationTerminalState()`'s denominator
 * (`CompetencyResult::where('unscorable_reason', null)->count()`).
 *
 * After B2b (per-indicator isolation), the SAME scenario persists
 * `unscorable_reason = NULL` with N IndicatorScore rows — a legally-scored,
 * genuinely-attempted competency that merely failed every indicator. It now
 * ENTERS the denominator under the false policy. This is deliberate and
 * correct (D7): the competency WAS scored, just poorly. This test pins that
 * consequence explicitly so a future change of intent breaks a test here,
 * not slides silently.
 *
 * Setup: 2 competencies for one participant — COMP_A (both its indicators
 * return illegal, out-of-range scores) and COMP_B (scores normally, valid).
 */

use App\Contracts\LLMProvider;
use App\Enums\EvaluationStatus;
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

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project, 2: Participant, 3: string, 4: string}
 *                                                                                  org, project, participant, COMP_A code, COMP_B code
 */
function gatePolicyAllUnassessableSetup(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'gate-policy-'.uniqid(),
        'display_name' => 'Gate Policy Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $role = Role::factory()->create(['code' => 'ROLE_GATE_'.uniqid()]);

    // COMP_A: 2 indicators, BOTH illegal scores — every indicator fails
    // validation, none legally scored.
    $compA = Competency::factory()->create(['code' => 'GATEA_'.uniqid()]);
    $project->competencies()->attach($compA->id, ['position' => 0]);

    foreach ([0, 1] as $position) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $compA->id,
            'text' => ['en' => "COMP_A indicator {$position}"],
            'anchor_5' => ['en' => 'Anchor 5'],
            'anchor_3' => ['en' => 'Anchor 3'],
            'anchor_1' => ['en' => 'Anchor 1'],
            'position' => $position,
        ]);
        $indicator->save();
    }

    // COMP_B: 1 indicator, scores normally (valid).
    $compB = Competency::factory()->create(['code' => 'GATEB_'.uniqid()]);
    $project->competencies()->attach($compB->id, ['position' => 1]);

    $indicatorB = new BarsIndicator;
    $indicatorB->forceFill([
        'role_id' => $role->id,
        'competency_id' => $compB->id,
        'text' => ['en' => 'COMP_B indicator'],
        'anchor_5' => ['en' => 'Always collaborates excellently'],
        'anchor_3' => ['en' => 'Collaborates adequately'],
        'anchor_1' => ['en' => 'Rarely collaborates'],
        'position' => 0,
    ]);
    $indicatorB->save();

    foreach ([$compA, $compB] as $competency) {
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
    }

    return [$org, $project, $participant, $compA->code, $compB->code];
}

test('under the non-default flag, an all-indicators-failed competency now enters the gate denominator', function (): void {
    config(['scoring.gate.count_unscorable_against_total' => false]);

    [, , $participant, $compACode, $compBCode] = gatePolicyAllUnassessableSetup();

    $compAContent = json_encode([
        'behaviors' => [
            ['indicator' => 'COMP_A indicator 0', 'score' => 6, 'explanation' => 'Out of range.', 'excerpts' => []],
            ['indicator' => 'COMP_A indicator 1', 'score' => 0, 'explanation' => 'Out of range.', 'excerpts' => []],
        ],
    ], JSON_THROW_ON_ERROR);

    $compBContent = json_encode([
        'behaviors' => [
            ['indicator' => 'COMP_B indicator', 'score' => 5, 'explanation' => 'Strong evidence.', 'excerpts' => ['I worked collaboratively on multiple projects']],
        ],
    ], JSON_THROW_ON_ERROR);

    $cassette = new CassetteLLMProvider([
        $compACode => $compAContent,
        $compBCode => $compBContent,
    ]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    expect($evaluation)->not->toBeNull();

    $compAResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $compACode)
        ->first();

    // The precondition this pin depends on: COMP_A is NOT discarded — it is
    // NULL-unscorable-reason with 2 persisted (unassessable) IndicatorScore rows.
    expect($compAResult)->not->toBeNull()
        ->and($compAResult->unscorable_reason)->toBeNull()
        ->and($compAResult->valid)->toBeFalse();

    expect(IndicatorScore::where('competency_result_id', $compAResult->id)->count())->toBe(2);

    // The consequence: under the FALSE policy, resolveEvaluationTerminalState()
    // now counts COMP_A (unscorable_reason IS NULL) into the denominator —
    // 1 valid (COMP_B) of 2 total = 50%, below the 90% gate → Pending.
    // PRE-B2b, COMP_A would have been 'llm_parse_error' (excluded from this
    // denominator under the false policy): 1 valid of 1 total = 100% →
    // Completed. This is the exact flip the Open Question names.
    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::Pending);
});

test('the default policy (true) is unaffected: the denominator is still the fixed project-competency count', function (): void {
    // Default — no config() override; `gate.count_unscorable_against_total`
    // ships `true` (config/scoring.php).
    [, , $participant, $compACode, $compBCode] = gatePolicyAllUnassessableSetup();

    $compAContent = json_encode([
        'behaviors' => [
            ['indicator' => 'COMP_A indicator 0', 'score' => 6, 'explanation' => 'Out of range.', 'excerpts' => []],
            ['indicator' => 'COMP_A indicator 1', 'score' => 0, 'explanation' => 'Out of range.', 'excerpts' => []],
        ],
    ], JSON_THROW_ON_ERROR);

    $compBContent = json_encode([
        'behaviors' => [
            ['indicator' => 'COMP_B indicator', 'score' => 5, 'explanation' => 'Strong evidence.', 'excerpts' => ['I worked collaboratively on multiple projects']],
        ],
    ], JSON_THROW_ON_ERROR);

    $cassette = new CassetteLLMProvider([
        $compACode => $compAContent,
        $compBCode => $compBContent,
    ]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();

    // Under the DEFAULT (true) policy, the denominator is
    // $project->competencies()->count() — 2 — regardless of COMP_A's
    // unscorable_reason value (untouched code path, D7). 1 valid of 2 = 50%,
    // below 90% → Pending, exactly as it was before B2b.
    expect($evaluation->fresh()->status)->toBe(EvaluationStatus::Pending);
});
