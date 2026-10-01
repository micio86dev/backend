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
use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\ReusableLinkFixtures as Fx;

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
        'email' => uniqid('cand-').'@example.test',
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

    expect($p->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME)
        ->and($p->fresh()->email)->toBe($p->email);
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

    expect($recent->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME)
        ->and($recent->fresh()->email)->toBe($recent->email);
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
    // The address goes with the name, in the same pass: a placeholder derived
    // from the participant's own reference and from nothing else.
    expect($fresh->email)->toBe(PlaceholderEmail::forPurged($ref))
        ->and($fresh->email)->not->toBe($old->email);
});

// ─── Transcripts ─────────────────────────────────────────────────────────────

test('the transcript class deletes utterances past the window and keeps recent ones', function (): void {
    // `utterances` has no `created_at` (`$timestamps = false`; `ts` is the only
    // timestamp), and this class filtered on it: enabling `transcript` threw
    // "column created_at does not exist". It was found only because the class
    // had no test, the same way the snapshot class's identical mistake was.
    $org = purgeOrg();
    $fixture = roundTripCandidateFixture($org);

    $make = fn (string $text, int $daysAgo): Utterance => Utterance::forceCreate([
        'interview_session_id' => $fixture['session']->id,
        'organization_id' => $org->id,
        'speaker' => 'candidate',
        'text' => $text,
        'ts' => now()->subDays($daysAgo),
    ]);
    $old = $make('an old answer', 90);
    $recent = $make('a recent answer', 5);

    config()->set('retention.enabled', true);
    config()->set('retention.days.transcript', 30);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [transcript]: 1')->assertSuccessful();

    expect(Utterance::withoutGlobalScopes()->whereKey($old->id)->exists())->toBeFalse();
    expect(Utterance::withoutGlobalScopes()->whereKey($recent->id)->exists())->toBeTrue();
});

// ─── The external reference is retained (candidate-external-reference) ───────

/**
 * `external_id` and `source` are the calling system's own record id and name.
 * They belong to NO artifact class: every class leaves both columns exactly as
 * they are, treated like `candidate_ref`. A DOCUMENTED DEFAULT pending the
 * ruling-2 legal sign-off, not a legal conclusion — so these tests pin the
 * current behaviour, and a legal decision to purge them is a new class, which
 * would turn one of them red on purpose.
 */
test('the participant_pii purge redacts the name and the email and leaves the external reference untouched', function (): void {
    $org = purgeOrg();
    $old = purgeParticipant($org, now()->subDays(90)->toDateTimeString());
    $old->forceFill(['external_id' => 4471, 'source' => 'acme-ats'])->save();

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $fresh = $old->fresh();

    expect($fresh->display_name)->toBe(PurgeExpiredDataCommand::PURGED_NAME);
    expect($fresh->candidate_ref)->toBe($old->candidate_ref);
    // Verbatim: not the sentinel, not nulled.
    expect($fresh->external_id)->toBe(4471);
    expect($fresh->source)->toBe('acme-ats');
    expect($fresh->email)->toBe(PlaceholderEmail::forPurged($old->candidate_ref));
});

/**
 * reusable-interview-links (B3b.7): a visitor created by a reusable link is an
 * ordinary participant to the purge. Its `display_name` and `email` are the ones
 * the visitor typed, and the transcript or recording of a visitor is personal
 * data in fact, so the existing classes apply to it unchanged (the ruling-2
 * sign-off is to name visitors as a class it covers). The reference and the
 * origin marker are not part of any class: like `candidate_ref`, they identify
 * nobody, and the marker is what keeps the row recognisable afterwards.
 */
test('a reusable link visitor past the participant_pii window has its name and email redacted and keeps its reference and origin marker', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable(linkAttributes: ['label' => 'Milan fair stand']);
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ada.lovelace@example.com', 'Ada Lovelace')))->assertOk();
    $visitor = Fx::visitorsOf($link)[0];
    DB::table('participants')->where('id', $visitor->id)->update(['created_at' => now()->subDays(90)]);

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    expect($visitor->display_name)->toBe('Ada Lovelace');

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    // Read raw: a model cast or accessor could hide a coerced value.
    $row = DB::table('participants')->where('id', $visitor->id)->first();

    expect($row->display_name)->toBe(PurgeExpiredDataCommand::PURGED_NAME)
        ->and($row->candidate_ref)->toBe($visitor->candidate_ref)
        ->and($row->candidate_ref)->toStartWith('rlv_')
        ->and($row->email)->toBe(PlaceholderEmail::forPurged($visitor->candidate_ref))
        ->and($row->reusable_interview_link_id)->toBe($link->id);
});

test('NULL is not coerced: a participant without a reference keeps both columns NULL after the purge', function (): void {
    $org = purgeOrg();
    $old = purgeParticipant($org, now()->subDays(90)->toDateTimeString());

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    // Read raw: a model cast could hide a coerced value.
    $row = DB::table('participants')->where('id', $old->id)->first();

    expect($row->display_name)->toBe(PurgeExpiredDataCommand::PURGED_NAME);
    expect($row->external_id)->toBeNull();
    expect($row->source)->toBeNull();
});

test('no other artifact class touches the external reference', function (): void {
    $org = purgeOrg();
    $fixture = roundTripCandidateFixture($org);
    $participant = $fixture['participant'];
    $participant->forceFill(['external_id' => 4471, 'source' => 'acme-ats'])->save();

    $utterance = Utterance::forceCreate([
        'interview_session_id' => $fixture['session']->id,
        'organization_id' => $org->id,
        'speaker' => 'candidate',
        'text' => 'I led the migration',
        'ts' => now()->subDays(90),
    ]);

    $delivery = TenantContextScope::runFor($org->id, function () use ($participant): WebhookDelivery {
        $delivery = WebhookDelivery::factory()->forParticipant($participant)->create();
        $delivery->forceFill(['created_at' => now()->subDays(90)])->save();

        return $delivery;
    });

    // Every class EXCEPT participant_pii: their effects are asserted below so
    // the test cannot pass vacuously, and the participant row must not move.
    config()->set('retention.enabled', true);
    config()->set('retention.days', [
        'snapshot' => 30,
        'transcript' => 30,
        'webhook_payload' => 30,
        'participant_pii' => null,
    ]);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect(Utterance::withoutGlobalScopes()->whereKey($utterance->id)->exists())->toBeFalse();
    expect(DB::table('webhook_deliveries')->where('id', $delivery->id)->value('payload'))->toContain('purged');

    $row = DB::table('participants')->where('id', $participant->id)->first();
    expect($row->external_id)->toBe(4471);
    expect($row->source)->toBe('acme-ats');
    expect($row->display_name)->toBe($participant->display_name);
    expect($row->email)->toBe($participant->email);
});

test('two organizations sharing the same reference are each purged inside their own scope, and a second run changes nothing', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $make = fn (Organization $org): Participant => TenantContextScope::runFor($org->id, function () use ($org): Participant {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $p = Participant::factory()->create(['project_id' => $project->id]);
        $p->forceFill(['created_at' => now()->subDays(90), 'external_id' => 4471, 'source' => 'acme-ats'])->save();

        return $p->fresh();
    });
    $a = $make($orgA);
    $b = $make($orgB);

    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 2')->assertSuccessful();
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    foreach ([$a, $b] as $participant) {
        $row = DB::table('participants')->where('id', $participant->id)->first();

        expect($row->display_name)->toBe(PurgeExpiredDataCommand::PURGED_NAME);
        expect($row->external_id)->toBe(4471);
        expect($row->source)->toBe('acme-ats');
        // Each address is rewritten from its OWN reference, never from another row.
        expect($row->email)->toBe(PlaceholderEmail::forPurged($row->candidate_ref));
    }
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

    expect($old->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME)
        ->and($old->fresh()->email)->toBe($old->email);
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
    expect($b->fresh()->display_name)->not->toBe(PurgeExpiredDataCommand::PURGED_NAME)
        ->and($b->fresh()->email)->toBe($b->email);
});

// ─── The participant email is redacted with the name (reusable-link-visitor-identity) ──

/**
 * A participant of `$project` with the given attributes, created `$daysAgo` days
 * ago, through the model so it carries every default a real one has.
 *
 * @param  array<string, mixed>  $attributes
 */
function purgeEnrol(Project $project, array $attributes = [], int $daysAgo = 90): Participant
{
    return TenantContextScope::runFor($project->organization_id, function () use ($project, $attributes, $daysAgo): Participant {
        $participant = Participant::factory()->forProject($project)->create($attributes);
        DB::table('participants')->where('id', $participant->id)->update(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);

        return $participant->fresh();
    });
}

/**
 * A project in a fresh organisation, with retention switched on for `participant_pii`.
 */
function purgeProject(): Project
{
    $org = purgeOrg();
    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);

    return TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['organization_id' => $org->id]));
}

/**
 * The stored row, raw.
 */
function purgeRow(Participant $participant): object
{
    return DB::table('participants')->where('id', $participant->id)->first();
}

test('a due participant has both fields redacted, and every other column is untouched', function (): void {
    $project = purgeProject();
    $participant = purgeEnrol($project, [
        'display_name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'candidate_ref' => 'ref-1',
        'external_id' => 4471,
        'source' => 'acme-ats',
        'status' => 'completato',
    ]);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $row = purgeRow($participant);
    expect($row->display_name)->toBe('[purged]')
        ->and($row->email)->toBe(PlaceholderEmail::forPurged('ref-1'))
        ->and($row->email)->not->toContain('ada')
        ->and($row->email)->not->toContain('lovelace')
        ->and($row->candidate_ref)->toBe('ref-1')
        ->and($row->external_id)->toBe(4471)
        ->and($row->source)->toBe('acme-ats')
        ->and($row->status)->toBe('completato')
        ->and($row->reusable_interview_link_id)->toBeNull();
});

test('a participant that is not yet due keeps both fields and the row is not written', function (): void {
    $project = purgeProject();
    $recent = purgeEnrol($project, ['display_name' => 'Ada Lovelace', 'email' => 'ada@example.com'], daysAgo: 5);
    $before = purgeRow($recent);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect(purgeRow($recent))->toEqual($before);
});

test('a second run changes nothing, counts zero and writes no second audit row', function (): void {
    $project = purgeProject();
    $participant = purgeEnrol($project, ['email' => 'ada@example.com']);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 1')->assertSuccessful();
    $afterFirst = purgeRow($participant);
    $audits = AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count();

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    expect(purgeRow($participant))->toEqual($afterFirst)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count())->toBe($audits);
});

test('two due participants of one project get distinct placeholders and the unique constraint holds, while a not-yet-due one keeps its address', function (): void {
    $project = purgeProject();
    $first = purgeEnrol($project, ['candidate_ref' => 'ref-1', 'email' => 'one@example.com']);
    $second = purgeEnrol($project, ['candidate_ref' => 'ref-2', 'email' => 'two@example.com']);
    $recent = purgeEnrol($project, ['candidate_ref' => 'ref-3', 'email' => 'three@example.com'], daysAgo: 5);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 2')->assertSuccessful();

    expect(purgeRow($first)->email)->toBe(PlaceholderEmail::forPurged('ref-1'))
        ->and(purgeRow($second)->email)->toBe(PlaceholderEmail::forPurged('ref-2'))
        ->and(purgeRow($first)->email)->not->toBe(purgeRow($second)->email)
        ->and(purgeRow($recent)->email)->toBe('three@example.com')
        ->and(purgeRow($recent)->display_name)->toBe($recent->display_name);
});

test('a 255-character reference still yields a placeholder that fits the column, is its own and is recognised as a placeholder', function (): void {
    $project = purgeProject();
    $ref = str_repeat('r', 255);
    $long = purgeEnrol($project, ['candidate_ref' => $ref, 'email' => 'long@example.com']);
    $other = purgeEnrol($project, ['candidate_ref' => str_repeat('r', 254), 'email' => 'other@example.com']);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect(strlen(purgeRow($long)->email))->toBe(84)
        ->and(purgeRow($long)->email)->toBe(PlaceholderEmail::forPurged($ref))
        ->and(PlaceholderEmail::is(purgeRow($long)->email))->toBeTrue()
        ->and(purgeRow($long)->email)->not->toBe(purgeRow($other)->email);
});

test('a participant whose name was already purged by an earlier version gets its email redacted next, counted once', function (): void {
    $project = purgeProject();
    $participant = purgeEnrol($project, ['display_name' => '[purged]', 'candidate_ref' => 'ref-1', 'email' => 'ada@example.com']);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 1')->assertSuccessful();
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    expect(purgeRow($participant)->email)->toBe(PlaceholderEmail::forPurged('ref-1'))
        ->and(purgeRow($participant)->display_name)->toBe('[purged]');
});

test('a legacy anonymous row keeps the address it already holds as its own placeholder, and its name is still redacted', function (): void {
    $project = purgeProject();
    $legacy = purgeEnrol($project, ['candidate_ref' => 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ0', 'email' => 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ0@invalid.beai.local', 'display_name' => 'Reusable link #1']);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 1')->assertSuccessful();
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    expect(purgeRow($legacy)->display_name)->toBe('[purged]')
        ->and(purgeRow($legacy)->email)->toBe('rlv_01JABCDEFGHJKMNPQRSTVWXYZ0@invalid.beai.local');
});

test('a placeholder that collides with another address of the project is reported by id, skipped, and does not stop the other rows', function (): void {
    $project = purgeProject();
    $colliding = purgeEnrol($project, ['candidate_ref' => 'ref-a', 'email' => 'a@example.com']);
    $other = purgeEnrol($project, ['candidate_ref' => 'ref-b', 'email' => 'b@example.com']);
    // Another participant of the project holds EXACTLY the placeholder ref-a would get.
    purgeEnrol($project, ['candidate_ref' => 'holder', 'email' => PlaceholderEmail::forPurged('ref-a')], daysAgo: 5);

    $this->artisan('beai:purge-expired-data')
        ->expectsOutputToContain("participant {$colliding->id} skipped")
        ->expectsOutputToContain('Purged [participant_pii]: 1')
        ->assertSuccessful();

    expect(purgeRow($other)->email)->toBe(PlaceholderEmail::forPurged('ref-b'))
        ->and(purgeRow($other)->display_name)->toBe('[purged]')
        // Left whole for the next pass: the row is never half-redacted.
        ->and(purgeRow($colliding)->email)->toBe('a@example.com')
        ->and(purgeRow($colliding)->display_name)->toBe($colliding->display_name);
});

test('the warning for a skipped row names the participant id only, never an address', function (): void {
    $project = purgeProject();
    $colliding = purgeEnrol($project, ['candidate_ref' => 'ref-a', 'email' => 'a@example.com']);
    purgeEnrol($project, ['candidate_ref' => 'holder', 'email' => PlaceholderEmail::forPurged('ref-a')], daysAgo: 5);

    $this->artisan('beai:purge-expired-data')
        ->doesntExpectOutputToContain('a@example.com')
        ->doesntExpectOutputToContain('purged.beai.invalid')
        ->expectsOutputToContain("participant {$colliding->id} skipped")
        ->assertSuccessful();
});

test('a disabled retention and an enabled one with no duration for participant_pii touch neither field', function (string $case): void {
    $org = purgeOrg();
    $project = TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['organization_id' => $org->id]));
    $participant = purgeEnrol($project, ['email' => 'ada@example.com']);

    if ($case === 'disabled') {
        config()->set('retention.enabled', false);
    } else {
        config()->set('retention.enabled', true);
        config()->set('retention.days.participant_pii', null);
    }

    $before = purgeRow($participant);
    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    expect(purgeRow($participant))->toEqual($before);
})->with(['disabled', 'enabled without a duration']);

test('a dry run counts the backfill case, is capped to the batch size and writes nothing', function (): void {
    $project = purgeProject();
    $backfill = purgeEnrol($project, ['display_name' => '[purged]', 'candidate_ref' => 'ref-1', 'email' => 'ada@example.com']);
    $ordinary = purgeEnrol($project, ['candidate_ref' => 'ref-2', 'email' => 'bob@example.com']);
    $third = purgeEnrol($project, ['candidate_ref' => 'ref-3', 'email' => 'cy@example.com']);
    $before = [purgeRow($backfill), purgeRow($ordinary), purgeRow($third)];

    $this->artisan('beai:purge-expired-data', ['--dry-run' => true])->expectsOutputToContain('Would purge [participant_pii]: 3')->assertSuccessful();

    // A real run is capped to the batch, so the dry run reports the cap, not the total.
    config()->set('retention.batch_size', 2);
    $this->artisan('beai:purge-expired-data', ['--dry-run' => true])->expectsOutputToContain('Would purge [participant_pii]: 2')->assertSuccessful();

    expect([purgeRow($backfill), purgeRow($ordinary), purgeRow($third)])->toEqual($before)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count())->toBe(0);
});

test('the audit row carries the class, the count and the cutoff and neither a name nor an address', function (): void {
    $project = purgeProject();
    purgeEnrol($project, ['display_name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->where('subject_type', 'participant_pii')->firstOrFail();
    $encoded = (string) json_encode($audit->after);

    expect(array_keys($audit->after))->toBe(['count', 'cutoff'])
        ->and($audit->after['count'])->toBe(1)
        ->and($encoded)->not->toContain('@')
        ->and($encoded)->not->toContain('Ada')
        ->and($encoded)->not->toContain('purged.beai.invalid');
});

test('two organisations with the same reference are each redacted from their own reference, and a second run changes nothing', function (): void {
    config()->set('retention.enabled', true);
    config()->set('retention.days.participant_pii', 30);
    $rows = [];

    foreach ([Organization::factory()->create(), Organization::factory()->create()] as $org) {
        $project = TenantContextScope::runFor($org->id, fn (): Project => Project::factory()->create(['organization_id' => $org->id]));
        $rows[] = purgeEnrol($project, ['candidate_ref' => 'shared-ref', 'email' => 'person-'.$org->id.'@example.com']);
    }

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 2')->assertSuccessful();
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    foreach ($rows as $participant) {
        expect(purgeRow($participant)->email)->toBe(PlaceholderEmail::forPurged('shared-ref'))
            ->and(purgeRow($participant)->display_name)->toBe('[purged]');
    }
});

test('the former address no longer resolves anyone through the public API, and the same address can be enrolled again in the same project', function (): void {
    $project = purgeProject();
    purgeEnrol($project, ['candidate_ref' => 'ref-old', 'email' => 'ada@example.com']);
    $key = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($key)->create([
        'organization_id' => $project->organization_id,
        'is_active' => true,
        'abilities' => ['interviews:read'],
    ]);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    $list = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/api/v1/interviews?email=ada@example.com')->assertOk();
    expect($list()->json('data'))->toBe([]);

    // The unique index no longer holds the address: the same person enrols again.
    purgeEnrol($project, ['candidate_ref' => 'ref-new', 'email' => 'ada@example.com'], daysAgo: 1);

    expect(collect($list()->json('data'))->pluck('candidate_ref')->all())->toBe(['ref-new']);
});

test('a database error that is not the email collision is not swallowed: the pass fails loudly', function (): void {
    $project = purgeProject();
    purgeEnrol($project, ['candidate_ref' => 'ref-1', 'email' => 'ada@example.com']);

    // The index named in the message but another SQLSTATE (a foreign key
    // violation): only the (project_id, email) unique violation is skipped.
    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'update "participants"')) {
            $previous = new class('violates participants_project_id_email_unique') extends PDOException
            {
                public function __construct(string $message)
                {
                    parent::__construct($message);
                    $this->code = '23503';
                }
            };

            throw new QueryException('pgsql', $query, [], $previous);
        }
    });

    expect(fn () => Artisan::call('beai:purge-expired-data'))->toThrow(QueryException::class);
});

// ─── Re-entry for a purged reference (design VD-24) ──────────────────────────

/**
 * A single-use sso-link token for `$project`, as the calling system would send it.
 */
function purgeSsoLink(Project $project, string $candidateRef, string $name, string $email): string
{
    return CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $candidateRef,
        'display_name' => $name,
        'email' => $email,
        'project_id' => $project->id,
        'org_id' => $project->organization_id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);
}

test('an exchange of a REAL re-sent identity for an in_attesa purged row is a new collection, and the next pass redacts it again', function (): void {
    $project = purgeProject();
    $project->forceFill(['status' => 'active'])->save();
    makeProjectInterviewable($project);
    $participant = purgeEnrol($project, ['candidate_ref' => 'ref-1', 'display_name' => '[purged]', 'email' => PlaceholderEmail::forPurged('ref-1'), 'status' => 'in_attesa']);

    $this->getJson('/api/sso/exchange?token='.purgeSsoLink($project, 'ref-1', 'Ada Lovelace', 'ada@example.com'))->assertOk();

    // The calling system sent a real identity for the same reference: the
    // exchange writes what the signed token says. The purge left no copy and the
    // exchange never reads the old address, so nothing is "resurrected".
    expect(purgeRow($participant)->display_name)->toBe('Ada Lovelace')
        ->and(purgeRow($participant)->email)->toBe('ada@example.com');

    // `created_at` is unchanged, so the row is still past the window: the next
    // pass redacts it again.
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 1')->assertSuccessful();

    expect(purgeRow($participant)->display_name)->toBe('[purged]')
        ->and(purgeRow($participant)->email)->toBe(PlaceholderEmail::forPurged('ref-1'));
});

test('an exchange for a purged row that is not in_attesa is a 403 and writes nothing', function (string $status): void {
    $project = purgeProject();
    $project->forceFill(['status' => 'active'])->save();
    makeProjectInterviewable($project);
    $participant = purgeEnrol($project, ['candidate_ref' => 'ref-1', 'display_name' => '[purged]', 'email' => PlaceholderEmail::forPurged('ref-1'), 'status' => $status]);
    $before = purgeRow($participant);

    $this->getJson('/api/sso/exchange?token='.purgeSsoLink($project, 'ref-1', 'Ada Lovelace', 'ada@example.com'))->assertForbidden();

    expect(purgeRow($participant))->toEqual($before);
})->with(['completato', 'errore', 'in_corso']);

test('a row deleted between the selection and its update is not counted, and writes no audit row', function (): void {
    $project = purgeProject();
    $vanishing = purgeEnrol($project, ['candidate_ref' => 'ref-1', 'email' => 'ada@example.com']);

    // Another process deletes the participant after the pass selected it and
    // before the pass writes it: the UPDATE then affects zero rows. The count
    // reports rows actually redacted, so nothing is claimed or audited.
    $deleted = false;
    DB::beforeExecuting(function (string $query) use ($vanishing, &$deleted): void {
        if (! $deleted && str_starts_with($query, 'update "participants"')) {
            $deleted = true;
            DB::table('participants')->where('id', $vanishing->id)->delete();
        }
    });

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();

    expect($deleted)->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'data.purged')->count())->toBe(0);
});

test('a legacy placeholder is kept only when it is exactly the row\'s own: a differently cased spelling is not its own and becomes the purged placeholder', function (): void {
    $project = purgeProject();
    $exact = purgeEnrol($project, ['candidate_ref' => 'ref-1', 'email' => 'ref-1@invalid.beai.local']);
    $recased = purgeEnrol($project, ['candidate_ref' => 'ref-2', 'email' => 'REF-2@INVALID.BEAI.LOCAL']);

    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 2')->assertSuccessful();

    // `PlaceholderEmail::isOwn()` compares exactly, so the purge does too: the
    // exact own placeholder is already non-identifying and stays as it is; any
    // other spelling is rewritten to the purged placeholder (non-identifying
    // either way, and never mailed: `is()` recognises both).
    expect(purgeRow($exact)->email)->toBe('ref-1@invalid.beai.local')
        ->and(purgeRow($recased)->email)->toBe(PlaceholderEmail::forPurged('ref-2'));
    $this->artisan('beai:purge-expired-data')->expectsOutputToContain('Purged [participant_pii]: 0')->assertSuccessful();
});
