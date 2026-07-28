<?php

declare(strict_types=1);

/**
 * Shared scoring-engine test fixtures.
 *
 * Moved out of `tests/Feature/Jobs/AiRequestLoggingTest.php`, where it was defined
 * but also consumed by `ScoreEvaluationJobTenancyTest.php`. Under CI's
 * `php artisan test --parallel`, ParaTest can place those two files in different
 * worker processes, leaving the consumer with an undefined function. It had not
 * failed yet only by luck of file distribution — a latent break already sitting on
 * `develop`, found while diagnosing the identical (and actually firing) C10 case.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;

/**
 * Creates a minimal scored competency setup: Role, Competency (attached to project),
 * BarsIndicator with EN translations, and an InterviewSession with one utterance.
 *
 * @return array{role: Role, competency: Competency, session: InterviewSession}
 */
function setupScoringCompetency(Organization $org, Project $project, Participant $participant, string $compCode): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'ROLE_'.$compCode.'_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => $compCode.'_'.uniqid()]);

    // Attach competency to project
    $project->competencies()->attach($competency->id, ['position' => 0]);

    // Create BARS indicator with EN translations
    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Work effectively with others'],
        'anchor_5' => ['en' => 'Always collaborates excellently'],
        'anchor_3' => ['en' => 'Collaborates adequately'],
        'anchor_1' => ['en' => 'Rarely collaborates'],
        'position' => 0,
    ]);
    $indicator->save();

    // Create an InterviewSession for this competency
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    // Add an utterance so transcript is non-empty
    $utt = new Utterance;
    $utt->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'I worked collaboratively on multiple projects.',
        'ts' => now(),
    ]);
    $utt->save();

    return [
        'role' => $role,
        'competency' => $competency,
        'session' => $session,
    ];
}
