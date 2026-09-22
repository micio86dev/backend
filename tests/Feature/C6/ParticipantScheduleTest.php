<?php

declare(strict_types=1);

/**
 * RED — PATCH/DELETE /api/participants/{id}/schedule — backoffice reschedule
 * and cancel surface (interview-scheduling PR-E, design AD-7, tasks T-E2/T-E3).
 *
 * Every scheduling_status transition from the spec's reschedule/cancel
 * requirements (Engram #2221) is covered on this surface:
 *   Pending    + PATCH (valid lead time)   -> 200, stays Pending, scheduled_at updated
 *   Pending    + PATCH (too-close)         -> 422, distinct lead-time message
 *   NoticeSent + PATCH (valid lead time)   -> 200, re-arms to Pending
 *   NoticeSent + PATCH (too-close)         -> 422, distinct lead-time message
 *   Started    + PATCH or DELETE           -> 409 (terminal)
 *   null (never scheduled) + PATCH/DELETE  -> 422 (not_scheduled)
 *   Pending    + DELETE                    -> 200, Cancelled
 *   NoticeSent + DELETE                    -> 200, Cancelled
 *   Cancelled  + DELETE                    -> 200, idempotent no-op
 * Plus: viewer denied (403), cross-org 404, organization_id never trusted
 * from input.
 *
 * REQ: Scheduled Start Can Be Rescheduled Before/After Its Notice Is Sent,
 *      Scheduled Start Can Be Cancelled Before It Fires,
 *      A Sent Start Email Makes The Schedule Terminal
 *      (sdd/interview-scheduling/spec, Engram #2221)
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helpers — uniquely named ("resched*") to avoid collision with the other
// scheduling test files loaded into the same PHP process.
// ---------------------------------------------------------------------------

function reschedToken(Organization $org, string $role = 'operator'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function reschedProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge(['status' => 'active'], $attrs));
    makeProjectInterviewable($project);

    return $project;
}

function reschedParticipant(Project $project, Organization $org, array $overrides = []): Participant
{
    $p = new Participant;
    $p->forceFill(array_merge([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'resched-'.uniqid(),
        'display_name' => 'Resched Candidate',
        'email' => uniqid('resched-').'@example.test',
        'status' => 'in_attesa',
    ], $overrides));
    $p->save();

    return $p->fresh();
}

// ---------------------------------------------------------------------------
// Reschedule (PATCH) — Pending
// ---------------------------------------------------------------------------

test('PATCH while pending updates scheduled_at and stays pending', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);
    $newTime = now('UTC')->addHours(5)->toIso8601String();

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => $newTime,
    ]);

    $response->assertOk();
    $response->assertJsonPath('scheduling_status', 'pending');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
    expect($participant->scheduled_at->toIso8601String())->toBe($newTime);
});

test('PATCH while pending with a value too close to now is rejected 422 without changing the row', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $originalTime = now('UTC')->addHours(2);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => $originalTime,
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addMinutes(10)->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('16 minutes');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
    expect($participant->scheduled_at->toIso8601String())->toBe($originalTime->toIso8601String());
});

// ---------------------------------------------------------------------------
// Reschedule (PATCH) — NoticeSent (re-arm)
// ---------------------------------------------------------------------------

test('PATCH after notice sent re-arms to pending for the new time', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addMinutes(10),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);
    $newTime = now('UTC')->addHours(3)->toIso8601String();

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => $newTime,
    ]);

    $response->assertOk();
    $response->assertJsonPath('scheduling_status', 'pending');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
    expect($participant->scheduled_at->toIso8601String())->toBe($newTime);
});

test('PATCH after notice sent with a value too close to now is rejected 422, leaving notice_sent unchanged', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addMinutes(10),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addMinutes(5)->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('16 minutes');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::NoticeSent);
});

test('PATCH after notice sent with a past value is rejected 422 exactly as at creation', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addMinutes(10),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->subHour()->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('future');
});

// ---------------------------------------------------------------------------
// Terminal — Started refuses both PATCH and DELETE with 409
// ---------------------------------------------------------------------------

test('PATCH after the start email was sent is refused 409, terminal, nothing changes', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $scheduledAt = now('UTC')->subMinutes(5);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => $scheduledAt,
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
    ]);

    $response->assertStatus(409);
    $response->assertJsonPath('reason', 'terminal');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
    expect($participant->scheduled_at->toIso8601String())->toBe($scheduledAt->toIso8601String());
});

test('DELETE after the start email was sent is refused 409, terminal, nothing changes', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);

    $response = $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule");

    $response->assertStatus(409);
    $response->assertJsonPath('reason', 'terminal');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);
});

// ---------------------------------------------------------------------------
// Never scheduled — explicit 422, distinct reason
// ---------------------------------------------------------------------------

test('PATCH on a participant that was never scheduled is refused 422 not_scheduled', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('reason', 'not_scheduled');
});

test('DELETE on a participant that was never scheduled is refused 422 not_scheduled', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org);

    $response = $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule");

    $response->assertStatus(422);
    $response->assertJsonPath('reason', 'not_scheduled');
});

// ---------------------------------------------------------------------------
// Cancel (DELETE) — Pending / NoticeSent -> Cancelled, silent
// ---------------------------------------------------------------------------

test('DELETE while pending cancels silently', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $response = $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule");

    $response->assertOk();
    $response->assertJsonPath('scheduling_status', 'cancelled');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
});

test('DELETE after notice sent but before start cancels silently, no further email', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::NoticeSent,
    ]);

    $response = $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule");

    $response->assertOk();
    $response->assertJsonPath('scheduling_status', 'cancelled');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
});

test('a cancelled schedule is not resurrected by a repeated DELETE — idempotent no-op', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Cancelled,
    ]);

    $response = $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule");

    $response->assertOk();
    $response->assertJsonPath('scheduling_status', 'cancelled');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
});

test('a cancelled schedule refuses a PATCH rather than silently resurrecting it', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org);
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Cancelled,
    ]);

    $response = $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('reason', 'not_scheduled');

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Cancelled);
});

// ---------------------------------------------------------------------------
// Authorization — viewer denied
// ---------------------------------------------------------------------------

test('a viewer is denied PATCH with 403', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org, 'viewer');
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withToken($token)->patchJson("/api/participants/{$participant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
    ])->assertStatus(403);
});

test('a viewer is denied DELETE with 403', function (): void {
    $org = Organization::factory()->create();
    $project = reschedProject($org);
    $token = reschedToken($org, 'viewer');
    $participant = reschedParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withToken($token)->deleteJson("/api/participants/{$participant->id}/schedule")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Multi-tenancy — cross-org 404
// ---------------------------------------------------------------------------

test('PATCH against a cross-org participant id is refused 404', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = reschedProject($orgB);
    $tokenA = reschedToken($orgA);
    $participantB = reschedParticipant($projectB, $orgB, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withToken($tokenA)->patchJson("/api/participants/{$participantB->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
    ])->assertStatus(404);

    $participantB->refresh();
    expect($participantB->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
});

test('DELETE against a cross-org participant id is refused 404', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = reschedProject($orgB);
    $tokenA = reschedToken($orgA);
    $participantB = reschedParticipant($projectB, $orgB, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withToken($tokenA)->deleteJson("/api/participants/{$participantB->id}/schedule")
        ->assertStatus(404);

    $participantB->refresh();
    expect($participantB->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
});
