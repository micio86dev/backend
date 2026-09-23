<?php

declare(strict_types=1);

/**
 * RED — PR-A/T-A1: `scheduled_at` + `scheduling_status` on `participants`
 * (interview-scheduling, design AD-1).
 *
 * Verifies the structural invariants added by
 * `2026_09_23_000001_add_scheduling_to_participants_table.php`:
 * - both columns exist and are nullable
 * - the composite index (scheduling_status, scheduled_at) exists
 * - the CHECK constraint makes "status with no time" / "time with no status"
 *   unrepresentable at the DB level
 * - a never-scheduled participant (existing or freshly created) has both
 *   columns null — "none" is represented by null, not by a case of the enum
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array{0: Organization, 1: Project}
 */
function schedulingMigrationFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id]);

    return [$org, $project];
}

test('participants table has scheduled_at and scheduling_status columns', function (): void {
    expect(Schema::hasColumn('participants', 'scheduled_at'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'scheduling_status'))->toBeTrue();
});

test('scheduled_at and scheduling_status are both nullable', function (): void {
    $columns = Schema::getConnection()->select("
        SELECT column_name, is_nullable
        FROM information_schema.columns
        WHERE table_name = 'participants'
          AND column_name IN ('scheduled_at', 'scheduling_status')
    ");

    expect($columns)->toHaveCount(2);

    foreach ($columns as $column) {
        expect($column->is_nullable)->toBe('YES', "{$column->column_name} should be nullable");
    }
});

test('composite index (scheduling_status, scheduled_at) exists', function (): void {
    $indexes = Schema::getConnection()->select("
        SELECT indexname, indexdef
        FROM pg_indexes
        WHERE tablename = 'participants'
          AND indexdef LIKE '%scheduling_status%'
          AND indexdef LIKE '%scheduled_at%'
    ");

    expect($indexes)->not->toBeEmpty('Composite index (scheduling_status, scheduled_at) should exist');
});

test('an existing/new unscheduled participant has both columns null (no "none" case)', function (): void {
    [, $project] = schedulingMigrationFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    expect($participant->fresh()->scheduled_at)->toBeNull();
    expect($participant->fresh()->scheduling_status)->toBeNull();
});

test('a legal scheduled row insert succeeds', function (): void {
    [, $project] = schedulingMigrationFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    DB::table('participants')->where('id', $participant->id)->update([
        'scheduled_at' => now()->addDay(),
        'scheduling_status' => 'pending',
    ]);

    $row = DB::table('participants')->where('id', $participant->id)->first();

    expect($row->scheduling_status)->toBe('pending');
    expect($row->scheduled_at)->not->toBeNull();
});

test('CHECK constraint rejects scheduling_status set with scheduled_at null', function (): void {
    [, $project] = schedulingMigrationFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    assertPostgresConstraintViolation(
        fn () => DB::table('participants')->where('id', $participant->id)->update([
            'scheduled_at' => null,
            'scheduling_status' => 'pending',
        ]),
        '23514',
        'participants_scheduling_status_pair_check',
    );
});

test('CHECK constraint rejects scheduled_at set with scheduling_status null', function (): void {
    [, $project] = schedulingMigrationFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    assertPostgresConstraintViolation(
        fn () => DB::table('participants')->where('id', $participant->id)->update([
            'scheduled_at' => now()->addDay(),
            'scheduling_status' => null,
        ]),
        '23514',
        'participants_scheduling_status_pair_check',
    );
});
