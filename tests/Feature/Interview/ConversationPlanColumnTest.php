<?php

declare(strict_types=1);

/**
 * `interview_sessions.conversation_plan` (tavus-single-session-interview,
 * API-01, design N2): nullable JSON, cast to an array, null by default.
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planSession(): InterviewSession
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    return InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
}

test('conversation_plan is null by default', function (): void {
    $session = planSession();

    expect($session->fresh()->conversation_plan)->toBeNull();
});

test('conversation_plan round-trips the documented JSON through the cast', function (): void {
    $session = planSession();
    $plan = [
        'competencies' => [
            ['code' => 'PRS', 'primary_questions' => ['Q1', 'Q2'], 'follow_up_budget' => 4],
            ['code' => 'STG', 'primary_questions' => ['Q3'], 'follow_up_budget' => 4],
        ],
        'chars' => 1234,
    ];

    $session->conversation_plan = $plan;
    $session->save();

    expect($session->fresh()->conversation_plan)->toBe($plan);
});
