<?php

declare(strict_types=1);

/**
 * RED — PR-A/T-A3: Participant casts `scheduled_at` to a Carbon instance and
 * `scheduling_status` to the backed `ParticipantSchedulingStatus` enum
 * (interview-scheduling, design AD-1).
 *
 * Neither column is in `$fillable` — every write path in this domain uses
 * `forceFill()` (mirroring the M2M controller's own existing convention);
 * mass assignment of a scheduling field from an uncontrolled array is exactly
 * the kind of surface `$fillable` is deliberately narrow to prevent here.
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Carbon;

function participantSchedulingCastProject(): Project
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['organization_id' => $org->id]);
}

test('scheduled_at round-trips as a Carbon instance', function (): void {
    $project = participantSchedulingCastProject();

    $participant = Participant::factory()->forProject($project)->create();
    $participant->forceFill([
        'scheduled_at' => now()->addDay(),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ])->save();

    expect($participant->fresh()->scheduled_at)->toBeInstanceOf(Carbon::class);
});

test('scheduling_status round-trips as a ParticipantSchedulingStatus enum instance', function (): void {
    $project = participantSchedulingCastProject();

    $participant = Participant::factory()->forProject($project)->create();
    $participant->forceFill([
        'scheduled_at' => now()->addDay(),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ])->save();

    expect($participant->fresh()->scheduling_status)->toBeInstanceOf(ParticipantSchedulingStatus::class)
        ->and($participant->fresh()->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
});

test('a never-scheduled participant casts both columns to null, not a "none" enum case', function (): void {
    $project = participantSchedulingCastProject();

    $participant = Participant::factory()->forProject($project)->create();

    expect($participant->fresh()->scheduled_at)->toBeNull();
    expect($participant->fresh()->scheduling_status)->toBeNull();
});

test('scheduled_at and scheduling_status are not mass-assignable via fillable', function (): void {
    $participant = new Participant;

    expect($participant->isFillable('scheduled_at'))->toBeFalse();
    expect($participant->isFillable('scheduling_status'))->toBeFalse();
});
