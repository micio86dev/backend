<?php

declare(strict_types=1);

/**
 * Direct unit coverage for `App\Actions\Scheduling\RescheduleParticipant`'s
 * own defense-in-depth lead-time re-check (design AD-5's "lock, re-check,
 * only then act" discipline).
 *
 * Every HTTP-level scenario (tests/Feature/C6/ParticipantScheduleTest.php,
 * ParticipantScheduleM2mTest.php) sends a `scheduled_at` too close to now
 * through the controller, where `App\Rules\ScheduledStartWithinLeadTime`
 * ALWAYS intercepts it at request-validation time — the action's own
 * `LeadTimeTooShort` throw is therefore never reached from an HTTP test.
 * This file calls the action directly, bypassing HTTP validation entirely,
 * to exercise that line: it is what protects against the gap between
 * validation and lock acquisition (AD-10's residual-risk list), not
 * something an HTTP test can trigger without controlling the clock.
 */

use App\Actions\Scheduling\RescheduleParticipant;
use App\Enums\ParticipantSchedulingStatus;
use App\Exceptions\Sso\ParticipantScheduleRefusalReason;
use App\Exceptions\Sso\ParticipantScheduleRefused;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function rescheduleActionProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function rescheduleActionParticipant(Project $project, Organization $org, ParticipantSchedulingStatus $status): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'reschedule-action-'.uniqid(),
        'display_name' => 'Reschedule Action Candidate',
        'email' => uniqid('reschedule-action-').'@example.test',
        'status' => 'in_attesa',
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => $status,
    ]);
    $p->save();

    return $p->fresh();
}

test('the action itself refuses a lead time too short even when called directly, bypassing HTTP validation', function (): void {
    $org = Organization::factory()->create();
    $project = rescheduleActionProject($org);
    $participant = rescheduleActionParticipant($project, $org, ParticipantSchedulingStatus::Pending);
    $originalScheduledAt = $participant->scheduled_at->toIso8601String();

    $action = app(RescheduleParticipant::class);

    try {
        $action->handle($participant->id, $org->id, now('UTC')->addMinutes(5));
        $this->fail('Expected ParticipantScheduleRefused to be thrown.');
    } catch (ParticipantScheduleRefused $e) {
        expect($e->reason)->toBe(ParticipantScheduleRefusalReason::LeadTimeTooShort);
    }

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
    expect($participant->scheduled_at->toIso8601String())->toBe($originalScheduledAt);
});
