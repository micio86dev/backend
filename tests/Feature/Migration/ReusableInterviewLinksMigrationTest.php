<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B1a.5/B1a.7: behaviour of
 * `2026_10_01_100000_create_reusable_interview_links_table` beyond the schema it
 * leaves behind (asserted in `Feature/ReusableLinks/Schema/ReusableInterviewLinksSchemaTest`).
 *
 * Unlike the `participants` migrations this one creates a NEW, EMPTY table, so it
 * is an ordinary transactional migration: no `CONCURRENTLY`, no invalid-index
 * repair. What is worth proving is that it is rerunnable and reversible:
 * `up()` on an existing table is a no-op that leaves rows alone, `down()` may be
 * run twice, and `down()` followed by `up()` restores every constraint and index.
 * Proven by calling the same anonymous migration class directly, from inside
 * `RefreshDatabase`'s own wrapping transaction (`Feature/Migration` is configured
 * that way in `tests/Pest.php`).
 */

use App\Models\Organization;
use App\Models\Project;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function reusableLinksMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_01_100000_create_reusable_interview_links_table.php');

    return $migration;
}

/**
 * @return list<string> the statements run while the callback executes
 */
function reusableLinksStatementsDuring(callable $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

/**
 * @return list<string>
 */
function reusableLinksConstraintNames(string $type): array
{
    return collect(DB::select(
        "SELECT conname FROM pg_constraint WHERE conrelid = to_regclass('reusable_interview_links') AND contype = ? ORDER BY conname",
        [$type]
    ))->pluck('conname')->all();
}

/**
 * Inserts one raw link so a rerun can be shown to leave data alone.
 */
function reusableLinksInsertRow(): int
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create();
    $raw = ReusableLinkTokenGenerator::generate();

    return (int) DB::table('reusable_interview_links')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'lang' => 'en',
        'token_hash' => ReusableLinkTokenGenerator::hash($raw),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($raw),
    ]);
}

test('the migration is an ordinary transactional create: a new empty table needs no CONCURRENTLY', function (): void {
    expect(reusableLinksMigration()->withinTransaction)->toBeTrue();
});

test('up() on an existing table is a no-op that leaves rows and structure alone', function (): void {
    $id = reusableLinksInsertRow();
    $before = Schema::getIndexes('reusable_interview_links');

    $statements = reusableLinksStatementsDuring(fn () => reusableLinksMigration()->up());

    expect(DB::table('reusable_interview_links')->where('id', $id)->exists())->toBeTrue();
    expect(Schema::getIndexes('reusable_interview_links'))->toBe($before);
    expect(collect($statements)->contains(function (string $sql): bool {
        $sql = strtoupper($sql);

        return str_contains($sql, 'CREATE ') || str_contains($sql, 'ALTER TABLE') || str_contains($sql, 'DROP ');
    }))->toBeFalse();
});

test('down() drops the table', function (): void {
    reusableLinksMigration()->down();

    expect(Schema::hasTable('reusable_interview_links'))->toBeFalse();
});

test('down() is guarded: running it twice does not fail', function (): void {
    $migration = reusableLinksMigration();

    $migration->down();
    $migration->down();

    expect(Schema::hasTable('reusable_interview_links'))->toBeFalse();
});

test('down() then up() restores the columns, the constraints and the indexes', function (): void {
    $columnsBefore = Schema::getColumnListing('reusable_interview_links');
    $checksBefore = reusableLinksConstraintNames('c');
    $foreignKeysBefore = reusableLinksConstraintNames('f');
    $uniquesBefore = reusableLinksConstraintNames('u');
    $indexNamesBefore = collect(Schema::getIndexes('reusable_interview_links'))->pluck('name')->sort()->values()->all();

    $migration = reusableLinksMigration();
    $migration->down();
    $migration->up();

    expect(Schema::getColumnListing('reusable_interview_links'))->toBe($columnsBefore);
    expect(reusableLinksConstraintNames('c'))->toBe($checksBefore)->not->toBe([]);
    expect(reusableLinksConstraintNames('f'))->toBe($foreignKeysBefore)->toHaveCount(4);
    expect(reusableLinksConstraintNames('u'))->toBe($uniquesBefore)->toHaveCount(2);
    expect(collect(Schema::getIndexes('reusable_interview_links'))->pluck('name')->sort()->values()->all())->toBe($indexNamesBefore);
});

test('the named constraints the schema and the application rely on all exist', function (): void {
    expect(reusableLinksConstraintNames('c'))->toBe([
        'reusable_interview_links_token_hash_check',
        'reusable_interview_links_token_prefix_check',
        'reusable_interview_links_uses_count_check',
    ]);

    expect(reusableLinksConstraintNames('f'))->toBe([
        'reusable_interview_links_created_by_foreign',
        'reusable_interview_links_disabled_by_foreign',
        'reusable_interview_links_organization_id_foreign',
        'reusable_interview_links_project_id_foreign',
    ]);
});
