<?php

declare(strict_types=1);

use App\Models\AvatarTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function hraConfigIdMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_19_100000_add_hr_avatar_config_id_to_avatar_templates.php');

    return $migration;
}

test('avatar_templates.hr_avatar_config_id is a nullable varchar(64) with no default', function (): void {
    $column = DB::selectOne(
        "SELECT is_nullable, column_default, data_type, character_maximum_length FROM information_schema.columns
         WHERE table_name = 'avatar_templates' AND column_name = 'hr_avatar_config_id'"
    );

    expect($column)->not->toBeNull();
    expect($column->is_nullable)->toBe('YES');
    expect($column->column_default)->toBeNull();
    expect($column->data_type)->toBe('character varying');
    expect($column->character_maximum_length)->toBe(64);
});

test('the column points into another service, so it has no foreign key and no index', function (): void {
    expect(Schema::hasColumn('avatar_templates', 'hr_avatar_config_id'))->toBeTrue();

    $foreignKeys = DB::select(
        "SELECT 1 FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu USING (constraint_name, table_schema)
         WHERE tc.table_name = 'avatar_templates' AND tc.constraint_type = 'FOREIGN KEY' AND kcu.column_name = 'hr_avatar_config_id'"
    );
    $indexes = DB::select(
        "SELECT 1 FROM pg_indexes WHERE tablename = 'avatar_templates' AND indexdef LIKE '%hr_avatar_config_id%'"
    );

    expect($foreignKeys)->toBe([]);
    expect($indexes)->toBe([]);
});

test('the model accepts the config id through mass assignment and defaults it to null', function (): void {
    $org = casOrg();

    $withId = casInTenant($org, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Linked', 'provider' => 'tavus', 'config' => [], 'hr_avatar_config_id' => 'avc_01HXYZ',
    ]));
    $without = casInTenant($org, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Unlinked', 'provider' => 'tavus', 'config' => [],
    ]));

    expect(DB::table('avatar_templates')->where('id', $withId->id)->value('hr_avatar_config_id'))->toBe('avc_01HXYZ');
    expect(DB::table('avatar_templates')->where('id', $without->id)->value('hr_avatar_config_id'))->toBeNull();
});

test('down() drops the column and up() restores it', function (): void {
    $migration = hraConfigIdMigration();

    $migration->down();
    expect(Schema::hasColumn('avatar_templates', 'hr_avatar_config_id'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('avatar_templates', 'hr_avatar_config_id'))->toBeTrue();
});
