<?php

declare(strict_types=1);

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

    // Verify uniqueness by attempting to insert a duplicate slug
    $pdo = DB::connection()->getPdo();
    $pdo->exec("INSERT INTO organizations (name, slug) VALUES ('Org Alpha', 'org-alpha')");

    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('organizations')->insert(['name' => 'Org Alpha 2', 'slug' => 'org-alpha']);
})->throws(\Illuminate\Database\QueryException::class);
