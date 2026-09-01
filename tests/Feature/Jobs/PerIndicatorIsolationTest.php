<?php

declare(strict_types=1);

/**
 * RED+GREEN — B2b.1/B2b.3: per-indicator validation-failure isolation
 * (C13, design.md D7, scoring-engine's "Per-Indicator Validation-Failure
 * Isolation" and "Indicator Validation-Failure Reason Vocabulary"
 * requirements).
 *
 * A competency with 3 indicators failing THREE DIFFERENT WAYS — an illegal
 * (out-of-range) score, a non-verbatim excerpt, and a genuine model-declared
 * -1 — must persist ALL THREE IndicatorScore rows, each carrying its own
 * `unassessable_reason`, regardless of which one fails FIRST in processing
 * order. This is only possible once the job's per-indicator `try` lives
 * INSIDE the loop (D7) — before this change, ANY one of these three failing
 * would discard the whole competency via the single, whole-competency `try`.
 *
 * The illegal score is placed FIRST deliberately: it proves an early failure
 * does not poison the indicators that come after it.
 */

use App\Contracts\LLMProvider;
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

test('3 indicators failing 3 different ways all persist, siblings unaffected regardless of order', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'per-indicator-isolation-'.uniqid(),
        'display_name' => 'Per-Indicator Isolation Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $role = Role::factory()->create(['code' => 'ROLE_PII_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'PII_'.uniqid()]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    // 3 indicators, positions 0/1/2 — text is the CANONICAL catalog text
    // (never the echoed LLM text, D4 FIX-8).
    $indicatorTexts = [
        0 => 'Handle out-of-range feedback constructively',
        1 => 'Cite concrete evidence when describing outcomes',
        2 => 'Reflect on situations with no clear resolution',
    ];

    foreach ($indicatorTexts as $position => $text) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $text],
            'anchor_5' => ['en' => 'Anchor 5'],
            'anchor_3' => ['en' => 'Anchor 3'],
            'anchor_1' => ['en' => 'Anchor 1'],
            'position' => $position,
        ]);
        $indicator->save();
    }

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
        'text' => 'I handled the feedback and moved the project forward.',
        'ts' => now(),
    ]);
    $utt->save();

    // Position 0: ILLEGAL out-of-range score (6) — IndicatorValidator throws,
    //             must be caught INSIDE the per-indicator loop, first.
    // Position 1: EXCERPT NOT VERBATIM — "a claim never made" is not a
    //             substring of the transcript above.
    // Position 2: MODEL-DECLARED -1 — legal, no exception at all.
    $llmContent = json_encode([
        'behaviors' => [
            ['indicator' => $indicatorTexts[0], 'score' => 6, 'explanation' => 'Out of range.', 'excerpts' => []],
            ['indicator' => $indicatorTexts[1], 'score' => 4, 'explanation' => 'Unverifiable.', 'excerpts' => ['a claim never made']],
            ['indicator' => $indicatorTexts[2], 'score' => -1, 'explanation' => 'No episode given.', 'excerpts' => []],
        ],
    ], JSON_THROW_ON_ERROR);

    $cassette = new CassetteLLMProvider([$competency->code => $llmContent]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    expect($evaluation)->not->toBeNull();

    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competency->code)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBeNull('The competency is NOT discarded — it survives with a lower reliability (D7).');

    // The post-condition (B2b.3): every DTO the parser produced persisted as
    // its own row — no sibling silently dropped, regardless of which failed
    // or in what order.
    $rows = IndicatorScore::where('competency_result_id', $result->id)
        ->orderBy('position')
        ->get();

    expect($rows)->toHaveCount(3, 'count($validated) === count($dtos) — no sibling is ever silently dropped.');

    expect($rows[0]->score)->toBe(-1)
        ->and($rows[0]->unassessable_reason)->toBe('score_illegal')
        ->and($rows[1]->score)->toBe(-1)
        ->and($rows[1]->unassessable_reason)->toBe('excerpt_unverifiable')
        ->and($rows[2]->score)->toBe(-1)
        ->and($rows[2]->unassessable_reason)->toBe('model_declared');
});
