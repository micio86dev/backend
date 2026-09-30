<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B1b.1: behaviour of
 * `2026_10_01_100100_add_reusable_interview_link_id_to_participants_table` beyond
 * the schema it leaves behind (asserted in
 * `Feature/C6/Schema/ParticipantsReusableLinkSchemaTest`).
 *
 * Like the external-reference migration it follows, this one disables the
 * wrapping transaction (`$withinTransaction = false`) so the index can be built
 * `CONCURRENTLY` on a hot table. That is what makes a partial-failure rerun
 * possible, and it only helps if every statement is ALSO individually guarded:
 * the column by `hasColumn()`, the foreign key by its catalogue row AND its
 * `convalidated` flag (it is added `NOT VALID` and validated in a SEPARATE
 * statement, so a failure between the two leaves an unvalidated constraint that
 * a rerun must finish), the index by repair-then-`hasIndex()`. Proven by calling
 * the same anonymous migration class directly, from inside `RefreshDatabase`'s
 * own wrapping transaction (`Feature/Migration` is configured that way in
 * `tests/Pest.php`) — the condition under which `CONCURRENTLY` must NOT be
 * attempted, because Postgres refuses it there.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const REUSABLE_LINK_MARKER_INDEX = 'participants_org_reusable_link_index';

const REUSABLE_LINK_MARKER_INDEX_DEFINITION = '(organization_id, reusable_interview_link_id) WHERE (reusable_interview_link_id IS NOT NULL)';

const REUSABLE_LINK_MARKER_FOREIGN_KEY = 'participants_reusable_interview_link_id_foreign';

function reusableLinkMarkerMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_01_100100_add_reusable_interview_link_id_to_participants_table.php');

    return $migration;
}

/**
 * @return list<string> the statements run while the callback executes
 */
function reusableLinkMarkerStatementsDuring(callable $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

function reusableLinkMarkerIndexDefinition(): ?string
{
    $row = DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
        ['participants', REUSABLE_LINK_MARKER_INDEX]
    );

    return is_object($row) && is_string($row->indexdef ?? null) ? $row->indexdef : null;
}

function reusableLinkMarkerIndexIsValid(): bool
{
    $row = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [REUSABLE_LINK_MARKER_INDEX]);

    return is_object($row) && (bool) $row->indisvalid;
}

/**
 * `null` when the constraint does not exist, otherwise whether Postgres has
 * validated it against the existing rows.
 */
function reusableLinkMarkerForeignKeyValidated(): ?bool
{
    $row = DB::selectOne(
        'SELECT convalidated FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?',
        ['participants', REUSABLE_LINK_MARKER_FOREIGN_KEY]
    );

    return is_object($row) ? (bool) $row->convalidated : null;
}

/**
 * A participant created BY a link, inside the tenant that owns it.
 *
 * @return array{0: Participant, 1: ReusableInterviewLink}
 */
function reusableLinkMarkerVisitor(): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $link = ReusableInterviewLink::factory()->forProject($project)->create();

    $visitor = Participant::factory()->forProject($project)->create();
    $visitor->forceFill(['reusable_interview_link_id' => $link->id])->save();

    return [$visitor, $link];
}

test('the migration disables the wrapping transaction so the index can be built concurrently', function (): void {
    expect(reusableLinkMarkerMigration()->withinTransaction)->toBeFalse();
});

test('up() is idempotent on rerun', function (): void {
    reusableLinkMarkerMigration()->up();

    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeTrue();
    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
    expect(Schema::hasIndex('participants', REUSABLE_LINK_MARKER_INDEX))->toBeTrue();
    expect(reusableLinkMarkerIndexIsValid())->toBeTrue();
    expect(collect(DB::select(
        "SELECT 1 FROM pg_constraint WHERE conrelid = to_regclass('participants') AND conname = ?",
        [REUSABLE_LINK_MARKER_FOREIGN_KEY]
    )))->toHaveCount(1);
});

test('the foreign key is added NOT VALID and validated in a separate, later statement', function (): void {
    DB::statement('ALTER TABLE participants DROP COLUMN reusable_interview_link_id');

    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->up());

    $addedNotValid = collect($statements)->search(
        fn (string $sql): bool => str_contains(strtoupper($sql), 'ADD CONSTRAINT') && str_contains(strtoupper($sql), 'NOT VALID')
    );
    $validated = collect($statements)->search(
        fn (string $sql): bool => str_contains(strtoupper($sql), 'VALIDATE CONSTRAINT')
    );

    expect($addedNotValid)->not->toBeFalse();
    expect($validated)->not->toBeFalse()->toBeGreaterThan($addedNotValid);
    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
});

test('up() re-adds a missing column together with its foreign key and index', function (): void {
    // Dropping the column takes the foreign key and the index that use it with
    // it — exactly the half-applied shape a rerun after a partial failure meets.
    DB::statement('ALTER TABLE participants DROP COLUMN reusable_interview_link_id');

    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeFalse();
    expect(reusableLinkMarkerForeignKeyValidated())->toBeNull();
    expect(Schema::hasIndex('participants', REUSABLE_LINK_MARKER_INDEX))->toBeFalse();

    reusableLinkMarkerMigration()->up();

    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeTrue();
    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
    expect(reusableLinkMarkerIndexDefinition())->toContain(REUSABLE_LINK_MARKER_INDEX_DEFINITION);
});

test('a missing foreign key is re-added while the column and index are left alone', function (): void {
    DB::statement('ALTER TABLE participants DROP CONSTRAINT '.REUSABLE_LINK_MARKER_FOREIGN_KEY);

    expect(reusableLinkMarkerForeignKeyValidated())->toBeNull();

    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->up());

    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
    expect(collect($statements)->contains(function (string $sql): bool {
        $sql = strtoupper($sql);

        return str_contains($sql, 'DROP INDEX') || str_starts_with($sql, 'CREATE INDEX') || str_contains($sql, 'ADD "REUSABLE_INTERVIEW_LINK_ID"');
    }))->toBeFalse();
});

test('a foreign key left NOT VALID by a failed validation is validated by the rerun, not re-added', function (): void {
    // `ADD CONSTRAINT ... NOT VALID` committed but `VALIDATE CONSTRAINT` never
    // ran (killed between the two): the constraint exists by name, enforces new
    // writes only, and a rerun that checked presence alone would call it done.
    DB::statement('ALTER TABLE participants DROP CONSTRAINT '.REUSABLE_LINK_MARKER_FOREIGN_KEY);
    DB::statement(
        'ALTER TABLE participants ADD CONSTRAINT '.REUSABLE_LINK_MARKER_FOREIGN_KEY.'
         FOREIGN KEY (reusable_interview_link_id) REFERENCES reusable_interview_links(id) ON DELETE SET NULL NOT VALID'
    );

    expect(reusableLinkMarkerForeignKeyValidated())->toBeFalse();

    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->up());

    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'ADD CONSTRAINT')))->toBeFalse();
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'VALIDATE CONSTRAINT')))->toBeTrue();
});

test('a dropped index is rebuilt with plain CREATE INDEX (no CONCURRENTLY) inside a transaction, keeping its predicate', function (): void {
    DB::statement('DROP INDEX '.REUSABLE_LINK_MARKER_INDEX);

    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->up());

    expect(reusableLinkMarkerIndexDefinition())->toContain(REUSABLE_LINK_MARKER_INDEX_DEFINITION);

    // Postgres refuses CONCURRENTLY inside a transaction block, so a test that
    // got this wrong would have errored rather than failed an assertion.
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'CONCURRENTLY')))->toBeFalse();
    expect(collect($statements)->filter(fn (string $sql): bool => str_starts_with(strtoupper($sql), 'CREATE INDEX')))->toHaveCount(1);
});

test('an INVALID index (a failed CONCURRENTLY build) is dropped and rebuilt, not left in place', function (): void {
    // Schema::hasIndex() reports an index as present purely by NAME. A
    // CREATE INDEX CONCURRENTLY that failed partway leaves exactly this shape:
    // in the catalogue, but pg_index.indisvalid = false, so Postgres never
    // uses it and a rerun that only checked presence would keep saying "done".
    DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = '".REUSABLE_LINK_MARKER_INDEX."'::regclass");

    expect(reusableLinkMarkerIndexIsValid())->toBeFalse();
    expect(Schema::hasIndex('participants', REUSABLE_LINK_MARKER_INDEX))->toBeTrue();

    reusableLinkMarkerMigration()->up();

    expect(reusableLinkMarkerIndexIsValid())->toBeTrue();
    expect(reusableLinkMarkerIndexDefinition())->toContain(REUSABLE_LINK_MARKER_INDEX_DEFINITION);
});

test('a valid, fully applied schema is left untouched by a rerun (no ALTER, no DROP, no CREATE)', function (): void {
    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->up());

    expect(collect($statements)->contains(function (string $sql): bool {
        $sql = strtoupper($sql);

        return str_contains($sql, 'DROP INDEX') || str_starts_with($sql, 'CREATE INDEX') || str_starts_with($sql, 'ALTER TABLE');
    }))->toBeFalse();
});

test('down() removes the index, then the constraint, then the column', function (): void {
    $statements = reusableLinkMarkerStatementsDuring(fn () => reusableLinkMarkerMigration()->down());

    expect(Schema::hasIndex('participants', REUSABLE_LINK_MARKER_INDEX))->toBeFalse();
    expect(reusableLinkMarkerForeignKeyValidated())->toBeNull();
    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeFalse();

    $upper = array_map('strtoupper', $statements);
    $dropIndex = collect($upper)->search(fn (string $sql): bool => str_contains($sql, 'DROP INDEX'));
    $dropConstraint = collect($upper)->search(fn (string $sql): bool => str_contains($sql, 'DROP CONSTRAINT'));
    $dropColumn = collect($upper)->search(fn (string $sql): bool => str_contains($sql, 'DROP COLUMN'));

    expect($dropIndex)->not->toBeFalse();
    expect($dropConstraint)->not->toBeFalse()->toBeGreaterThan($dropIndex);
    expect($dropColumn)->not->toBeFalse()->toBeGreaterThan($dropConstraint);
});

test('down() is guarded: running it twice does not fail', function (): void {
    $migration = reusableLinkMarkerMigration();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeFalse();
});

test('down() then up() restores the column, the validated foreign key and the partial index', function (): void {
    $migration = reusableLinkMarkerMigration();

    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeTrue();
    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
    expect(reusableLinkMarkerIndexDefinition())->toContain(REUSABLE_LINK_MARKER_INDEX_DEFINITION);
    expect(reusableLinkMarkerIndexIsValid())->toBeTrue();
});

test('rolling back leaves the visitors behind as ordinary participants, and re-applying leaves them unmarked', function (): void {
    [$visitor] = reusableLinkMarkerVisitor();
    $migration = reusableLinkMarkerMigration();

    $migration->down();

    // The marker is gone with its column; the participant itself is untouched.
    expect(Participant::query()->whereKey($visitor->id)->exists())->toBeTrue();

    $migration->up();

    expect(DB::table('participants')->where('id', $visitor->id)->value('reusable_interview_link_id'))->toBeNull();
});

test('up() on a table that already holds participants leaves every existing row valid with a NULL marker', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create();
    $existing = Participant::factory()->forProject($project)->count(3)->create();

    $migration = reusableLinkMarkerMigration();
    $migration->down();
    $migration->up();

    expect(DB::table('participants')->whereIn('id', $existing->modelKeys())->count())->toBe(3);
    expect(DB::table('participants')->whereIn('id', $existing->modelKeys())->whereNotNull('reusable_interview_link_id')->count())->toBe(0);
    expect(reusableLinkMarkerForeignKeyValidated())->toBeTrue();
});
