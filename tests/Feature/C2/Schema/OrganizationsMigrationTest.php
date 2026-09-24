<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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
    $pdo = DB::connection()->getPdo();

    expect(fn () => $pdo->exec("INSERT INTO organizations (name, slug) VALUES ('Org Null Check', 'org-null-check')"))
        ->toThrow(PDOException::class);
});
