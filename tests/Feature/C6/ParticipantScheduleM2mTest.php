<?php

declare(strict_types=1);

/**
 * RED — PATCH/DELETE /api/m2m/participants/{id}/schedule — M2M reschedule and
 * cancel surface (interview-scheduling PR-E, design AD-7, tasks T-E2/T-E4).
 *
 * Symmetric with the backoffice surface (tests/Feature/C6/ParticipantScheduleTest.php),
 * which already covers every scheduling_status transition in depth. This file
 * focuses on what is genuinely DIFFERENT on the M2M surface: the new
 * `participants:schedule` ability (deliberately narrower than
 * `participants:create`, AD-7's least-privilege reasoning), cross-tenant
 * isolation, organization_id resolution from the authenticated client (never
 * the request body), cross-surface parity on the terminal/lead-time rules,
 * and a genuine race against the real sweep command.
 *
 * REQ: Reschedule And Cancel Are Symmetric Across Both Surfaces,
 *      Scheduling Preserves Multi-Tenant Isolation,
 *      A Sent Start Email Makes The Schedule Terminal
 *      (sdd/interview-scheduling/spec, Engram #2221)
 */

use App\Enums\ParticipantSchedulingStatus;
use App\Models\ApiClient;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helpers — uniquely named ("pschedM2m*") to avoid Pest's "Cannot redeclare"
// collision with the other scheduling test files loaded into the same
// process (ParticipantM2mSchedulingTest.php's "m2mSched*",
// ParticipantScheduleTest.php's "resched*",
// DispatchScheduledInterviewInvitationsTest.php's "sweep*").
// ---------------------------------------------------------------------------

/**
 * @return array{client: ApiClient, key: string}
 */
function pschedM2mClient(Organization $org, array $abilities): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function pschedM2mProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(array_merge([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));

    makeProjectInterviewable($project);

    return $project;
}

function pschedM2mParticipant(Project $project, Organization $org, array $overrides = []): Participant
{
    $p = new Participant;
    $p->forceFill(array_merge([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'pschedm2m-'.uniqid(),
        'display_name' => 'PSchedM2m Candidate',
        'email' => uniqid('pschedm2m-').'@example.test',
        'role_code' => $project->role_code,
        'language' => 'en',
        'status' => 'in_attesa',
    ], $overrides));
    $p->save();

    return $p->fresh();
}

function pschedM2mOperatorToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

/**
 * Runs the real sweep command the way the scheduler runs it — no ambient
 * tenant context — so the "race against the sweep" test exercises the
 * ACTUAL production lock/idempotency path (design AD-5), not a hand-set
 * status standing in for it.
 */
function pschedM2mRunSweep(): int
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    return Artisan::call('beai:dispatch-scheduled-invitations');
}

// ---------------------------------------------------------------------------
// Ability gating — participants:schedule, deliberately NOT participants:create
// ---------------------------------------------------------------------------

test('an M2M client with only participants:create is denied PATCH with 403', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:create']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
        ])->assertStatus(403);
});

test('an M2M client with only participants:create is denied DELETE with 403', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:create']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->deleteJson("/api/m2m/participants/{$participant->id}/schedule")
        ->assertStatus(403);
});

test('an M2M client with participants:schedule can reschedule and cancel', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);
    $newTime = now('UTC')->addHours(5)->toIso8601String();

    $reschedule = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$participant->id}/schedule", ['scheduled_at' => $newTime]);
    $reschedule->assertOk();
    $reschedule->assertJsonPath('scheduling_status', 'pending');

    $cancel = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->deleteJson("/api/m2m/participants/{$participant->id}/schedule");
    $cancel->assertOk();
    $cancel->assertJsonPath('scheduling_status', 'cancelled');
});

// ---------------------------------------------------------------------------
// Transitions — terminal and not-scheduled parity with the backoffice surface
// ---------------------------------------------------------------------------

test('PATCH after start is refused 409 terminal on the M2M surface too', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
        ]);

    $response->assertStatus(409);
    $response->assertJsonPath('reason', 'terminal');
});

test('DELETE on a never-scheduled participant is refused 422 not_scheduled on the M2M surface too', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    $participant = pschedM2mParticipant($project, $org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->deleteJson("/api/m2m/participants/{$participant->id}/schedule");

    $response->assertStatus(422);
    $response->assertJsonPath('reason', 'not_scheduled');
});

// ---------------------------------------------------------------------------
// Multi-tenancy
// ---------------------------------------------------------------------------

test('organization_id for the schedule mutation always comes from the resolved client, never the request', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
            'organization_id' => 99999,
        ])->assertOk();

    $participant->refresh();
    expect($participant->organization_id)->toBe($org->id);
});

test('a scheduled participant of org A cannot be rescheduled or cancelled by org B\'s M2M client', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectA = pschedM2mProject($orgA);
    $m2mB = pschedM2mClient($orgB, ['participants:schedule']);
    $participantA = pschedM2mParticipant($projectA, $orgA, [
        'scheduled_at' => now('UTC')->addHours(2),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2mB['key']])
        ->patchJson("/api/m2m/participants/{$participantA->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
        ])->assertStatus(404);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2mB['key']])
        ->deleteJson("/api/m2m/participants/{$participantA->id}/schedule")
        ->assertStatus(404);

    $participantA->refresh();
    expect($participantA->scheduling_status)->toBe(ParticipantSchedulingStatus::Pending);
});

// ---------------------------------------------------------------------------
// Cross-surface parity — backoffice vs M2M agree on the terminal refusal
// ---------------------------------------------------------------------------

test('both surfaces agree on refusing a terminal schedule with the same 409 reason', function (): void {
    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $token = pschedM2mOperatorToken($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);

    $backofficeParticipant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);
    $m2mParticipant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(5),
        'scheduling_status' => ParticipantSchedulingStatus::Started,
    ]);

    $backofficeResponse = $this->withToken($token)->patchJson("/api/participants/{$backofficeParticipant->id}/schedule", [
        'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
    ]);
    $m2mResponse = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$m2mParticipant->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
        ]);

    expect($backofficeResponse->status())->toBe(409);
    expect($m2mResponse->status())->toBe(409);
    expect($backofficeResponse->json('reason'))->toBe('terminal');
    expect($m2mResponse->json('reason'))->toBe('terminal');
});

// ---------------------------------------------------------------------------
// Concurrency — a reschedule/cancel racing an in-flight sweep tick
// ---------------------------------------------------------------------------

test('a reschedule racing a sweep tick that already sent the start email loses and is refused 409', function (): void {
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.test']);

    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    // Start-due: scheduled_at already in the past, still Pending — the real
    // sweep command (design AD-4/AD-5/AD-6) will mint, dispatch and advance
    // this row to Started under its own row lock BEFORE the PATCH below runs.
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(1),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    pschedM2mRunSweep();

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->patchJson("/api/m2m/participants/{$participant->id}/schedule", [
            'scheduled_at' => now('UTC')->addHours(2)->toIso8601String(),
        ]);

    $response->assertStatus(409);
    $response->assertJsonPath('reason', 'terminal');
});

test('a cancel racing a sweep tick that already sent the start email loses and is refused 409', function (): void {
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.test']);

    $org = Organization::factory()->create();
    $project = pschedM2mProject($org);
    $m2m = pschedM2mClient($org, ['participants:schedule']);
    $participant = pschedM2mParticipant($project, $org, [
        'scheduled_at' => now('UTC')->subMinutes(1),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);

    pschedM2mRunSweep();

    $participant->refresh();
    expect($participant->scheduling_status)->toBe(ParticipantSchedulingStatus::Started);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->deleteJson("/api/m2m/participants/{$participant->id}/schedule");

    $response->assertStatus(409);
    $response->assertJsonPath('reason', 'terminal');
});
