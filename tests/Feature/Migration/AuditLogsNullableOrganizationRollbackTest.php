<?php

declare(strict_types=1);

/**
 * RED/GREEN — Z28 (R1-001/R4-audit-rollback-deletes-platform-rows,
 * framework-catalogue-authoring): `down()` on
 * `2026_09_16_120000_make_audit_logs_organization_nullable` used to DELETE
 * every platform (NULL-organization_id) `audit_logs` row so the restored
 * NOT NULL constraint could apply. `audit_logs` is append-only (see that
 * table's own creation migration docblock) — a rollback silently destroying
 * rows it exists to make irreversible is exactly the failure mode an audit
 * trail cannot have. `down()` now refuses outright, deleting nothing, when a
 * platform row exists.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const AUDIT_LOGS_NULLABLE_ROLLBACK_MIGRATION_BOUNDARY = '2026_09_16_120000_make_audit_logs_organization_nullable';

/**
 * Computed, not hard-coded (matches `BaselineRevisionRollbackTest`'s own
 * rationale) — the number of migrations at or after the boundary, whatever
 * that happens to be today.
 */
function auditLogsNullableRollbackStepsToRollBack(): int
{
    return DB::table('migrations')
        ->where('migration', '>=', AUDIT_LOGS_NULLABLE_ROLLBACK_MIGRATION_BOUNDARY)
        ->count();
}

test('down() refuses to roll back when a platform audit row exists, deleting nothing', function (): void {
    // public_id (public-api step 4, G-05) is NOT NULL on organizations — a
    // raw insert supplies a bare ULID directly, bypassing
    // App\Models\Concerns\HasPublicId (this is a plain DB::table() write,
    // not an Eloquent one).
    $orgId = DB::table('organizations')->insertGetId([
        'name' => 'Audit Rollback Org',
        'slug' => 'audit-rollback-org-'.uniqid('', true),
        'public_id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A platform (NULL-org) row — the exact shape this migration's own
    // docblock says exists for a superadmin editing the shared catalogue.
    DB::table('audit_logs')->insert([
        'organization_id' => null,
        'action' => 'catalogue.role.created',
        'subject_type' => 'Role',
        'subject_id' => 1,
        'created_at' => now(),
    ]);

    DB::table('audit_logs')->insert([
        'organization_id' => $orgId,
        'action' => 'catalogue.role.created',
        'subject_type' => 'Role',
        'subject_id' => 2,
        'created_at' => now(),
    ]);

    $countBefore = DB::table('audit_logs')->count();
    expect($countBefore)->toBe(2);

    $steps = auditLogsNullableRollbackStepsToRollBack();

    expect(fn () => Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]))
        ->toThrow(RuntimeException::class);

    // Nothing deleted — both rows, platform and tenant, survive untouched.
    expect(DB::table('audit_logs')->count())->toBe($countBefore);
    expect(DB::table('audit_logs')->whereNull('organization_id')->count())->toBe(1);

    // Restore full schema — some later migrations rolled back successfully
    // before this one refused; re-migrate so the rest of the suite sees the
    // complete, current schema (same restoration `BaselineRevisionRollbackTest`
    // performs after its own successful-rollback assertion).
    Artisan::call('migrate', ['--force' => true]);
});

test('down() still rolls back cleanly when no platform row exists', function (): void {
    $orgId = DB::table('organizations')->insertGetId([
        'name' => 'Audit Rollback Org Clean',
        'slug' => 'audit-rollback-org-clean-'.uniqid('', true),
        'public_id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('audit_logs')->insert([
        'organization_id' => $orgId,
        'action' => 'catalogue.role.created',
        'subject_type' => 'Role',
        'subject_id' => 1,
        'created_at' => now(),
    ]);

    $steps = auditLogsNullableRollbackStepsToRollBack();

    Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);

    // The tenant row survived the rollback — down() never touches non-platform rows.
    expect(DB::table('audit_logs')->count())->toBe(1);

    Artisan::call('migrate', ['--force' => true]);

    // organization_id is nullable again after re-migrating up.
    DB::table('audit_logs')->insert([
        'organization_id' => null,
        'action' => 'catalogue.role.created',
        'subject_type' => 'Role',
        'subject_id' => 2,
        'created_at' => now(),
    ]);
    expect(DB::table('audit_logs')->whereNull('organization_id')->exists())->toBeTrue();
});
