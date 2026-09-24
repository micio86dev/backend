<?php

declare(strict_types=1);

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('organizations table exists with required columns', function (): void {
    expect(Schema::hasTable('organizations'))->toBeTrue();
    expect(Schema::hasColumn('organizations', 'id'))->toBeTrue();
    expect(Schema::hasColumn('organizations', 'name'))->toBeTrue();
    expect(Schema::hasColumn('organizations', 'slug'))->toBeTrue();
    expect(Schema::hasColumn('organizations', 'created_at'))->toBeTrue();
    expect(Schema::hasColumn('organizations', 'updated_at'))->toBeTrue();
});

it('organizations.slug has a unique index', function (): void {
    expect(Schema::hasTable('organizations'))->toBeTrue();

    // Verify uniqueness by attempting to insert a duplicate slug.
    // `public_id` (public-api step 4, G-05) is NOT NULL — a bare ULID
    // string satisfies both the char(26) length and the unique
    // constraint on that column without this test caring about its
    // actual value.
    $pdo = DB::connection()->getPdo();
    $pdo->exec("INSERT INTO organizations (name, slug, public_id) VALUES ('Org Alpha', 'org-alpha', '01ARZ3NDEKTSV4RRFFQ69G5FAV')");

    $this->expectException(QueryException::class);
    DB::table('organizations')->insert([
        'name' => 'Org Alpha 2',
        'slug' => 'org-alpha',
        'public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
    ]);
})->throws(QueryException::class);

// public-api step 5, Part A follow-up 1: the migration that added this
// column (`2026_09_24_130000_add_public_id_to_organizations_and_projects_
// tables`) ends the column NOT NULL + UNIQUE and backfills every
// pre-existing row — asserted here the same way OrganizationsMigrationTest
// already asserts uniqueness above: at the constraint level, not by
// inspecting the migration's own PHP.
it('organizations.public_id and projects.public_id are NOT NULL and backfilled for every row', function (): void {
    foreach (['organizations', 'projects'] as $table) {
        $column = Schema::getColumns($table);
        $publicIdColumn = collect($column)->firstWhere('name', 'public_id');

        expect($publicIdColumn)->not->toBeNull();
        expect($publicIdColumn['nullable'])->toBeFalse();
    }

    // Rows created through the ORM already get a `public_id` from
    // `HasPublicId`'s `creating` hook — this asserts the COLUMN-level
    // NOT NULL constraint holds, which is what makes a legacy row that
    // predates the trait (the case this migration's own backfill loop
    // exists for) impossible to leave behind un-backfilled: a raw INSERT
    // with no `public_id` is rejected at the database, exactly like the
    // `slug` uniqueness test above proves for its own constraint.
    //
    // Specific to the NOT NULL violation (step 5 review follow-up, item 16)
    // — `PDOException::getCode()` is asserted against `23502`
    // (`not_null_violation`, PostgreSQL's own SQLSTATE for exactly this
    // failure) rather than merely "some PDOException was thrown", which
    // would just as happily pass for an unrelated failure (a dropped
    // connection, a syntax error introduced by a later edit) that has
    // nothing to do with the constraint this test exists to prove.
    $pdo = DB::connection()->getPdo();

    try {
        $pdo->exec("INSERT INTO organizations (name, slug) VALUES ('Org Null Check', 'org-null-check')");
        test()->fail('Expected a NOT NULL violation on organizations.public_id.');
    } catch (PDOException $e) {
        expect($e->getCode())->toBe('23502');
    }
});

// public-api step 5 review follow-up, item 16: the assertion above proves
// the COLUMN-level constraint holds today, but never actually exercises the
// migration's OWN backfill loop — a legacy row is simulated here by
// widening the column back to nullable, landing a row with no `public_id`
// (a raw UPDATE to NULL, since factory-created rows already have one from
// `HasPublicId`'s `creating` hook and neither table's factory can insert
// around it), then re-running the migration's `up()` and proving that
// SPECIFIC row now has one.
it('organizations.public_id and projects.public_id are backfilled by the migration for a row missing one', function (): void {
    foreach (['organizations', 'projects'] as $table) {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->char('public_id', 26)->nullable()->change();
        });
    }

    $org = Organization::factory()->create();

    $project = TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Legacy', 'provider' => 'heygen', 'config' => []]);
        $frameworkVersion = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $frameworkVersion->id,
            'avatar_template_id' => $avatarTemplate->id,
        ]);
    });

    DB::table('organizations')->where('id', $org->id)->update(['public_id' => null]);
    DB::table('projects')->where('id', $project->id)->update(['public_id' => null]);

    expect(DB::table('organizations')->where('id', $org->id)->value('public_id'))->toBeNull();
    expect(DB::table('projects')->where('id', $project->id)->value('public_id'))->toBeNull();

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_130000_add_public_id_to_organizations_and_projects_tables.php');
    $migration->up();

    $backfilledOrgId = DB::table('organizations')->where('id', $org->id)->value('public_id');
    $backfilledProjectId = DB::table('projects')->where('id', $project->id)->value('public_id');

    expect($backfilledOrgId)->not->toBeNull();
    expect($backfilledOrgId)->toHaveLength(26);
    expect($backfilledProjectId)->not->toBeNull();
    expect($backfilledProjectId)->toHaveLength(26);

    // The column-level NOT NULL constraint is restored by the same up()
    // call, not left widened from the setup above — the migration's own
    // `columnIsNotNull()` guard re-applies `->change()` when it finds the
    // column nullable, exactly the "resume after partial failure" case its
    // own docblock describes.
    $publicIdColumn = collect(Schema::getColumns('organizations'))->firstWhere('name', 'public_id');
    expect($publicIdColumn['nullable'])->toBeFalse();
});
