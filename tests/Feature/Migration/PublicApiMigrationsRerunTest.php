<?php

declare(strict_types=1);

/**
 * gga round 4 finding 3: both `public_id`-adding migrations disable the
 * wrapping transaction (`$withinTransaction = false`), which is exactly
 * what makes a partial-failure rerun possible in the first place — but that
 * only helps if every schema-changing statement inside `up()` is ALSO
 * individually guarded, or the second run fails on the first `ADD COLUMN`/
 * `ADD CONSTRAINT` of a step the first run already completed. Proven here
 * by calling the SAME anonymous migration class's `up()` a second time,
 * directly, right after `RefreshDatabase` already ran every migration once.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the step 4 public_id migration (organizations/projects) is idempotent on rerun', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_130000_add_public_id_to_organizations_and_projects_tables.php');

    $migration->up();

    expect(Schema::hasColumn('organizations', 'public_id'))->toBeTrue();
    expect(Schema::hasColumn('projects', 'public_id'))->toBeTrue();
    expect(Schema::hasIndex('organizations', 'organizations_public_id_unique'))->toBeTrue();
    expect(Schema::hasIndex('projects', 'projects_public_id_unique'))->toBeTrue();
});

test('the step 5 participants public-api-fields migration is idempotent on rerun', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_140000_add_public_api_fields_to_participants_table.php');

    $migration->up();

    expect(Schema::hasColumn('participants', 'public_id'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'metadata'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'exit_redirect_url'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'mode'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'session_token_jti'))->toBeTrue();
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();

    $constraintExists = DB::select(
        "SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'participants_mode_check'"
    );
    expect($constraintExists)->not->toBeEmpty();
});
