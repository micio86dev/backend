<?php

declare(strict_types=1);

/**
 * RED — POST /api/entry-links scheduled creation (interview-scheduling PR-B,
 * design AD-1 amendment / AD-2 / AD-3, tasks T-B1/T-B2/T-B4).
 *
 * Covers:
 * - scheduled_at present -> eager Participant row (scheduling_status=pending),
 *   no mint, no email dispatch, no entry_url in the response.
 * - scheduled_at absent -> byte-for-byte unchanged immediate path (explicit
 *   non-regression: still mints/dispatches, still creates NO participant row
 *   at this endpoint).
 * - validation: past, too-close, and no-explicit-offset all reject with
 *   HTTP 422 and DISTINCT messages; a value clearing the lead time succeeds.
 * - duplicate (project_id, candidate_ref) / (project_id, email) on the
 *   scheduled path -> 409, same shape the immediate path already uses.
 * - multi-tenancy: organization_id always comes from the resolved project,
 *   never from the request; cross-org project_id -> 404.
 * - ParticipantResource exposes scheduled_at/scheduling_status (pending on
 *   the scheduled path, null/null for a never-scheduled participant).
 *
 * REQ: Optional Scheduled Start On Participant Creation,
 *      Scheduled Start Must Be In The Future,
 *      Scheduled Participants Are Trackable As Not Yet Notified
 *      (sdd/interview-scheduling/spec, Engram #2221)
 */

use App\Jobs\SendCandidateInvitationJob;
use App\Models\ApiClient;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function schedulingOperatorToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function schedulingProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create(array_merge([
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

/**
 * @return array{client: ApiClient, key: string}
 */
function schedulingM2mClient(Organization $org, array $abilities = ['participants:read']): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

// ---------------------------------------------------------------------------
// Scheduled path — creation
// ---------------------------------------------------------------------------

test('a scheduled create persists scheduled_at and scheduling_status pending, and does not dispatch the invitation job', function (): void {
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);
    $scheduledAt = now('UTC')->addMinutes(30)->toIso8601String();

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-1',
        'display_name' => 'Scheduled Candidate',
        'email' => uniqid('sched-').'@example.test',
        'lang' => 'en',
        'scheduled_at' => $scheduledAt,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('scheduling_status', 'pending');
    expect($response->json('scheduled_at'))->toBeString();
    expect($response->json())->not->toHaveKey('entry_url');
    expect($response->json())->not->toHaveKey('expires_at');

    $participant = Participant::where('candidate_ref', 'sched-cand-1')->firstOrFail();
    expect($participant->organization_id)->toBe($org->id);
    expect($participant->status)->toBe('in_attesa');
    expect($participant->scheduling_status->value)->toBe('pending');
    expect($participant->scheduled_at)->not->toBeNull();

    Bus::assertNotDispatched(SendCandidateInvitationJob::class);
});

test('a scheduled create with a non-zero explicit UTC offset persists and returns the correct UTC instant, not the local wall-clock digits', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    // The instant under test, computed once in UTC. The request body encodes
    // the SAME instant with an explicit non-zero "+02:00" offset instead of
    // "Z"/"+00:00" — every other case in this file builds its input from a
    // zero-offset clock, so this is the only case that can catch a regression
    // that stores the raw wall-clock digits or shifts by the offset instead
    // of converting it (R3-offset-roundtrip-unproved).
    $expectedInstant = now('UTC')->addMinutes(90)->startOfSecond();
    $inputWithNonZeroOffset = $expectedInstant->copy()->setTimezone('+02:00')->toIso8601String();
    expect($inputWithNonZeroOffset)->toContain('+02:00');

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-offset',
        'display_name' => 'Offset Candidate',
        'email' => uniqid('sched-').'@example.test',
        'lang' => 'en',
        'scheduled_at' => $inputWithNonZeroOffset,
    ]);

    $response->assertStatus(201);

    $returnedInstant = Carbon::parse($response->json('scheduled_at'));
    expect($returnedInstant->equalTo($expectedInstant))->toBeTrue();
    expect($returnedInstant->getTimestamp())->toBe($expectedInstant->getTimestamp());

    $participant = Participant::where('candidate_ref', 'sched-cand-offset')->firstOrFail();
    expect($participant->scheduled_at->equalTo($expectedInstant))->toBeTrue();
    expect($participant->scheduled_at->getTimestamp())->toBe($expectedInstant->getTimestamp());

    // Guards specifically against the wall-clock-digits regression: the local
    // representation ("...T<local time>+02:00") is two hours AHEAD of the
    // correct UTC instant, so if the offset were ever dropped or misapplied
    // the persisted/returned instant would equal this wrong value instead.
    $wallClockDigitsMisreadAsUtc = $expectedInstant->copy()->addHours(2);
    expect($participant->scheduled_at->equalTo($wallClockDigitsMisreadAsUtc))->toBeFalse();
});

test('omitting scheduled_at preserves the exact immediate behavior — mints, dispatches, and creates no participant row here', function (): void {
    Bus::fake();
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'immediate-cand-1',
        'display_name' => 'Immediate Candidate',
        'email' => uniqid('immediate-').'@example.test',
        'lang' => 'en',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['entry_url', 'expires_at', 'email_sent']);
    expect($response->json())->not->toHaveKey('data');

    // Non-regression: the immediate path still creates the row only at SSO
    // exchange time (SsoExchangeController), never here — untouched by this
    // change, asserted explicitly so a future edit cannot silently make it
    // eager for BOTH paths.
    expect(Participant::where('candidate_ref', 'immediate-cand-1')->exists())->toBeFalse();

    Bus::assertDispatched(SendCandidateInvitationJob::class);
});

// ---------------------------------------------------------------------------
// Validation — distinct 422 reasons
// ---------------------------------------------------------------------------

test('a past scheduled_at is rejected with 422, distinct from the too-close message', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-past',
        'display_name' => 'Past Candidate',
        'email' => uniqid('sched-').'@example.test',
        'scheduled_at' => now('UTC')->subHour()->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('future');
    expect(Participant::where('candidate_ref', 'sched-cand-past')->exists())->toBeFalse();
});

test('a scheduled_at only 10 minutes out is rejected with 422 for a lead-time reason distinct from a past-time rejection', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-tooclose',
        'display_name' => 'Too Close Candidate',
        'email' => uniqid('sched-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(10)->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('16 minutes');
    expect($response->json('errors.scheduled_at.0'))->not->toContain('must be a time in the future');
    expect(Participant::where('candidate_ref', 'sched-cand-tooclose')->exists())->toBeFalse();
});

test('a scheduled_at 20 minutes out clears the minimum lead time and succeeds', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-20min',
        'display_name' => '20 Minute Candidate',
        'email' => uniqid('sched-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(20)->toIso8601String(),
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('scheduling_status', 'pending');
    expect(Participant::where('candidate_ref', 'sched-cand-20min')->exists())->toBeTrue();
});

test('a scheduled_at with no explicit UTC offset is rejected with 422', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-nooffset',
        'display_name' => 'No Offset Candidate',
        'email' => uniqid('sched-').'@example.test',
        // Bare local datetime — no Z, no +HH:MM.
        'scheduled_at' => now('UTC')->addMinutes(30)->format('Y-m-d\TH:i:s'),
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('explicit UTC offset');
    expect(Participant::where('candidate_ref', 'sched-cand-nooffset')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Conflicts — scheduled path uses the same 409 shape as the immediate path
// ---------------------------------------------------------------------------

test('a duplicate candidate_ref on the scheduled path returns 409 exactly as the immediate path does', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $existing = new Participant;
    $existing->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-dup-ref',
        'display_name' => 'Existing',
        'email' => uniqid('existing-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $existing->save();

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-dup-ref',
        'display_name' => 'New Attempt',
        'email' => uniqid('newattempt-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
    ]);

    $response->assertStatus(409);
    expect($response->json('reason'))->toBe('duplicate_candidate_ref');
    // `message` carries a CODE, never a hand-written sentence — same
    // machine-facing convention this endpoint already uses for every other
    // refusal (CLAUDE.md "machine-facing responses are not localized").
    expect($response->json('message'))->toBe('entry_link_participant_duplicate_candidate_ref');
});

test('a duplicate email on the scheduled path returns 409 with an explicit, distinguishable reason', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);
    $sharedEmail = uniqid('shared-').'@example.test';

    $existing = new Participant;
    $existing->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-dup-email-original',
        'display_name' => 'Existing',
        'email' => $sharedEmail,
        'status' => 'in_attesa',
    ]);
    $existing->save();

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-dup-email-new',
        'display_name' => 'New Attempt',
        'email' => $sharedEmail,
        'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
    ]);

    $response->assertStatus(409);
    expect($response->json('reason'))->toBe('duplicate_email');
    expect($response->json('message'))->toBe('entry_link_participant_duplicate_email');
});

// ---------------------------------------------------------------------------
// Multi-tenancy
// ---------------------------------------------------------------------------

test('a scheduled create sets organization_id from the resolved project, never from the request', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $token = schedulingOperatorToken($org);

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'sched-cand-tenant',
        'display_name' => 'Tenant Candidate',
        'email' => uniqid('sched-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        'organization_id' => 99999, // must be ignored — not even a validated field
    ])->assertStatus(201);

    $participant = Participant::where('candidate_ref', 'sched-cand-tenant')->firstOrFail();
    expect($participant->organization_id)->toBe($org->id);
    expect($participant->organization_id)->not->toBe(99999);
});

test('a scheduled create against a cross-org project_id is refused with 404, exactly as the immediate path is', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = schedulingProject($orgB);
    $token = schedulingOperatorToken($orgA);

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $projectB->id,
        'candidate_ref' => 'sched-cand-crosstenant',
        'display_name' => 'Cross Tenant',
        'email' => uniqid('sched-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
    ])->assertStatus(404);
});

// ---------------------------------------------------------------------------
// ParticipantResource — scheduled_at / scheduling_status (T-B4)
// ---------------------------------------------------------------------------

test('a never-scheduled participant exposes scheduled_at and scheduling_status as null via ParticipantResource', function (): void {
    $org = Organization::factory()->create();
    $project = schedulingProject($org);
    $m2m = schedulingM2mClient($org);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'never-scheduled-cand',
        'display_name' => 'Never Scheduled',
        'email' => uniqid('never-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $participant->save();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->getJson('/api/m2m/participants/'.$participant->id);

    $response->assertOk();
    expect($response->json('data'))->toHaveKey('scheduled_at');
    expect($response->json('data'))->toHaveKey('scheduling_status');
    $response->assertJsonPath('data.scheduled_at', null);
    $response->assertJsonPath('data.scheduling_status', null);
});
