<?php

declare(strict_types=1);

/**
 * RED — 6.3: EvaluationPayloadAssembler (C10, design.md D7).
 *
 * Asserts:
 * - Envelope carries version/event/delivery_id/occurred_at/candidate_ref
 *   (verbatim)/project{id,slug}.
 * - `text` block matches the shape of docs/app_description/03-ux-reference/
 *   esempio-report-valutazione.json (keys, not literal scored values).
 * - `reliability` DELEGATES to ReliabilityRenderer::render() — never re-derives the
 *   round-before-cast formula (asserted via a mock returning a value the real formula
 *   could never produce for the given input).
 * - `files` has exactly `transcript` + `evaluation_raw`, never `audio`.
 * - An unscorable competency serializes `score: null` plus an ADDITIVE
 *   `unscorable_reason` key (absent entirely for scored competencies).
 * - Ordering is deterministic: competencies by project_competencies.position,
 *   behaviors by indicator_scores.position — proven by inserting out of order.
 * - assembleForFailedParticipant(): status from the Evaluation row if one exists,
 *   else the terminal participant state; always an empty text map.
 */

use App\Enums\WebhookEventType;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Scoring\ReliabilityRenderer;
use App\Services\Webhooks\EvaluationPayloadAssembler;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Str;

/**
 * A CompetencyResult's row alone does not put a competency into the payload's `text`
 * block — renderText() orders and filters through project_competencies (the source of
 * truth for "which competencies belong to this project, in what order"). Every test
 * that creates a CompetencyResult for a given code MUST attach that code to the
 * fixture project first, exactly as a real project would have it configured.
 */
function c10AttachCompetency(Project $project, string $code, int $position = 0): Competency
{
    $competency = Competency::factory()->create(['code' => $code]);
    $project->competencies()->attach($competency->id, ['position' => $position]);

    return $competency;
}

/**
 * @return array{0: Organization, 1: Project, 2: Participant, 3: Evaluation}
 */
function c10EvaluationFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['language' => 'en']);
    $participant = Participant::factory()->forProject($project)->create([
        'candidate_ref' => 'candidate-ref-verbatim-9001',
    ]);

    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);

    return [$org, $project, $participant, $evaluation];
}

test('envelope carries version, event=evaluation, delivery_id, occurred_at, candidate_ref verbatim, project{id,slug}', function (): void {
    [, $project, $participant, $evaluation] = c10EvaluationFixtures();
    $deliveryId = (string) Str::uuid();

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, $deliveryId);

    expect($payload['version'])->toBe(config('webhooks.payload.version'))
        ->and($payload['event'])->toBe(WebhookEventType::Evaluation->value)
        ->and($payload['delivery_id'])->toBe($deliveryId)
        ->and($payload['candidate_ref'])->toBe('candidate-ref-verbatim-9001')
        ->and($payload['project'])->toBe(['id' => $project->id, 'slug' => $project->slug])
        ->and($payload['occurred_at'])->not->toBeEmpty();
});

test('reliability delegates to ReliabilityRenderer::render() — never re-derives the formula', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'PRS');

    CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
        'reliability' => 0.6667,
        'score' => 4.0,
    ]);

    // The real formula would render 0.6667 as 67%. Mocking a different return value
    // proves the assembler used the injected renderer rather than recomputing.
    $mock = Mockery::mock(ReliabilityRenderer::class);
    $mock->shouldReceive('render')->once()->with(0.6667)->andReturn(99);
    app()->instance(ReliabilityRenderer::class, $mock);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    expect($payload['data']['text']['PRS']['reliability'])->toBe('99%');
});

test('text block matches the sample report shape exactly (esempio-report-valutazione.json)', function (): void {
    $samplePath = dirname(base_path()).'/docs/app_description/03-ux-reference/esempio-report-valutazione.json';
    $sample = json_decode(file_get_contents($samplePath), true, 512, JSON_THROW_ON_ERROR);

    $sampleEntryKeys = array_keys($sample['COL']);
    sort($sampleEntryKeys);
    $sampleBehaviorKeys = array_keys($sample['COL']['behaviors'][0]);
    sort($sampleBehaviorKeys);

    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'COL');
    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    $ourEntryKeys = array_keys($payload['data']['text']['COL']);
    sort($ourEntryKeys);
    $ourBehaviorKeys = array_keys($payload['data']['text']['COL']['behaviors'][0]);
    sort($ourBehaviorKeys);

    expect($ourEntryKeys)->toBe($sampleEntryKeys)
        ->and($ourBehaviorKeys)->toBe($sampleBehaviorKeys);
});

test('files has exactly transcript and evaluation_raw, never audio', function (): void {
    [, , $participant, $evaluation] = c10EvaluationFixtures();

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());
    $files = $payload['data']['files'];

    expect(array_keys($files))->toEqualCanonicalizing(['transcript', 'evaluation_raw'])
        ->and($files)->not->toHaveKey('audio')
        ->and($files['transcript'])->toBe([
            'type' => 'transcript',
            'ref' => "participant:{$participant->id}",
            'url' => route('admin.participants.transcript.download', ['id' => $participant->id]),
        ])
        ->and($files['evaluation_raw'])->toBe([
            'type' => 'evaluation',
            'ref' => "evaluation:{$evaluation->id}",
            'url' => route('admin.participants.evaluation.download', ['id' => $participant->id]),
        ]);
});

test('files url is absolute and rooted at config(app.url) — not a relative path', function (): void {
    [, , $participant, $evaluation] = c10EvaluationFixtures();

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());
    $files = $payload['data']['files'];

    expect($files['transcript']['url'])->toStartWith((string) config('app.url'))
        ->and($files['evaluation_raw']['url'])->toStartWith((string) config('app.url'));
});

test('unscorable competency serializes score:null plus an additive unscorable_reason key', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'STG');

    CompetencyResult::factory()->unscorable()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'STG',
    ]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());
    $entry = $payload['data']['text']['STG'];

    expect($entry['score'])->toBeNull()
        ->and($entry['unscorable_reason'])->toBe('role_no_bars')
        ->and($entry['behaviors'])->toBe([]);
});

test('a truncated competency\'s llm_truncated reason propagates into the webhook payload with zero production code change (A1.13, C-B)', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'PRS');

    CompetencyResult::factory()->truncated()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());
    $entry = $payload['data']['text']['PRS'];

    expect($entry['score'])->toBeNull()
        ->and($entry['unscorable_reason'])->toBe('llm_truncated')
        ->and($entry['behaviors'])->toBe([]);
});

test('a scored competency never carries an unscorable_reason key (additive-only)', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'PRS');

    CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    expect($payload['data']['text']['PRS'])->not->toHaveKey('unscorable_reason');
});

// ─── unassessable_reason inside behaviors[] (B3, design.md D10) ─────────────

test('an unassessable behaviors[] entry carries an additive unassessable_reason key', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'COL');

    $result = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 5.0,
        'reliability' => 0.5,
        'valid' => true,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0, 'score' => 5]);
    IndicatorScore::factory()->unassessable('excerpt_unverifiable')->create([
        'competency_result_id' => $result->id,
        'position' => 1,
    ]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());
    $behaviors = $payload['data']['text']['COL']['behaviors'];

    expect($behaviors[0])->not->toHaveKey('unassessable_reason')
        ->and($behaviors[1]['unassessable_reason'])->toBe('excerpt_unverifiable');
});

test('a legally-scored behaviors[] entry never carries an unassessable_reason key (additive-only)', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'SLF');

    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'SLF',
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0, 'score' => 3]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    expect($payload['data']['text']['SLF']['behaviors'][0])->not->toHaveKey('unassessable_reason');
});

test('unassessable_reason on a behaviors[] entry does not change payload_version (additive, no version bump)', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();
    c10AttachCompetency($project, 'COL');

    $result = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => null,
        'reliability' => 0.0,
        'valid' => false,
    ]);
    IndicatorScore::factory()->unassessable('score_illegal')->create([
        'competency_result_id' => $result->id,
        'position' => 0,
    ]);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    expect($payload['version'])->toBe((string) config('webhooks.payload.version'));
});

test('deterministic ordering: competencies by project_competencies.position, behaviors by indicator_scores.position', function (): void {
    [, $project, , $evaluation] = c10EvaluationFixtures();

    $colCompetency = Competency::factory()->create(['code' => 'COL']);
    $prsCompetency = Competency::factory()->create(['code' => 'PRS']);

    // Attach out of alphabetical order: PRS at position 0, COL at position 1.
    $project->competencies()->attach([
        $prsCompetency->id => ['position' => 0],
        $colCompetency->id => ['position' => 1],
    ]);

    $resultCol = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'PRS',
    ]);

    // Insert indicator scores in REVERSE position order to prove sorting, not
    // insertion order, determines the output.
    IndicatorScore::factory()->create(['competency_result_id' => $resultCol->id, 'position' => 2, 'indicator_text' => 'third']);
    IndicatorScore::factory()->create(['competency_result_id' => $resultCol->id, 'position' => 0, 'indicator_text' => 'first']);
    IndicatorScore::factory()->create(['competency_result_id' => $resultCol->id, 'position' => 1, 'indicator_text' => 'second']);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForEvaluation($evaluation->id, (string) Str::uuid());

    expect(array_keys($payload['data']['text']))->toBe(['PRS', 'COL']);

    $behaviorOrder = array_column($payload['data']['text']['COL']['behaviors'], 'indicator');
    expect($behaviorOrder)->toBe(['first', 'second', 'third']);
});

test('assembleForFailedParticipant renders status from the Evaluation row when one exists', function (): void {
    [, , $participant, $evaluation] = c10EvaluationFixtures();
    $evaluation->forceFill(['status' => 'pending'])->save();

    $payload = app(EvaluationPayloadAssembler::class)->assembleForFailedParticipant($participant->id, (string) Str::uuid());

    expect($payload['data']['status'])->toBe('pending')
        ->and($payload['data']['text'])->toBe([]);
});

test('assembleForFailedParticipant renders the terminal participant state when no Evaluation row exists', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $participant = Participant::factory()->forProject($project)->create(['status' => 'errore']);

    $payload = app(EvaluationPayloadAssembler::class)->assembleForFailedParticipant($participant->id, (string) Str::uuid());

    expect($payload['data']['status'])->toBe('errore')
        ->and($payload['data']['text'])->toBe([])
        ->and($payload['data']['files'])->toBe([
            'transcript' => [
                'type' => 'transcript',
                'ref' => "participant:{$participant->id}",
                'url' => route('admin.participants.transcript.download', ['id' => $participant->id]),
            ],
        ]);
});
