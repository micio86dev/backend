<?php

declare(strict_types=1);

/**
 * InterviewSessionFactory (interview-session-started-at, D8).
 *
 * The default state MUST agree with production's own INSERT
 * (`InterviewController.php:642-650`) — a `pending` session that never
 * reached the provider carries no recorded start. This is the third
 * appearance of the "fixture synthesizes a state production never writes"
 * defect class on this project.
 *
 * REQ: interview-session "Test fixtures must not synthesize a start
 *      production would not write"
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function factoryStateOrg(): Organization
{
    return Organization::factory()->create();
}

function factoryStateContext(Organization $org): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    return [$fv, $project, $participant];
}

test('a factory-built session in its default state matches production\'s pending INSERT byte-for-byte in field set', function (): void {
    $org = factoryStateOrg();
    [$fv, $project, $participant] = factoryStateContext($org);

    $session = InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);

    expect($session->status)->toBe('pending')
        ->and($session->provider_session_ref)->toBeNull()
        ->and($session->started_at)->toBeNull()
        ->and($session->ended_at)->toBeNull()
        ->and($session->ended_reason)->toBeNull();
});

test('a duration assertion cannot be satisfied by the bare default — it needs a named state', function (): void {
    $org = factoryStateOrg();
    [$fv, $project, $participant] = factoryStateContext($org);

    $default = InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
    $default->load('livePeriods');

    // The bare default carries no live period at all — liveSeconds() is
    // absent, not a plausible-looking number the assertion could pass on
    // by accident.
    expect($default->liveSeconds())->toBeNull();
});

test('->live() creates in_corso with exactly one open period', function (): void {
    $org = factoryStateOrg();
    [$fv, $project, $participant] = factoryStateContext($org);

    $session = InterviewSession::factory()->live()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
    $session->load('livePeriods');

    expect($session->status)->toBe('in_corso')
        ->and($session->livePeriods)->toHaveCount(1)
        ->and($session->livePeriods->first()->ended_at)->toBeNull()
        ->and($session->liveSeconds())->toBeNull(); // open period excluded
});

test('->ended() creates completed with exactly one closed period matching the requested length', function (): void {
    $org = factoryStateOrg();
    [$fv, $project, $participant] = factoryStateContext($org);

    $session = InterviewSession::factory()->ended(300)->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
    $session->load('livePeriods');

    expect($session->status)->toBe('completed')
        ->and($session->livePeriods)->toHaveCount(1)
        ->and($session->liveSeconds())->toBe(300);
});

test('->resumed() creates two closed periods whose gap is excluded from liveSeconds()', function (): void {
    $org = factoryStateOrg();
    [$fv, $project, $participant] = factoryStateContext($org);

    $session = InterviewSession::factory()->resumed(first: 240, gap: 10_800, second: 360)->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
    $session->load('livePeriods');

    expect($session->livePeriods)->toHaveCount(2)
        ->and($session->liveSeconds())->toBe(600); // 240 + 360, the 3h gap excluded
});
