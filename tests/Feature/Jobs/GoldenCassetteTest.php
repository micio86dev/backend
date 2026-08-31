<?php

declare(strict_types=1);

/**
 * RED — Task 14.8: Golden cassette tests using CassetteLLMProvider (C9 D8).
 *
 * Verifies:
 * (a) Full job run with COL+SLF cassette; assert CompetencyResult.score = 3.67 for COL.
 * (b) Same run; assert CompetencyResult.score = 4.0 for SLF.
 *
 * Cassette keyed by competency_code; assertion uses string form '3.67' not raw float equality.
 *
 * Refs spec: D8 "Golden cassette COL 3.67 from {5,3,3}", "Golden cassette SLF 4.0 from {5,3,-1}".
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
use App\Services\Scoring\ReliabilityRenderer;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function goldenOrg(): Organization
{
    return Organization::factory()->create();
}

function goldenProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function goldenParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'golden-'.uniqid(),
        'display_name' => 'Golden Cassette Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

/**
 * Create the minimal scenario for a competency with N indicators.
 *
 * @param  array<int, array{score: int, text: string}>  $indicatorSpecs
 */
function createGoldenCompetency(
    Organization $org,
    Project $project,
    Participant $participant,
    string $compCode,
    int $pivotPosition,
    array $indicatorSpecs,
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

    // Create interview session
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => $pivotPosition,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    // Utterances sourced from evaluation-report-example.json (Italian transcript).
    // These MUST contain the verbatim excerpts in the col_slf_golden.php cassette fixture.
    $utterances = [
        'Candidate: I worked collaboratively on multiple projects.',
        "Candidate: Quello che abbiamo fatto è stato di cambiare le nostre abitudini e quindi di interfacciarci direttamente l'uno con l'altro.",
        "Candidate: è stato un esempio di collaborazione fuori dagli schemi che ha funzionato molto bene e ha arricchito sia l'uno che l'altro.",
        'Candidate: è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi che avevamo in quel momento.',
        'Candidate: Recentemente mi è capitato di dover lavorare a stretto contatto con un collega.',
        'Candidate: quello che ho fatto è stato veramente spiegare qual era la necessità reale dietro questa idea.',
        'Candidate: avevamo parlato direttamente con dei potenziali clienti e quindi avevamo capito i loro bisogni.',
        'Candidate: Il risultato è stato che i colleghi hanno effettivamente visto e si sono convinti della bontà di questa idea.',
    ];

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

test('(a)+(b) golden cassette: COL {5,3,3} → 3.67 and SLF {5,3,-1} → 4.0', function (): void {
    $org = goldenOrg();
    $project = goldenProject($org);
    $participant = goldenParticipant($org, $project);

    // COL: 3 indicators → LLM returns {5,3,3} → mean = 3.67
    $colCompetency = createGoldenCompetency($org, $project, $participant, 'COL', 0, [
        ['text' => 'Work effectively with others', 'score' => 5],
        ['text' => 'Willingly help colleagues in trouble', 'score' => 3],
        ['text' => 'Demonstrate commitment to team goals', 'score' => 3],
    ]);

    // SLF: 3 indicators → LLM returns {5,3,-1} → mean = 4.0
    $slfCompetency = createGoldenCompetency($org, $project, $participant, 'SLF', 1, [
        ['text' => 'Describe products and services accurately', 'score' => 5],
        ['text' => 'Link own arguments to customer needs', 'score' => 3],
        ['text' => 'Negotiate to reach solutions', 'score' => -1],
    ]);

    // Load golden cassette from fixture
    $cassette = require base_path('tests/Fixtures/cassettes/col_slf_golden.php');

    // Bind cassette by competency_code
    $cassetteLlm = new CassetteLLMProvider([
        $colCompetency->code => $cassette[$colCompetency->code],
        $slfCompetency->code => $cassette[$slfCompetency->code],
    ]);

    $this->app->instance(LLMProvider::class, $cassetteLlm);

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();

    // COL: score should be 3.67 (mean of 5,3,3)
    $colResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $colCompetency->code)
        ->first();

    expect($colResult)->not->toBeNull('COL CompetencyResult must exist.');
    expect($colResult->score)->toBe(3.67, 'COL score must be 3.67 (mean of {5,3,3}).');

    // COL PR3: reliability = 3/3 = 1.0 (all 3 assessed); valid = true (1.0 >= T=0.5)
    expect($colResult->reliability)->toBe(1.0, 'COL reliability must be 1.0 (3/3 assessed).');
    expect($colResult->valid)->toBeTrue('COL must be valid (reliability 1.0 >= threshold 0.5).');

    // COL PR3: rendered as 100% via ReliabilityRenderer
    $renderer = new ReliabilityRenderer;
    expect($renderer->render($colResult->reliability))->toBe(100, 'COL reliability must render as 100%.');

    // SLF: score should be 4.0 (mean of {5,3} — -1 excluded)
    $slfResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $slfCompetency->code)
        ->first();

    expect($slfResult)->not->toBeNull('SLF CompetencyResult must exist.');
    expect($slfResult->score)->toBe(4.0, 'SLF score must be 4.0 (mean of {5,3}, -1 excluded).');

    // SLF PR3: reliability = 2/3 ≈ 0.6667; rendered 67% (round-before-cast); valid = true (>= 0.5)
    expect(round($slfResult->reliability, 4))->toBe(round(2 / 3, 4), 'SLF reliability must be 2/3.');
    expect($slfResult->valid)->toBeTrue('SLF must be valid (reliability 0.6667 >= threshold 0.5).');
    expect($renderer->render($slfResult->reliability))->toBe(67, 'SLF reliability must render as 67% (round-before-cast).');

    // PR3 Gate: 2/2 valid → 100% >= 90% → Evaluation.status = completed
    $freshEvaluation = Evaluation::withoutGlobalScopes()->find($evaluation->id);
    expect($freshEvaluation->status->value)->toBe('completed', 'Gate: 2/2 valid → completed.');
    expect($freshEvaluation->evaluated_at)->not->toBeNull('evaluated_at must be set on completed Evaluation.');

    // PR3 Lifecycle: participant in_valutazione → completato
    $freshParticipant = Participant::withoutGlobalScopes()->find($participant->id);
    expect($freshParticipant->status)->toBe('completato', 'Lifecycle: participant must be completato.');
});
