<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B1a.5: the `reusable_interview_links` table
 * (design AD-3).
 *
 * Asserts the structural invariants of
 * `2026_10_01_100000_create_reusable_interview_links_table.php`:
 * - every column, with the type, length, nullability and default the design names
 * - NO expiry or revocation-timestamp columns: a reusable link never expires and
 *   is switched off by `disabled_at` alone (a second "revoked" vocabulary would be
 *   two sources of truth for one fact)
 * - `public_id` and `token_hash` are UNIQUE; the raw token has nowhere to live
 * - the three CHECK constraints reject malformed rows at the INSERT boundary, so
 *   a bug in application code cannot store a hash the lookup could never match
 * - the list index exists and every composite index leads with `organization_id`
 *   (D22), with the two single-column uniques as the documented exceptions
 * - FK behaviour: project / organisation deletion cascades to the links, user
 *   deletion only NULLs the two actor columns (the audit of who did it is not
 *   worth losing a link over)
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * @return array{0: Organization, 1: Project}
 */
function reusableLinkSchemaFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();

    return [$org, $project];
}

/**
 * A raw, well-formed row. `$overrides` replace columns; a `null` override keeps
 * the column in the INSERT as NULL (so a NOT NULL violation can be provoked).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reusableLinkSchemaRow(Project $project, array $overrides = []): array
{
    $raw = ReusableLinkTokenGenerator::generate();

    return array_merge([
        'public_id' => (string) Str::ulid(),
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'lang' => 'en',
        'token_hash' => ReusableLinkTokenGenerator::hash($raw),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($raw),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * Assert the INSERT raises a genuine Postgres CHECK violation (SQLSTATE 23514)
 * for the NAMED constraint. A bare `toThrow(QueryException::class)` would also
 * pass on an unrelated failure, such as the table not existing (42P01).
 *
 * @param  array<string, mixed>  $row
 */
function reusableLinkSchemaExpectCheckViolation(array $row, string $constraintName): void
{
    try {
        DB::table('reusable_interview_links')->insert($row);
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514', "Expected check_violation for '{$constraintName}', got '{$e->getCode()}': {$e->getMessage()}");
        expect(str_contains($e->getMessage(), $constraintName))->toBeTrue("Expected '{$constraintName}' in: {$e->getMessage()}");

        return;
    }

    Assert::fail("Expected constraint '{$constraintName}' to reject the insert, but it succeeded.");
}

/**
 * Assert the INSERT raises a unique violation (23505) naming the constraint.
 *
 * @param  array<string, mixed>  $row
 */
function reusableLinkSchemaExpectUniqueViolation(array $row, string $constraintName): void
{
    try {
        DB::table('reusable_interview_links')->insert($row);
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505', "Expected unique_violation for '{$constraintName}', got '{$e->getCode()}': {$e->getMessage()}");
        expect(str_contains($e->getMessage(), $constraintName))->toBeTrue("Expected '{$constraintName}' in: {$e->getMessage()}");

        return;
    }

    Assert::fail("Expected '{$constraintName}' to reject the insert, but it succeeded.");
}

// ─── Columns ─────────────────────────────────────────────────────────────────

test('the reusable_interview_links table exists', function (): void {
    expect(Schema::hasTable('reusable_interview_links'))->toBeTrue();
});

test('every column has the type, length, nullability and default the design names', function (): void {
    $columns = collect(DB::select("
        SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'reusable_interview_links' AND table_schema = current_schema()
    "))->keyBy('column_name');

    // column => [data_type, max length, nullable]
    $expected = [
        'id' => ['bigint', null, 'NO'],
        'public_id' => ['character', 26, 'NO'],
        'organization_id' => ['bigint', null, 'NO'],
        'project_id' => ['bigint', null, 'NO'],
        'created_by' => ['bigint', null, 'YES'],
        'label' => ['character varying', 120, 'YES'],
        'lang' => ['character varying', 10, 'NO'],
        'token_hash' => ['character', 64, 'NO'],
        'token_prefix' => ['character varying', 16, 'NO'],
        'uses_count' => ['integer', null, 'NO'],
        'last_used_at' => ['timestamp with time zone', null, 'YES'],
        'disabled_at' => ['timestamp with time zone', null, 'YES'],
        'disabled_by' => ['bigint', null, 'YES'],
        'created_at' => ['timestamp with time zone', null, 'YES'],
        'updated_at' => ['timestamp with time zone', null, 'YES'],
    ];

    expect($columns->keys()->sort()->values()->all())->toBe(collect($expected)->keys()->sort()->values()->all());

    foreach ($expected as $name => [$type, $length, $nullable]) {
        expect($columns[$name]->data_type)->toBe($type, "{$name} type");
        expect($columns[$name]->character_maximum_length)->toBe($length, "{$name} length");
        expect($columns[$name]->is_nullable)->toBe($nullable, "{$name} nullability");
    }

    expect($columns['uses_count']->column_default)->toBe('0');
    expect($columns['label']->column_default)->toBeNull();
    expect($columns['disabled_at']->column_default)->toBeNull();
});

test('there is no expiry or revocation-timestamp column: a link never expires and is only ever disabled', function (): void {
    foreach (Schema::getColumnListing('reusable_interview_links') as $column) {
        expect($column)->not->toMatch('/expires|expiry|ttl|revoked|deleted_at|valid_until/');
    }
});

// ─── Uniqueness ──────────────────────────────────────────────────────────────

test('public_id and token_hash are each covered by a unique index', function (): void {
    $uniqueColumns = collect(Schema::getIndexes('reusable_interview_links'))
        ->filter(fn (array $index): bool => $index['unique'] === true && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->all();

    expect($uniqueColumns)->toContain(['public_id']);
    expect($uniqueColumns)->toContain(['token_hash']);
});

test('two links cannot share a token_hash', function (): void {
    [, $project] = reusableLinkSchemaFixtures();

    $first = reusableLinkSchemaRow($project);
    DB::table('reusable_interview_links')->insert($first);

    reusableLinkSchemaExpectUniqueViolation(
        reusableLinkSchemaRow($project, ['token_hash' => $first['token_hash']]),
        'reusable_interview_links_token_hash_unique',
    );
});

test('the token_hash uniqueness is global, not per organisation: the lookup runs before any tenant is known', function (): void {
    [, $projectA] = reusableLinkSchemaFixtures();
    [, $projectB] = reusableLinkSchemaFixtures();

    $first = reusableLinkSchemaRow($projectA);
    DB::table('reusable_interview_links')->insert($first);

    reusableLinkSchemaExpectUniqueViolation(
        reusableLinkSchemaRow($projectB, ['token_hash' => $first['token_hash']]),
        'reusable_interview_links_token_hash_unique',
    );
});

test('two links cannot share a public_id', function (): void {
    [, $project] = reusableLinkSchemaFixtures();

    $first = reusableLinkSchemaRow($project);
    DB::table('reusable_interview_links')->insert($first);

    reusableLinkSchemaExpectUniqueViolation(
        reusableLinkSchemaRow($project, ['public_id' => $first['public_id']]),
        'reusable_interview_links_public_id_unique',
    );
});

test('several links may share a label and a project: nothing but the hash and the public id is unique', function (): void {
    [, $project] = reusableLinkSchemaFixtures();

    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($project, ['label' => 'Campus drive']));
    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($project, ['label' => 'Campus drive']));

    expect(DB::table('reusable_interview_links')->where('project_id', $project->id)->count())->toBe(2);
});

// ─── CHECK constraints ───────────────────────────────────────────────────────

test('token_hash must be 64 lowercase hex characters', function (string $badHash): void {
    [, $project] = reusableLinkSchemaFixtures();

    reusableLinkSchemaExpectCheckViolation(
        reusableLinkSchemaRow($project, ['token_hash' => $badHash]),
        'reusable_interview_links_token_hash_check',
    );
})->with(fn (): array => [
    'uppercase hex' => [strtoupper(hash('sha256', 'x'))],
    'non-hex character' => [str_repeat('g', 64)],
    'blank padded' => [str_pad('abc', 64)],
    'sha256 with a dash' => [substr(hash('sha256', 'x'), 0, 63).'-'],
]);

test('token_prefix must start with the beai_rl_ marker, with a literal underscore', function (string $badPrefix): void {
    [, $project] = reusableLinkSchemaFixtures();

    reusableLinkSchemaExpectCheckViolation(
        reusableLinkSchemaRow($project, ['token_prefix' => $badPrefix]),
        'reusable_interview_links_token_prefix_check',
    );
})->with(fn (): array => [
    'an api key prefix' => ['beai_live_AbCdE'],
    'a different marker' => ['beai_rk_AbCdEfGh'],
    'no marker' => ['AbCdEfGhAbCdEfGh'],
    // `_` is a LIKE wildcard: an unescaped pattern would accept these.
    'wildcard underscore 1' => ['beaiXrl_AbCdEfGh'],
    'wildcard underscore 2' => ['beai_rlXAbCdEfGh'],
]);

test('uses_count can never be negative', function (): void {
    [, $project] = reusableLinkSchemaFixtures();

    reusableLinkSchemaExpectCheckViolation(
        reusableLinkSchemaRow($project, ['uses_count' => -1]),
        'reusable_interview_links_uses_count_check',
    );
});

test('a well-formed row is accepted, with uses_count defaulting to 0 and the optional columns NULL', function (): void {
    [$org, $project] = reusableLinkSchemaFixtures();

    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($project));

    $row = DB::table('reusable_interview_links')->where('project_id', $project->id)->first();

    expect($row)->not->toBeNull();
    expect($row->organization_id)->toBe($org->id);
    expect($row->uses_count)->toBe(0);
    expect($row->label)->toBeNull();
    expect($row->last_used_at)->toBeNull();
    expect($row->disabled_at)->toBeNull();
    expect($row->disabled_by)->toBeNull();
    expect($row->created_by)->toBeNull();
});

// ─── Indexes ─────────────────────────────────────────────────────────────────

test('the (organization_id, project_id, created_at) list index exists', function (): void {
    $index = collect(Schema::getIndexes('reusable_interview_links'))
        ->firstWhere('name', 'reusable_interview_links_org_project_index');

    expect($index)->not->toBeNull();
    expect($index['columns'])->toBe(['organization_id', 'project_id', 'created_at']);
    expect($index['unique'])->toBeFalse();
});

test('every composite index leads with organization_id (D22); token_hash and public_id are the documented single-column uniques', function (): void {
    $offenders = [];

    foreach (Schema::getIndexes('reusable_interview_links') as $index) {
        if ($index['primary'] || count($index['columns']) === 1) {
            continue;
        }

        if ($index['columns'][0] !== 'organization_id') {
            $offenders[] = $index['name'];
        }
    }

    expect($offenders)->toBe([]);

    // The two single-column indexes that do NOT lead with organization_id are
    // exactly the two uniques: `token_hash` is looked up before any tenant is
    // known (same documented exception as `api_clients.key_hash`), `public_id`
    // is the externally visible id. Nothing else may join them.
    $singleColumn = collect(Schema::getIndexes('reusable_interview_links'))
        ->filter(fn (array $index): bool => ! $index['primary'] && count($index['columns']) === 1)
        ->map(fn (array $index): string => $index['columns'][0].($index['unique'] ? ':unique' : ''))
        ->sort()
        ->values()
        ->all();

    expect($singleColumn)->toBe(['public_id:unique', 'token_hash:unique']);
});

// ─── Foreign keys ────────────────────────────────────────────────────────────

test('deleting the project deletes its links', function (): void {
    [, $project] = reusableLinkSchemaFixtures();
    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($project));

    DB::table('projects')->where('id', $project->id)->delete();

    expect(DB::table('reusable_interview_links')->count())->toBe(0);
});

test('deleting the organisation deletes its links and only its links', function (): void {
    [$orgA, $projectA] = reusableLinkSchemaFixtures();
    [, $projectB] = reusableLinkSchemaFixtures();
    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($projectA));
    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($projectB));

    DB::table('projects')->where('organization_id', $orgA->id)->delete();
    DB::table('organizations')->where('id', $orgA->id)->delete();

    expect(DB::table('reusable_interview_links')->count())->toBe(1);
    expect(DB::table('reusable_interview_links')->value('project_id'))->toBe($projectB->id);
});

test('deleting a user keeps the link and only clears created_by / disabled_by', function (): void {
    [$org, $project] = reusableLinkSchemaFixtures();
    $creator = User::factory()->create(['organization_id' => $org->id]);
    $disabler = User::factory()->create(['organization_id' => $org->id]);

    DB::table('reusable_interview_links')->insert(reusableLinkSchemaRow($project, [
        'created_by' => $creator->id,
        'disabled_by' => $disabler->id,
        'disabled_at' => now(),
    ]));

    $creator->delete();
    $disabler->delete();

    $row = DB::table('reusable_interview_links')->where('project_id', $project->id)->first();

    expect($row)->not->toBeNull();
    expect($row->created_by)->toBeNull();
    expect($row->disabled_by)->toBeNull();
    expect($row->disabled_at)->not->toBeNull();
});

test('a link cannot reference a project or organisation that does not exist', function (): void {
    [, $project] = reusableLinkSchemaFixtures();

    foreach (['project_id', 'organization_id'] as $column) {
        // A failed statement aborts the surrounding Postgres transaction
        // (RefreshDatabase wraps every test in one), so each attempt runs in
        // its own savepoint: otherwise the second iteration would "fail" with
        // 25P02 and the assertion would pass for the wrong reason.
        try {
            DB::transaction(fn () => DB::table('reusable_interview_links')->insert(
                reusableLinkSchemaRow($project, [$column => 999_999_999])
            ));
            Assert::fail("Expected the {$column} foreign key to reject the insert.");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23503', "{$column}: {$e->getMessage()}");
        }
    }
});
