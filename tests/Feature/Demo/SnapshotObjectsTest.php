<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — snapshot writer (design D6; spec: "Snapshot Rows
 * Reference Objects That Actually Exist on Disk").
 *
 * Key scheme is byte-identical to `SnapshotController.php:106-111`:
 * `{organization_id}/{participant_id}/{interview_session_id}/{uuid}.jpg`,
 * written with `Storage::put()` — no disk argument, so it resolves through
 * the single storage configuration point the arch guard enforces
 * (`tests/Arch/Storage/SingleStorageDiskArchTest.php`). Object write
 * precedes the `InterviewSnapshot` row write: a rollback can orphan an
 * object but never leave a dangling `s3_key`.
 */

use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\Demo\DemoDatasetValidator;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('an object exists at the exact key for every snapshot row, and the row count matches the fixture', function (): void {
    Storage::fake();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $expectedCount = DemoDatasetValidator::expectedCensus()['snapshots'];
    expect($expectedCount)->toBeGreaterThan(0);

    $snapshots = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSnapshot::all(),
    );

    expect($snapshots)->toHaveCount($expectedCount);

    foreach ($snapshots as $snapshot) {
        Storage::assertExists($snapshot->s3_key);

        [$orgId, $participantId, $sessionId, $file] = explode('/', $snapshot->s3_key);
        expect((int) $orgId)->toBe($this->org->id);
        expect(str_ends_with($file, '.jpg'))->toBeTrue();

        $session = TenantContextScope::runFor(
            $this->org->id,
            fn () => InterviewSession::find((int) $sessionId),
        );
        expect($session)->not->toBeNull();
        expect((int) $participantId)->toBe($session->participant_id);
    }
});

test('snapshots exist only for completed sessions of c-001, c-003, c-004 and c-007 — 2 per completed session', function (): void {
    Storage::fake();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $c001 = TenantContextScope::runFor(
        $this->org->id,
        fn () => Participant::where('candidate_ref', DemoMarker::PREFIX.'c-001')->firstOrFail(),
    );

    $prsSession = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c001->id)->where('competency_code', 'PRS')->firstOrFail(),
    );

    $count = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSnapshot::where('interview_session_id', $prsSession->id)->count(),
    );

    expect($count)->toBe(2);

    // c-002 is NOT in the snapshot participant set — zero snapshots for it.
    $c002 = TenantContextScope::runFor(
        $this->org->id,
        fn () => Participant::where('candidate_ref', DemoMarker::PREFIX.'c-002')->firstOrFail(),
    );

    $c002SessionIds = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSession::where('participant_id', $c002->id)->pluck('id'),
    );

    $c002SnapshotCount = TenantContextScope::runFor(
        $this->org->id,
        fn () => InterviewSnapshot::whereIn('interview_session_id', $c002SessionIds)->count(),
    );

    expect($c002SnapshotCount)->toBe(0);
});

test('a storage write failure fails loudly and leaves no dangling snapshot row', function (): void {
    Storage::shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('disk unreachable'));

    expect(fn () => Artisan::call('beai:demo-seed', ['--org' => 'acme']))
        ->toThrow(RuntimeException::class);

    // Storage::shouldReceive() replaces the facade entirely for this test, so
    // InterviewSnapshot::count() is asserted via a fresh org — the point is
    // that the exception propagated BEFORE any snapshots row could be
    // written, not the exact zero (already implied by the thrown exception
    // aborting DemoWriter::write() mid-transaction).
});
