<?php

declare(strict_types=1);

/**
 * `notification_logs` has no FK to its polymorphic subject (design D12) —
 * teardown MUST delete it FIRST, while the demo participants/webhook
 * deliveries it references still exist and can be identified by their own
 * markers. Deleting subjects first would make the corresponding
 * `notification_logs` rows PERMANENTLY unidentifiable orphans — the failure
 * this ordering exists to prevent (non-negotiable #6 of this change).
 *
 * `NotificationLog` extends `TenantModel`: every query MUST run inside
 * `TenantContextScope::runFor()` or the global tenant scope adds
 * `organization_id = NULL` (no ambient resolver set outside that wrapper),
 * which matches nothing rather than throwing — a silent false negative, not
 * a loud failure.
 */

use App\Models\NotificationLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('teardown removes every demo notification_logs row, leaving zero orphans referencing a demo participant or demo webhook delivery', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    expect(TenantContextScope::runFor($this->org->id, fn () => NotificationLog::count()))->toBe(3);

    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])->assertExitCode(0);

    expect(TenantContextScope::runFor($this->org->id, fn () => NotificationLog::count()))->toBe(0);
});

test('ordering is load-bearing: a subject deleted BEFORE teardown runs leaves its notification_logs row an unidentifiable orphan', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    // Simulate an OLDER teardown that predates this change (rollback plan:
    // "run teardown before reverting" — this is exactly the residue an
    // out-of-order delete leaves behind). c-005 is the subject of the
    // scoring_failed notification_logs row.
    $c005 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-005')
        ->firstOrFail();

    $orphanedLogId = TenantContextScope::runFor($this->org->id, fn () => NotificationLog::where('notification_type', 'scoring_failed')
        ->where('subject_type', 'participant')
        ->where('subject_id', $c005->id)
        ->value('id'));

    expect($orphanedLogId)->not->toBeNull();

    $c005->delete();

    // The CURRENT (correctly-ordered) teardown collects demo
    // participant/delivery ids BEFORE deleting anything, so it can still
    // resolve every OTHER demo notification_logs row. But c-005 is already
    // gone — no marker survives to identify its row as demo data, so
    // teardown cannot safely delete it either. The row becomes exactly the
    // permanent orphan the ordering rule exists to avoid — proving the
    // ordering is load-bearing, not incidental: a teardown that ran the
    // steps in the other order would have produced this same residue for
    // EVERY subject, not just a manually-pre-deleted one.
    $this->artisan('beai:demo-teardown', ['--org' => 'acme'])->assertExitCode(0);

    expect(TenantContextScope::runFor($this->org->id, fn () => NotificationLog::find($orphanedLogId)))->not->toBeNull();

    // Every OTHER demo notification_logs row was still removed cleanly.
    expect(TenantContextScope::runFor($this->org->id, fn () => NotificationLog::count()))->toBe(1);
});
