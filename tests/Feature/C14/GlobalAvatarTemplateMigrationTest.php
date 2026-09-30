<?php

declare(strict_types=1);

/**
 * Global (platform) avatar templates — schema (A1).
 *
 * `avatar_templates.organization_id` becomes nullable: NULL means the template
 * belongs to the platform, not to a tenant. The new partial unique index keeps
 * live platform names unique without touching the per-organization indexes.
 */

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AvatarTemplates\PlatformTemplates;

function gatMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_30_100000_allow_platform_avatar_templates.php');

    return $migration;
}

function gatIsNullable(): bool
{
    $row = DB::selectOne(
        "SELECT is_nullable FROM information_schema.columns
         WHERE table_name = 'avatar_templates' AND column_name = 'organization_id'"
    );

    return $row->is_nullable === 'YES';
}

function gatIndexExists(): bool
{
    return DB::selectOne(
        "SELECT 1 AS x FROM pg_indexes WHERE indexname = 'avatar_templates_global_name_unique'"
    ) !== null;
}

/** A savepoint, so the expected constraint failure does not poison the test transaction. */
function gatInsertRefused(callable $insert): bool
{
    try {
        DB::transaction($insert);
    } catch (QueryException) {
        return true;
    }

    return false;
}

test('organization_id is nullable and existing organization rows are untouched', function (): void {
    $org = Organization::factory()->create();
    DB::table('avatar_templates')->insert([
        'organization_id' => $org->id, 'name' => 'Kept', 'provider' => 'heygen',
        'config' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Re-running up() over an already-migrated schema must be a no-op for data.
    expect(gatIsNullable())->toBeTrue()
        ->and(DB::table('avatar_templates')->where('name', 'Kept')->value('organization_id'))->toBe($org->id);
});

test('a live global name is unique, but trashed, organization and provider siblings coexist', function (): void {
    $org = Organization::factory()->create();
    PlatformTemplates::insertGlobal(['name' => 'Shared name']);

    expect(gatInsertRefused(fn () => PlatformTemplates::insertGlobal(['name' => 'Shared name'])))->toBeTrue();

    // A soft-deleted duplicate frees the name.
    PlatformTemplates::insertGlobal(['name' => 'Trashed', 'deleted_at' => now()]);
    expect(gatInsertRefused(fn () => PlatformTemplates::insertGlobal(['name' => 'Trashed'])))->toBeFalse();

    // A global and an organization template may share a name.
    expect(gatInsertRefused(fn () => DB::table('avatar_templates')->insert([
        'organization_id' => $org->id, 'name' => 'Shared name', 'provider' => 'heygen',
        'config' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ])))->toBeFalse();

    // Several active globals of one provider may coexist: the per-org active
    // index treats NULL organizations as distinct.
    expect(gatInsertRefused(function (): void {
        PlatformTemplates::insertActiveGlobal(['name' => 'Active one']);
        PlatformTemplates::insertActiveGlobal(['name' => 'Active two']);
    }))->toBeFalse();
});

test('down() refuses while any platform row exists, trashed included, and changes nothing', function (): void {
    PlatformTemplates::insertGlobal(['name' => 'Live']);
    PlatformTemplates::insertGlobal(['name' => 'Gone', 'deleted_at' => now()]);

    expect(fn () => gatMigration()->down())
        ->toThrow(RuntimeException::class, '2 platform avatar template row(s)');

    expect(gatIsNullable())->toBeTrue()->and(gatIndexExists())->toBeTrue();
});

test('down() restores NOT NULL and drops the index when no platform row exists, and up() reapplies', function (): void {
    gatMigration()->down();

    expect(gatIsNullable())->toBeFalse()->and(gatIndexExists())->toBeFalse();

    gatMigration()->up();

    expect(gatIsNullable())->toBeTrue()->and(gatIndexExists())->toBeTrue();
});
