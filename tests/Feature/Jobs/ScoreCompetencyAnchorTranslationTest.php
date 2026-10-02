<?php

declare(strict_types=1);

/**
 * ScoreCompetency — a competency whose BARS indicator has no translation in the
 * project's language is recorded as unscorable (`anchor_translation_missing`)
 * and the LLM is NEVER called: there is no silent fallback to English anchors,
 * because scoring against a rubric the candidate's report cannot show would be
 * unverifiable.
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
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an indicator missing the project language is unscorable with anchor_translation_missing and the LLM is never called', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ATM_ROLE_'.uniqid()]);
    $project = Project::factory()->create([
        'status' => 'active',
        'language' => 'it',
        'role_code' => $role->code,
    ]);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'atm-'.uniqid(),
        'display_name' => 'Anchor Translation Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ])->save();

    $competency = Competency::factory()->create(['code' => 'ATM_'.uniqid()]);
    $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 0]]);

    // English-only rubric under an Italian project.
    BarsIndicator::factory()->create([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'English-only indicator'],
        'anchor_5' => ['en' => 'Excellent'],
        'anchor_3' => ['en' => 'Good'],
        'anchor_1' => ['en' => 'Poor'],
        'position' => 0,
    ]);

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
    (new Utterance)->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'Ho lavorato in team su più progetti.',
        'ts' => now(),
    ])->save();

    $llm = Mockery::mock(LLMProvider::class);
    $llm->shouldNotReceive('complete');
    app()->instance(LLMProvider::class, $llm);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->firstOrFail();
    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competency->code)
        ->firstOrFail();

    expect($result->unscorable_reason)->toBe('anchor_translation_missing')
        ->and($result->score)->toBeNull()
        ->and(IndicatorScore::query()->where('competency_result_id', $result->id)->count())->toBe(0);
});
