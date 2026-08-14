<?php

declare(strict_types=1);

/**
 * GDPR retention purge (C13).
 *
 * Every duration used here is a FIXTURE. The real ones are null in
 * `config/retention.php` and stay null until open decision #2 has legal
 * sign-off — testing against them would either prove nothing or, worse, bake an
 * unratified number into the suite where it would look agreed.
 *
 * The first test is the most important one in the file: the command must do
 * NOTHING by default. Deletion is the one operation with no undo, so a
 * finished-but-unratified mechanism has exactly one safe state to wait in.
 */

use App\Console\Commands\PurgeExpiredDataCommand;
use App\Models\AuditLog;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

function purgeOrg(): Organization
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

function purgeParticipant(Organization $org, string $createdAt): Participant
{
    $project = Project::factory()->create(['organization_id' => $org->id]);
    $p = Participant::factory()->create(['project_id' => $project->id]);
    $p->forceFill(['created_at' => $createdAt])->save();

    return $p->fresh();
}

/**
 * Fixture for the round-trip test below: a live candidate + interview session,
 * ready to accept a real POST /snapshot. Distinct name prefix (roundTrip*)
 * deliberately avoids colliding with SnapshotControllerTest.php's snapshot*()
 * helpers — Pest test files share one global function namespace.
 *
 * @return array{participant: Participant, session: InterviewSession, token: string}
 */
function roundTripCandidateFixture(Organization $org): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id, 'status' => 'active']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'retention-'.uniqid(),
        'display_name' => 'Retention Round-Trip',
        'status' => 'in_corso',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'in_corso',
    ]);

    return [
        'participant' => $participant,
        'session' => $session,
        'token' => CandidateTokenFactory::mintCandidateToken($participant),
    ];
}

// ─── The default state is the point ──────────────────────────────────────────

test('the purge does NOTHING by default', function (): void {
    $org = purgeOrg();
    $p = purgeParticipant($org, now()->subYears(5)->toDateTimeString());

    // No config touched at all: this is a fresh install.
    $this->artisan('beai:purge-expired-data')
        ->expectsOutputToContain('Retention is DISABLED')
        ->assertSuccessful();

    expect($p->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME);
});

test('an artifact class with no ratified duration is skipped LOUDLY', function (): void {
    config()->set('retention.enabled', true);
    config()->set('retention.days', [
        'snapshot' => null,
        'transcript' => null,
        'webhook_payload' => null,
        'participant_pii' => 30,
    ]);

    // A missing number is an unratified decision — not a licence to keep data
    // forever, and not one to delete it now. Skipping silently would let an
    // operator believe the data was gone.
    $this->artisan('beai:purge-expired-data')
        ->expectsOutputToContain('Skipping [snapshot]')
        ->expectsOutputToContain('Skipping [transcript]')
        ->assertSuccessful();
});

// ─── The window ──────────────────────────────────────────────────────────────

test('artifacts inside the retention window are untouched', function (): void {
    $org = purgeOrg();
    $recent = purgeParticipant($org, now()->subDays(5)->toDateTimeString());

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect($recent->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME);
});

test('personal data past the window is redacted, the audit record kept', function (): void {
    $org = purgeOrg();
    $old = purgeParticipant($org, now()->subDays(90)->toDateTimeString());
    $ref = $old->candidate_ref;

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $fresh = $old->fresh();

    // The name goes; the opaque reference stays. candidate_ref is the calling
    // system's own identifier and carries no personal data — deleting the row
    // would destroy the audit trail without protecting anybody.
    expect($fresh->display_name)->toBe(PurgeExpiredDataCommand::PURGED_NAME);
    expect($fresh->candidate_ref)->toBe($ref);
});

// ─── Snapshots take their stored object with them ────────────────────────────

/**
 * The teeth of this change (object-storage-fix, D3): a single-disk test can
 * never distinguish "both sites read config" from "both sites hardcode the
 * same name". Parametrised over `['local', 's3']` — with `local`, the
 * pre-fix writer (hardcoded `disk('s3')`) writes to `s3` while the purge
 * looks in `local` and finds nothing, so the round-trip fails; with `s3` the
 * pre-fix purge (which already read `config('filesystems.default')`) happens
 * to agree by accident. One RED case is enough to prove the divergence; after
 * the fix both resolve through the same point and both pass.
 *
 * The key under test comes from the WRITER, not an invented fixture: a real
 * `POST /snapshot` call, read back from `interview_snapshots.s3_key`. The
 * previous version of this test invented its own key (`snapshots/old.jpg`)
 * on the disk the purge (not the writer) used, which proved nothing about
 * whether writer and purge ever agree.
 */
test('write→purge round-trip: the object POST /snapshot writes is the exact object the purge deletes', function (string $disk): void {
    // config()->set() MUST precede the unnamed Storage::fake() below — it
    // reads config('filesystems.default') at call-time, so the ordering is
    // what makes the fake fake the disk actually under test (D3).
    config()->set('filesystems.default', $disk);
    Storage::fake();

    $org = purgeOrg();
    $fixture = roundTripCandidateFixture($org);

    $imageBase64 = base64_encode("\xFF\xD8\xFF\xE0".str_repeat("\x00", 100));

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$fixture['token']])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $fixture['session']->id,
            'image_base64' => $imageBase64,
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $snapshot = InterviewSnapshot::where('interview_session_id', $fixture['session']->id)->firstOrFail();
    $key = $snapshot->s3_key;

    // The write actually landed on the configured default disk — no argument,
    // same resolution point the purge below uses.
    Storage::disk()->assertExists($key);

    // Age the snapshot past the retention cutoff, then let the purge run.
    $snapshot->forceFill(['taken_at' => now()->subDays(90)])->save();

    // Two auth-state leaks must be cleared before the artisan call below, both
    // discovered empirically running this test against the fixed code:
    // 1. tests/Pest.php's documented gotcha — resetAuthGuardState() clears the
    //    cached tymon token/user.
    // 2. Laravel's own auth:api-candidate middleware calls Auth::shouldUse()
    //    on successful authentication, which overwrites config('auth.defaults.guard')
    //    for the rest of the PHP process — resetAuthGuardState() does NOT undo
    //    this. Left unset, AuditRecorder::record() (called by the purge command
    //    below) resolves Auth::user() against the now-default 'api-candidate'
    //    guard and gets the Participant back, not a User; its id has no matching
    //    `users` row, and the purge's (unrelated to this test) audit write then
    //    fails its foreign key.
    resetAuthGuardState();
    config()->set('auth.defaults.guard', 'api');

    config()->set('retention.enabled', true);
    config()->set('retention.days.snapshot', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    // Deleting the row alone would leave the image on disk, unreachable through
    // the application and still entirely present — the worst outcome available.
    Storage::disk()->assertMissing($key);
    expect(InterviewSnapshot::withoutGlobalScopes()->where('s3_key', $key)->exists())->toBeFalse();
})->with(['local', 's3']);

// ─── A failed object delete never drops the row ───────────────────────────────

/**
 * Closes a verification gap (CRITICAL 1, post-apply review): the retry-on-
 * delete-failure scenario — "A failed object delete leaves the row intact for
 * retry" (nfr-hardening/data-retention spec.md:83-90) — is implemented
 * correctly at PurgeExpiredDataCommand::purgeSnapshots() (object delete
 * wrapped in try/catch; `continue` on failure skips the row delete), but
 * until now nothing ever forced `Storage::delete()` to fail, so the path had
 * zero runtime proof. Exactly the defect class this whole change exists to
 * eliminate: a data-protection path nobody ever watched fail.
 *
 * Proven RED against a deliberately inverted implementation (see tasks.md for
 * the captured failure) before being trusted: temporarily removing the
 * try/catch's `continue` — i.e. deleting the row unconditionally even when
 * the object delete throws — made this test fail exactly as expected.
 */
class ThrowingOnDeleteDisk extends FilesystemAdapter
{
    /**
     * Wraps an EXISTING fake disk's driver/adapter/config (not a fresh
     * Storage::fake() call, which would wipe the fake root directory and
     * lose the object the test already wrote) so every operation except
     * delete() still hits the real fake filesystem underneath.
     */
    public function __construct(FilesystemAdapter $inner)
    {
        parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
    }

    public function delete($paths)
    {
        throw new RuntimeException('Simulated object-store delete failure (test fixture)');
    }
}

test('a failed object delete leaves the row intact for retry, warns, and a later successful run removes it', function (): void {
    config()->set('filesystems.default', 'local');
    Storage::fake();

    $org = purgeOrg();
    $fixture = roundTripCandidateFixture($org);

    $imageBase64 = base64_encode("\xFF\xD8\xFF\xE0".str_repeat("\x00", 100));

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$fixture['token']])
        ->postJson('/api/candidate/interview/snapshot', [
            'session_id' => $fixture['session']->id,
            'image_base64' => $imageBase64,
        ]);

    $response->assertStatus(202);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $snapshot = InterviewSnapshot::where('interview_session_id', $fixture['session']->id)->firstOrFail();
    $key = $snapshot->s3_key;

    Storage::disk()->assertExists($key);

    $snapshot->forceFill(['taken_at' => now()->subDays(90)])->save();

    // Same two auth-state leaks as the round-trip test above — see its
    // comment for why both are required before an artisan call in the same
    // test that already authenticated a candidate over HTTP.
    resetAuthGuardState();
    config()->set('auth.defaults.guard', 'api');

    config()->set('retention.enabled', true);
    config()->set('retention.days.snapshot', 30);

    // Keep a handle on the REAL fake disk before wrapping it, so it can be
    // restored for the retry run below without losing the object underneath.
    $realDisk = Storage::disk('local');
    Storage::set('local', new ThrowingOnDeleteDisk($realDisk));

    $this->artisan('beai:purge-expired-data')
        ->expectsOutputToContain("Could not delete object for snapshot {$snapshot->getKey()}")
        ->assertSuccessful();

    // The row is the ONLY pointer that makes the object findable again — it
    // must survive exactly because the object delete failed.
    expect(InterviewSnapshot::withoutGlobalScopes()->where('s3_key', $key)->exists())->toBeTrue();
    $realDisk->assertExists($key);

    // Restore the real (non-throwing) disk and retry — the next run must
    // pick this snapshot back up and finish the job.
    Storage::set('local', $realDisk);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    Storage::disk()->assertMissing($key);
    expect(InterviewSnapshot::withoutGlobalScopes()->where('s3_key', $key)->exists())->toBeFalse();
});

// ─── Auditability ────────────────────────────────────────────────────────────

test('a purge leaves a trail that does not contain what it purged', function (): void {
    $org = purgeOrg();
    purgeParticipant($org, now()->subDays(90)->toDateTimeString());

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $row = AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->first();

    expect($row)->not->toBeNull();
    expect($row->subject_type)->toBe('participant_pii');
    expect($row->after['count'])->toBeGreaterThan(0);

    // Recording WHAT was deleted, in a table designed to be kept, would defeat
    // the deletion. The trail records that a purge happened and its scope.
    expect(json_encode($row->after))->not->toContain('@');
});

// ─── Idempotence ─────────────────────────────────────────────────────────────

test('a second run purges nothing further', function (): void {
    $org = purgeOrg();
    purgeParticipant($org, now()->subDays(90)->toDateTimeString());

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();
    $afterFirst = AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count();

    $this->artisan('beai:purge-expired-data')->assertSuccessful();
    $afterSecond = AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count();

    // Every query filters on the thing the purge removes, so the second run
    // finds nothing and writes no further audit row.
    expect($afterSecond)->toBe($afterFirst);
});

// ─── Dry run ─────────────────────────────────────────────────────────────────

test('a dry run reports without deleting', function (): void {
    $org = purgeOrg();
    $old = purgeParticipant($org, now()->subDays(90)->toDateTimeString());

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data', ['--dry-run' => true])
        ->expectsOutputToContain('Would purge')
        ->assertSuccessful();

    expect($old->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME);
});

// ─── The policy in isolation ─────────────────────────────────────────────────

test('the policy returns no cutoff while retention is disabled', function (): void {
    config()->set('retention.enabled', false);
    config()->set('retention.days.snapshot', 30);

    // Even a ratified duration must not produce a cutoff while the master
    // switch is off — otherwise "disabled" would depend on every class also
    // being unset, which is two ways to be safe instead of one.
    expect((new RetentionPolicy)->cutoffFor('snapshot'))->toBeNull();
});

test('a zero or negative duration is treated as unratified, not as delete-now', function (): void {
    config()->set('retention.enabled', true);
    config()->set('retention.days.snapshot', 0);

    // A misconfigured 0 must never mean "delete everything immediately".
    expect((new RetentionPolicy)->cutoffFor('snapshot'))->toBeNull();
});

test('cross-tenant: a purge scoped to one org does not reach another', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $b = TenantContextScope::runFor($orgB->id, function () use ($orgB): Participant {
        $project = Project::factory()->create(['organization_id' => $orgB->id]);
        $p = Participant::factory()->create(['project_id' => $project->id]);
        $p->forceFill(['created_at' => now()->subDays(1)])->save();

        return $p->fresh();
    });

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    // orgB's participant is inside the window and must survive regardless of
    // what happened elsewhere.
    expect($b->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME);
});
