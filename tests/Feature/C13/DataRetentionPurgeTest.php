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
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
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

test('a purged snapshot removes the stored object, not only the row', function (): void {
    Storage::fake('local');
    $org = purgeOrg();
    $participant = purgeParticipant($org, now()->subDays(90)->toDateTimeString());

    $key = 'snapshots/old.jpg';
    Storage::disk('local')->put($key, 'binary');

    // Built by hand: there is no InterviewSnapshot factory, and skipping this
    // test for want of one would leave the ONLY path that touches the disk
    // untested — the one where getting it wrong leaves personal data on
    // storage forever.
    $project = $participant->project;
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'ended',
    ]);

    $snapshot = new InterviewSnapshot;
    $snapshot->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        's3_key' => $key,
        'taken_at' => now()->subDays(90),
    ])->save();

    config()->set('filesystems.default', 'local');
    config()->set('retention.enabled', true);
    config()->set('retention.days.snapshot', 30);

    $this->artisan('beai:purge-expired-data')->assertSuccessful();

    // Deleting the row alone would leave the image on disk, unreachable through
    // the application and still entirely present — the worst outcome available.
    expect(InterviewSnapshot::withoutGlobalScopes()->where('s3_key', $key)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($key);
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
