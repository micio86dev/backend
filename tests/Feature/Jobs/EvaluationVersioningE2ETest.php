<?php

declare(strict_types=1);

/**
 * RED — Task 22.8: Evaluation versioning fields E2E test (C9 D1 + D8).
 *
 * Full job run via CassetteLLMProvider; assert framework_version_id, model_version,
 * prompt_version are non-null on the persisted Evaluation.
 *
 * Refs spec: D1 "Evaluation versioning fields populated", D8 golden cassette.
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
use App\Models\BarsIndicator;
use App\Models\Competency;
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

test('full job run: Evaluation versioning fields (framework_version_id, model_version, prompt_version) non-null', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'versioning-e2e-'.uniqid(),
        'display_name' => 'Versioning E2E Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    // Set up 1 competency with 1 indicator
    $compCode = 'VER_'.uniqid();
    $role = Role::factory()->create(['code' => 'ROLE_VER_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => $compCode]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $ind = new BarsIndicator;
    $ind->forceFill([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Describe accurately'],
        'anchor_5' => ['en' => 'Always accurate'],
        'anchor_3' => ['en' => 'Often accurate'],
        'anchor_1' => ['en' => 'Rarely accurate'],
        'position' => 0,
    ]);
    $ind->save();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $compCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    $utt = new Utterance;
    $utt->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'I describe products accurately.',
        'ts' => now(),
    ]);
    $utt->save();

    // Wire cassette with score 5 → 1/1 assessed → reliability 1.0 → valid → 1/1=100% → completed
    $cassette = [
        $compCode => json_encode([
            'behaviors' => [
                [
                    'indicator' => 'Describe accurately',
                    'score' => 5,
                    'explanation' => 'Always describes accurately.',
                    'excerpts' => ['I describe products accurately.'],
                ],
            ],
        ]),
    ];
    app()->instance(LLMProvider::class, new CassetteLLMProvider($cassette));

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();
    expect($evaluation->framework_version_id)->not->toBeNull('framework_version_id must be set');
    expect($evaluation->model_version)->not->toBeNull('model_version must be set');
    expect($evaluation->model_version)->not->toBe('');
    expect($evaluation->prompt_version)->not->toBeNull('prompt_version must be set');
    expect($evaluation->prompt_version)->not->toBe('');
    expect($evaluation->evaluated_at)->not->toBeNull('evaluated_at must be set on completed Evaluation');
});
