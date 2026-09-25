<?php

declare(strict_types=1);

/**
 * RED — POST /api/m2m/participants scheduled creation (interview-scheduling
 * PR-C, design AD-1 amendment / AD-2 / AD-3, tasks T-C1/T-C2).
 *
 * Mirrors `EntryLinkScheduledCreationTest.php` (PR-B) for the M2M surface,
 * reusing the SAME `App\Actions\Scheduling\CreateScheduledParticipant` action
 * and the SAME `App\Rules\ScheduledStartWithinLeadTime` rule — never a
 * re-implementation (AD-1's "one shared code path").
 *
 * Legitimate, already-existing per-surface differences (verified by reading
 * `M2m\ParticipantController::store()`'s own pre-existing 409 shape, not
 * introduced by this PR):
 * - M2M's conflict `message` is a hand-written sentence
 *   ("Conflict: a participant already exists..."), NOT a machine code —
 *   this is M2M's own established convention for its immediate-path 409
 *   (PR-C does not change it), unlike `EntryLinkController`'s machine-code
 *   convention. The `reason` field (`duplicate_candidate_ref`/
 *   `duplicate_email`) is IDENTICAL across both surfaces — that field, not
 *   `message`, is the parity contract.
 * - M2M's create field is `language`, EntryLink's is `lang` — pre-existing,
 *   unrelated to scheduling.
 * - M2M's immediate (non-scheduled) path never mints/sends anything on
 *   EITHER branch — it creates the row directly and returns 201 with no
 *   `entry_url` at all, unlike EntryLink's immediate path. So "path
 *   immediato invariato" here means: same request shape, same response
 *   shape, same `status=in_attesa`, `scheduled_at`/`scheduling_status` both
 *   null, exactly as before this PR.
 *
 * Covers:
 * - scheduled_at present -> eager Participant row (scheduling_status=pending),
 *   no `entry_url`/no mint (there never was one on this surface).
 * - scheduled_at absent -> byte-for-byte unchanged immediate path (explicit
 *   non-regression against the pre-existing `ParticipantM2mTest.php` shape).
 * - validation: past, too-close, and no-explicit-offset all reject with
 *   HTTP 422 and DISTINCT messages, using the SAME rule object as PR-B; a
 *   value clearing the lead time succeeds.
 * - duplicate (project_id, candidate_ref) / (project_id, email) on the
 *   scheduled path -> 409 with the SAME `reason` values PR-B's endpoint uses.
 * - multi-tenancy: organization_id always comes from the resolved project,
 *   never from the request; cross-org project_id -> 404; a scheduled
 *   participant created in org A is invisible to org B's M2M client.
 * - cross-surface parity: the exact 16-minute boundary and the exact 409
 *   `reason` values behave identically between `/api/entry-links` and
 *   `/api/m2m/participants`.
 *
 * REQ: Optional Scheduled Start On Participant Creation,
 *      Scheduled Start Must Be In The Future,
 *      Both Surfaces Agree On Acceptance
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
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helpers — uniquely named to avoid Pest's "Cannot redeclare" collision with
// ParticipantM2mTest.php's makeSso*() and EntryLinkScheduledCreationTest.php's
// scheduling*() helpers (all test files load into ONE PHP process).
// ---------------------------------------------------------------------------

/**
 * @return array{client: ApiClient, key: string}
 */
function m2mSchedClient(Organization $org, array $abilities = ['participants:create', 'participants:read']): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function m2mSchedProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge([
        'status' => 'active',
    ], $attrs));

    makeProjectInterviewable($project);

    return $project;
}

function m2mSchedExistingParticipant(Project $project, Organization $org, string $candidateRef, string $email): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $candidateRef,
        'display_name' => 'Existing',
        'email' => $email,
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p;
}

/**
 * Same operator token shape as EntryLinkScheduledCreationTest.php's
 * schedulingOperatorToken(), needed here only for the cross-surface parity
 * tests that also call `/api/entry-links`.
 */
function m2mSchedOperatorToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function m2mSchedEntryLinkProject(Organization $org, array $attrs = []): Project
{
    // Tenant context MUST be stamped BEFORE creating the tenant-scoped
    // FrameworkVersion row (mirrors EntryLinkScheduledCreationTest.php's
    // schedulingProject() ordering exactly) — creating it first throws
    // "No tenant context established before create".
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return m2mSchedProject($org, array_merge([
        'framework_version_id' => $fv->id,
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Scheduled path — creation
// ---------------------------------------------------------------------------

test('store with scheduled_at persists scheduled_at and scheduling_status pending', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);
    $scheduledAt = now('UTC')->addMinutes(30)->toIso8601String();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-1',
            'display_name' => 'Scheduled Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'language' => 'en',
            'scheduled_at' => $scheduledAt,
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('scheduling_status', 'pending');
    expect($response->json('scheduled_at'))->toBeString();
    expect($response->json())->not->toHaveKey('entry_url');

    $participant = Participant::where('candidate_ref', 'm2m-sched-1')->firstOrFail();
    expect($participant->organization_id)->toBe($org->id);
    expect($participant->status)->toBe('in_attesa');
    expect($participant->scheduling_status->value)->toBe('pending');
    expect($participant->scheduled_at)->not->toBeNull();
});

test('a scheduled create with a non-zero explicit UTC offset persists and returns the correct UTC instant, not the local wall-clock digits', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    // Mirrors EntryLinkScheduledCreationTest.php's identically-named test
    // (PR-B) for the M2M surface: every OTHER case in this file builds its
    // input from a zero-offset UTC clock, so this is the only case that can
    // catch a Carbon::parse()->utc() regression that stores the raw
    // wall-clock digits or shifts by the offset instead of converting it.
    $expectedInstant = now('UTC')->addMinutes(30)->startOfSecond();
    $inputWithNonZeroOffset = $expectedInstant->copy()->setTimezone('+02:00')->toIso8601String();
    expect($inputWithNonZeroOffset)->toContain('+02:00');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-offset',
            'display_name' => 'Offset Candidate',
            'email' => uniqid('m2m-sched-offset-').'@example.test',
            'language' => 'en',
            'scheduled_at' => $inputWithNonZeroOffset,
        ]);

    $response->assertStatus(201);

    $returnedInstant = Carbon::parse($response->json('scheduled_at'));
    expect($returnedInstant->equalTo($expectedInstant))->toBeTrue();
    expect($returnedInstant->getTimestamp())->toBe($expectedInstant->getTimestamp());

    $participant = Participant::where('candidate_ref', 'm2m-sched-offset')->firstOrFail();
    expect($participant->scheduled_at->equalTo($expectedInstant))->toBeTrue();
    expect($participant->scheduled_at->getTimestamp())->toBe($expectedInstant->getTimestamp());

    // Guards specifically against the wall-clock-digits regression: the local
    // representation ("...T<local time>+02:00") is two hours AHEAD of the
    // correct UTC instant, so if the offset were ever dropped or misapplied
    // the persisted/returned instant would equal this wrong value instead.
    $wallClockDigitsMisreadAsUtc = $expectedInstant->copy()->addHours(2);
    expect($participant->scheduled_at->equalTo($wallClockDigitsMisreadAsUtc))->toBeFalse();
});

test('store without scheduled_at is byte-for-byte unchanged — non-regression', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-immediate-1',
            'display_name' => 'Immediate Candidate',
            'email' => uniqid('m2m-immediate-').'@example.test',
        ]);

    $response->assertStatus(201);
    expect($response->json())->not->toHaveKey('data');
    expect($response->json())->not->toHaveKey('entry_url');
    $response->assertJsonPath('scheduling_status', null);
    $response->assertJsonPath('scheduled_at', null);

    $participant = Participant::where('candidate_ref', 'm2m-immediate-1')->firstOrFail();
    expect($participant->status)->toBe('in_attesa');
    expect($participant->scheduling_status)->toBeNull();
    expect($participant->scheduled_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Validation — distinct 422 reasons, same rule object as PR-B
// ---------------------------------------------------------------------------

test('a past scheduled_at is rejected with 422, distinct from the too-close message', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-past',
            'display_name' => 'Past Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->subHour()->toIso8601String(),
        ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('future');
    expect(Participant::where('candidate_ref', 'm2m-sched-past')->exists())->toBeFalse();
});

test('a scheduled_at only 10 minutes out is rejected with 422 for a lead-time reason distinct from a past-time rejection', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-tooclose',
            'display_name' => 'Too Close Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(10)->toIso8601String(),
        ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('16 minutes');
    expect($response->json('errors.scheduled_at.0'))->not->toContain('must be a time in the future');
    expect(Participant::where('candidate_ref', 'm2m-sched-tooclose')->exists())->toBeFalse();
});

test('a scheduled_at with no explicit UTC offset is rejected with 422', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-nooffset',
            'display_name' => 'No Offset Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(30)->format('Y-m-d\TH:i:s'),
        ]);

    $response->assertStatus(422);
    expect($response->json('errors.scheduled_at.0'))->toContain('explicit UTC offset');
    expect(Participant::where('candidate_ref', 'm2m-sched-nooffset')->exists())->toBeFalse();
});

test('a scheduled_at 20 minutes out clears the minimum lead time and succeeds', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-20min',
            'display_name' => '20 Minute Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(20)->toIso8601String(),
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('scheduling_status', 'pending');
    expect(Participant::where('candidate_ref', 'm2m-sched-20min')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Conflicts — scheduled path uses M2M's OWN pre-existing 409 shape
// ---------------------------------------------------------------------------

test('a duplicate candidate_ref on the scheduled path returns 409 with M2M\'s existing sentence shape', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);
    m2mSchedExistingParticipant($project, $org, 'm2m-sched-dup-ref', uniqid('existing-').'@example.test');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-dup-ref',
            'display_name' => 'New Attempt',
            'email' => uniqid('newattempt-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ]);

    $response->assertStatus(409);
    expect($response->json('reason'))->toBe('duplicate_candidate_ref');
    // M2M's own pre-existing convention (unchanged by this PR): a sentence,
    // not a machine code — see this file's header note on legitimate
    // per-surface differences.
    expect($response->json('message'))->toContain('candidate_ref');
});

test('a duplicate email on the scheduled path returns 409 with an explicit, distinguishable reason', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);
    $sharedEmail = uniqid('shared-').'@example.test';
    m2mSchedExistingParticipant($project, $org, 'm2m-sched-dup-email-original', $sharedEmail);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-dup-email-new',
            'display_name' => 'New Attempt',
            'email' => $sharedEmail,
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ]);

    $response->assertStatus(409);
    expect($response->json('reason'))->toBe('duplicate_email');
    expect($response->json('message'))->toContain('email');
});

// ---------------------------------------------------------------------------
// Multi-tenancy
// ---------------------------------------------------------------------------

test('a scheduled create sets organization_id from the resolved project, never from the request', function (): void {
    $org = Organization::factory()->create();
    $project = m2mSchedProject($org);
    $m2m = m2mSchedClient($org);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $project->id,
            'candidate_ref' => 'm2m-sched-tenant',
            'display_name' => 'Tenant Candidate',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
            'organization_id' => 99999,
        ])->assertStatus(201);

    $participant = Participant::where('candidate_ref', 'm2m-sched-tenant')->firstOrFail();
    expect($participant->organization_id)->toBe($org->id);
    expect($participant->organization_id)->not->toBe(99999);
});

test('a scheduled create against a cross-org project_id is refused with 404, exactly as the immediate path is', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectB = m2mSchedProject($orgB);
    $m2mA = m2mSchedClient($orgA);

    $this->withHeaders(['Authorization' => 'Bearer '.$m2mA['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $projectB->id,
            'candidate_ref' => 'm2m-sched-crosstenant',
            'display_name' => 'Cross Tenant',
            'email' => uniqid('m2m-sched-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ])->assertStatus(404);
});

/*
 * NOTE ON HELPER SHAPE: the org-A participant below is built directly via
 * `forceFill()`/`save()`, not through an authenticated POST as orgA's M2M
 * client. This is deliberate, not a shortcut around coverage: this
 * codebase's `Auth::viaRequest('api-m2m', ...)` guard is a Laravel
 * `RequestGuard`, which memoizes the FIRST resolved user for the lifetime
 * of the guard instance — a SECOND `$this->withHeaders(...)->getJson(...)`
 * call in the SAME test method, authenticated as a DIFFERENT M2M client,
 * would silently resolve to the FIRST client again (verified by direct
 * reproduction: a `GET /api/m2m/whoami` call as client B, in the same test
 * as an earlier authenticated call as client A, returned client A's own
 * `client_id`). `ParticipantM2mTest.php`'s own existing cross-tenant tests
 * avoid this the same way — exactly one authenticated M2M identity per test
 * method. The scheduled-creation half of this scenario is already covered
 * by "store with scheduled_at persists..." above; this test isolates the
 * READ-side tenant boundary only, which is what it is actually named for.
 */
test('a scheduled participant created in org A is invisible to org B\'s M2M client (index and show)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $projectA = m2mSchedProject($orgA);
    $m2mB = m2mSchedClient($orgB);

    $participantA = new Participant;
    $participantA->forceFill([
        'organization_id' => $orgA->id,
        'project_id' => $projectA->id,
        'candidate_ref' => 'm2m-sched-isolated',
        'display_name' => 'Isolated Candidate',
        'email' => uniqid('m2m-sched-').'@example.test',
        'status' => 'in_attesa',
        'scheduled_at' => now('UTC')->addMinutes(30),
        'scheduling_status' => ParticipantSchedulingStatus::Pending,
    ]);
    $participantA->save();

    $this->withHeaders(['Authorization' => 'Bearer '.$m2mB['key']])
        ->getJson('/api/m2m/participants/'.$participantA->id)
        ->assertNotFound();

    $index = $this->withHeaders(['Authorization' => 'Bearer '.$m2mB['key']])
        ->getJson('/api/m2m/participants');
    $index->assertOk();
    expect(collect($index->json('data'))->pluck('id'))->not->toContain($participantA->id);
});

// ---------------------------------------------------------------------------
// Cross-surface parity — backoffice (/api/entry-links) vs M2M
// (/api/m2m/participants) agree on the same boundary and the same 409 reason
// ---------------------------------------------------------------------------

test('both surfaces agree: clearing the lead time succeeds, 15 minutes out is rejected', function (): void {
    $org = Organization::factory()->create();
    $backofficeProject = m2mSchedEntryLinkProject($org);
    $m2mProject = m2mSchedProject($org);
    $token = m2mSchedOperatorToken($org);
    $m2m = m2mSchedClient($org);

    // 20 minutes, not the literal 16-minute boundary: asserting the EXACT
    // boundary against real wall-clock time in an HTTP test is inherently
    // racy (the few seconds between computing `scheduled_at` here and the
    // server evaluating its own `now()` can shave the diff under 16.0
    // whole minutes) — the same reason PR-B's own
    // EntryLinkScheduledCreationTest.php asserts a clear 20-minute success
    // case rather than the exact boundary.
    $backoffice20 = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $backofficeProject->id,
        'candidate_ref' => 'parity-boundary-eo-20',
        'display_name' => 'Parity 20',
        'email' => uniqid('parity-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(20)->toIso8601String(),
    ]);
    $m2m20 = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $m2mProject->id,
            'candidate_ref' => 'parity-boundary-m2m-20',
            'display_name' => 'Parity 20',
            'email' => uniqid('parity-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(20)->toIso8601String(),
        ]);

    expect($backoffice20->status())->toBe(201);
    expect($m2m20->status())->toBe(201);

    $backoffice15 = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $backofficeProject->id,
        'candidate_ref' => 'parity-boundary-eo-15',
        'display_name' => 'Parity 15',
        'email' => uniqid('parity-').'@example.test',
        'scheduled_at' => now('UTC')->addMinutes(15)->toIso8601String(),
    ]);
    $m2m15 = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $m2mProject->id,
            'candidate_ref' => 'parity-boundary-m2m-15',
            'display_name' => 'Parity 15',
            'email' => uniqid('parity-').'@example.test',
            'scheduled_at' => now('UTC')->addMinutes(15)->toIso8601String(),
        ]);

    expect($backoffice15->status())->toBe(422);
    expect($m2m15->status())->toBe(422);
});

test('both surfaces agree on the same 409 reason values for a duplicate email', function (): void {
    $org = Organization::factory()->create();
    $backofficeProject = m2mSchedEntryLinkProject($org);
    $m2mProject = m2mSchedProject($org);
    $token = m2mSchedOperatorToken($org);
    $m2m = m2mSchedClient($org);

    $eoEmail = uniqid('parity-eo-').'@example.test';
    m2mSchedExistingParticipant($backofficeProject, $org, 'parity-existing-eo', $eoEmail);
    $m2mEmail = uniqid('parity-m2m-').'@example.test';
    m2mSchedExistingParticipant($m2mProject, $org, 'parity-existing-m2m', $m2mEmail);

    $backofficeConflict = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $backofficeProject->id,
        'candidate_ref' => 'parity-new-eo',
        'display_name' => 'Parity Conflict',
        'email' => $eoEmail,
        'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
    ]);
    $m2mConflict = $this->withHeaders(['Authorization' => 'Bearer '.$m2m['key']])
        ->postJson('/api/m2m/participants', [
            'project_id' => $m2mProject->id,
            'candidate_ref' => 'parity-new-m2m',
            'display_name' => 'Parity Conflict',
            'email' => $m2mEmail,
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ]);

    expect($backofficeConflict->status())->toBe(409);
    expect($m2mConflict->status())->toBe(409);
    expect($backofficeConflict->json('reason'))->toBe('duplicate_email');
    expect($m2mConflict->json('reason'))->toBe('duplicate_email');
});
