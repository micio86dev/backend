<?php

declare(strict_types=1);

/**
 * RED — Task 3.9 (bars-full-scale-1-5, PR3): residual score levels end-to-end.
 *
 * Golden cassette test driving the intermediate_golden.php fixture through the full
 * pipeline (parse → validate → persist → mean → serialize) to confirm the widened
 * {1,2,3,4,5,-1} domain survives every layer, not just IndicatorValidator in isolation.
 *
 * Verifies:
 * (a) INTA {4,2,3} → CompetencyResult.score = 3.00, reliability = 1.0.
 * (b) INTB {5,4,-1} → CompetencyResult.score = 4.50, reliability = 2/3 (-1 excluded).
 * (c) INTC {2,3,4,5} → CompetencyResult.score = 3.50 exactly (D9 boundary case — the
 *     mean now routinely lands exactly on a mean-chip boundary once 2/4 are legal).
 * (d) `tests/Fixtures/cassettes/col_slf_golden.php` and `GoldenCassetteTest.php` stay
 *     byte-unchanged and still green (anchors-only regression pin) — confirmed
 *     separately by running that suite unmodified alongside this one.
 *
 * Refs spec: scoring-model "Competency Score Arithmetic" (3.5 boundary scenario),
 * scoring-engine "Competency Mean Recomputed Server-Side"; design.md D9.
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

function intermediateOrg(): Organization
{
    return Organization::factory()->create();
}

function intermediateProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function intermediateParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'intermediate-'.uniqid(),
        'display_name' => 'Intermediate Scale Cassette Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

/**
 * Create the minimal scenario for a competency with N indicators.
 *
 * @param  array<int, array{text: string}>  $indicatorSpecs
 */
function intermediateSetupCompetency(
    Organization $org,
    Project $project,
    Participant $participant,
    string $compCode,
    int $pivotPosition,
    array $indicatorSpecs,
    array $utterances,
): Competency {
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ROLE_'.$compCode.'_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => $compCode]);

    $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => $pivotPosition]]);

    foreach ($indicatorSpecs as $i => $spec) {
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $spec['text']],
            'anchor_5' => ['en' => 'Score 5 anchor for '.$spec['text']],
            'anchor_3' => ['en' => 'Score 3 anchor for '.$spec['text']],
            'anchor_1' => ['en' => 'Score 1 anchor for '.$spec['text']],
            'position' => $i,
        ]);
        $ind->save();
    }

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => $pivotPosition,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    foreach ($utterances as $idx => $line) {
        [$speaker, $text] = explode(': ', $line, 2);
        $utt = new Utterance;
        $utt->forceFill([
            'organization_id' => $org->id,
            'interview_session_id' => $session->id,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now()->addSeconds($idx),
        ]);
        $utt->save();
    }

    return $competency;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a)-(c) residual score levels survive the full pipeline: INTA 3.00, INTB 4.50, INTC 3.50', function (): void {
    $org = intermediateOrg();
    $project = intermediateProject($org);
    $participant = intermediateParticipant($org, $project);

    // INTA: 3 indicators → LLM returns {4,2,3} → mean = 3.00
    $intA = intermediateSetupCompetency($org, $project, $participant, 'INTA', 0, [
        ['text' => 'Adapt communication style to the audience'],
        ['text' => 'Structure information logically'],
        ['text' => 'Check for mutual understanding'],
    ], [
        'Candidate: I adjusted my explanation once I noticed the client looked confused.',
        'Candidate: I kind of jumped between topics but eventually got to the point.',
        'Candidate: I asked if that made sense before moving on.',
    ]);

    // INTB: 3 indicators → LLM returns {5,4,-1} → mean = 4.50
    $intB = intermediateSetupCompetency($org, $project, $participant, 'INTB', 1, [
        ['text' => 'Take initiative without being asked'],
        ['text' => 'Anticipate downstream problems'],
        ['text' => 'Push back on unrealistic deadlines'],
    ], [
        'Candidate: nobody asked me to but I went ahead and fixed the report template myself.',
        'Candidate: I flagged that the deadline would slip before anyone else noticed.',
    ]);

    // INTC: 4 indicators → LLM returns {2,3,4,5} → mean = 3.50 (D9 boundary case)
    $intC = intermediateSetupCompetency($org, $project, $participant, 'INTC', 2, [
        ['text' => 'Give constructive feedback'],
        ['text' => 'Receive feedback without defensiveness'],
        ['text' => 'Follow up on agreed action items'],
        ['text' => 'Document decisions for the team'],
    ], [
        'Candidate: I told them it could be better without saying exactly how.',
        'Candidate: I took the note and reworked the section they mentioned.',
        'Candidate: I checked back on it two days later on my own.',
        'Candidate: I wrote a short summary and sent it to the whole team the same afternoon.',
    ]);

    $cassette = require base_path('tests/Fixtures/cassettes/intermediate_golden.php');

    $cassetteLlm = new CassetteLLMProvider([
        $intA->code => $cassette[$intA->code],
        $intB->code => $cassette[$intB->code],
        $intC->code => $cassette[$intC->code],
    ]);

    $this->app->instance(LLMProvider::class, $cassetteLlm);

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();

    // (a) INTA: score should be 3.00 (mean of {4,2,3})
    $intAResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $intA->code)
        ->first();

    expect($intAResult)->not->toBeNull('INTA CompetencyResult must exist.');
    expect($intAResult->score)->toBe(3.0, 'INTA score must be 3.00 (mean of {4,2,3}).');
    expect($intAResult->reliability)->toBe(1.0, 'INTA reliability must be 1.0 (3/3 assessed).');
    expect($intAResult->valid)->toBeTrue('INTA must be valid (reliability 1.0 >= threshold 0.5).');

    // (b) INTB: score should be 4.50 (mean of {5,4} — -1 excluded)
    $intBResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $intB->code)
        ->first();

    expect($intBResult)->not->toBeNull('INTB CompetencyResult must exist.');
    expect($intBResult->score)->toBe(4.5, 'INTB score must be 4.50 (mean of {5,4}, -1 excluded).');
    expect(round($intBResult->reliability, 4))->toBe(round(2 / 3, 4), 'INTB reliability must be 2/3.');
    expect($intBResult->valid)->toBeTrue('INTB must be valid (reliability 0.6667 >= threshold 0.5).');

    // (c) INTC: score should be exactly 3.50 — the D9 mean-chip boundary case
    $intCResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $intC->code)
        ->first();

    expect($intCResult)->not->toBeNull('INTC CompetencyResult must exist.');
    expect($intCResult->score)->toBe(3.5, 'INTC score must be exactly 3.50 (mean of {2,3,4,5}).');
    expect($intCResult->reliability)->toBe(1.0, 'INTC reliability must be 1.0 (4/4 assessed).');
    expect($intCResult->valid)->toBeTrue('INTC must be valid (reliability 1.0 >= threshold 0.5).');

    // Gate: 3/3 valid → 100% >= 90% → Evaluation.status = completed
    $freshEvaluation = Evaluation::withoutGlobalScopes()->find($evaluation->id);
    expect($freshEvaluation->status->value)->toBe('completed', 'Gate: 3/3 valid → completed.');

    // This Evaluation was scored under the widened prompt — confirms D7's provenance
    // line will show the rubric version that actually produced these residual scores.
    expect($freshEvaluation->prompt_version)->toBe(config('scoring.prompt_version'));
});
