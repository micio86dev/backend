<?php

declare(strict_types=1);

/**
 * ResolveEvaluationTerminalState — focused unit coverage for the extracted collaborator
 * (split-score-evaluation-job). MOVED out of ScoreEvaluationJob verbatim; this test
 * exercises it directly, in isolation from the rest of the scoring pipeline, whereas
 * previously it was only reachable through a full ScoreEvaluationJob::handle() run.
 *
 * REQ: D5 CC1 gate + D9 lifecycle (C9 PR3) — same behavior, new direct coverage.
 */

use App\Actions\Scoring\ResolveEvaluationTerminalState;
use App\Enums\EvaluationStatus;
use App\Events\EvaluationCompleted;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project, 2: Participant, 3: Evaluation}
 */
function resolveFixtures(string $participantStatus = 'in_valutazione'): array
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
        'candidate_ref' => 'ret-'.uniqid(),
        'display_name' => 'Resolve Terminal State Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => $participantStatus,
    ]);
    $participant->save();
    $participant = Participant::withoutGlobalScopes()->findOrFail($participant->id);

    $evaluation = Evaluation::create([
        'participant_id' => $participant->id,
        'status' => EvaluationStatus::Processing->value,
        'framework_version_id' => $project->framework_version_id,
        'model_version' => config('scoring.model_version'),
        'prompt_version' => config('scoring.prompt_version'),
        'evaluated_at' => null,
        'retry_attempt' => false,
    ]);

    return [$org, $project, $participant, $evaluation];
}

test('gate passes (>=90% valid): evaluation completed, participant completato, EvaluationCompleted fired', function (): void {
    Event::fake([EvaluationCompleted::class]);

    [, $project, $participant, $evaluation] = resolveFixtures();

    $comp = Competency::factory()->create(['code' => 'RETS_PASS_'.uniqid()]);
    $project->competencies()->syncWithoutDetaching([$comp->id => ['position' => 0]]);

    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $comp->code,
        'score' => 4.5,
        'reliability' => 1.0,
        'valid' => true,
        'unscorable_reason' => null,
    ]);

    (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

    $freshEval = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($freshEval->status)->toBe(EvaluationStatus::Completed)
        ->and($freshEval->evaluated_at)->not->toBeNull();

    $freshParticipant = Participant::withoutGlobalScopes()->findOrFail($participant->id);
    expect($freshParticipant->status)->toBe('completato');

    Event::assertDispatched(EvaluationCompleted::class, fn ($e) => $e->evaluationId === $evaluation->id);
});

test('gate fails (<90% valid): evaluation pending, participant STILL completato (D9 — both resolve the participant)', function (): void {
    Event::fake([EvaluationCompleted::class]);

    [, $project, $participant, $evaluation] = resolveFixtures();

    $valid = Competency::factory()->create(['code' => 'RETS_VALID_'.uniqid()]);
    $invalid = Competency::factory()->create(['code' => 'RETS_INVALID_'.uniqid()]);
    $project->competencies()->syncWithoutDetaching([
        $valid->id => ['position' => 0],
        $invalid->id => ['position' => 1],
    ]);

    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $valid->code,
        'score' => 4.0,
        'reliability' => 1.0,
        'valid' => true,
        'unscorable_reason' => null,
    ]);
    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $invalid->code,
        'score' => null,
        'reliability' => 0.0,
        'valid' => false,
        'unscorable_reason' => 'role_no_bars',
    ]);

    (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

    // 1/2 = 50% < 90% → pending.
    $freshEval = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($freshEval->status)->toBe(EvaluationStatus::Pending);

    $freshParticipant = Participant::withoutGlobalScopes()->findOrFail($participant->id);
    expect($freshParticipant->status)->toBe('completato');

    Event::assertDispatched(EvaluationCompleted::class);
});

test('zero-competencies invariant: participant errore, Evaluation left processing, EvaluationCompleted NOT fired', function (): void {
    Event::fake([EvaluationCompleted::class]);

    [, $project, $participant, $evaluation] = resolveFixtures();
    // Deliberately no competencies attached to $project — totalCount() == 0.

    (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

    $freshParticipant = Participant::withoutGlobalScopes()->findOrFail($participant->id);
    expect($freshParticipant->status)->toBe('errore');

    // The invariant guard returns before persisting a terminal status.
    $freshEval = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($freshEval->status)->toBe(EvaluationStatus::Processing);

    Event::assertNotDispatched(EvaluationCompleted::class);
});

test('race guard: participant already errore is left alone (no errore→completato), EvaluationCompleted still fires', function (): void {
    Event::fake([EvaluationCompleted::class]);

    [, $project, $participant, $evaluation] = resolveFixtures(participantStatus: 'errore');

    $comp = Competency::factory()->create(['code' => 'RETS_RACE_'.uniqid()]);
    $project->competencies()->syncWithoutDetaching([$comp->id => ['position' => 0]]);

    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $comp->code,
        'score' => 4.5,
        'reliability' => 1.0,
        'valid' => true,
        'unscorable_reason' => null,
    ]);

    (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

    $freshParticipant = Participant::withoutGlobalScopes()->findOrFail($participant->id);
    expect($freshParticipant->status)->toBe('errore', 'D9 FIX-7: errore→completato is forbidden.');

    Event::assertDispatched(EvaluationCompleted::class, fn ($e) => $e->evaluationId === $evaluation->id);
});

test('alt unscorable policy (count_unscorable_against_total=false) excludes unscorables from the denominator', function (): void {
    Config::set('scoring.gate.count_unscorable_against_total', false);
    Event::fake([EvaluationCompleted::class]);

    [, $project, $participant, $evaluation] = resolveFixtures();

    $valid = Competency::factory()->create(['code' => 'RETS_ALT_VALID_'.uniqid()]);
    $unscorable = Competency::factory()->create(['code' => 'RETS_ALT_UNSC_'.uniqid()]);
    $project->competencies()->syncWithoutDetaching([
        $valid->id => ['position' => 0],
        $unscorable->id => ['position' => 1],
    ]);

    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $valid->code,
        'score' => 4.0,
        'reliability' => 1.0,
        'valid' => true,
        'unscorable_reason' => null,
    ]);
    CompetencyResult::create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => $unscorable->code,
        'score' => null,
        'reliability' => 0.0,
        'valid' => false,
        'unscorable_reason' => 'role_no_bars',
    ]);

    (new ResolveEvaluationTerminalState)->resolve($evaluation, $participant, $project);

    // Excluded from denominator: totalCount = 1 (only $valid), validCount = 1 → 100% → completed.
    $freshEval = Evaluation::withoutGlobalScopes()->findOrFail($evaluation->id);
    expect($freshEval->status)->toBe(EvaluationStatus::Completed);
});
