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
