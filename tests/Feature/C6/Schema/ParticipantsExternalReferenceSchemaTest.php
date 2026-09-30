<?php

declare(strict_types=1);

/**
 * RED — candidate-external-reference A1.4: `external_id` + `source` on
 * `participants` (design AD-4).
 *
 * Verifies the structural invariants added by
 * `2026_09_30_130000_add_external_reference_to_participants_table.php`:
 * - both columns exist, nullable, with the types the value object's caps rely on
 *   (BIGINT for the 2^53-1 cap, VARCHAR(180) for the source cap)
 * - the two composite indexes exist, lead with `organization_id`, and are
 *   PARTIAL (rows with no reference are never indexed)
 * - nothing is UNIQUE and no CHECK couples the pair: a row is an enrolment,
 *   so the same pair legitimately repeats across projects and organisations
 * - a participant with no reference (factory default) has both columns null
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Participant\ExternalReference;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\ExternalReferenceCases;

/**
 * @return array{0: Organization, 1: Project}
 */
function externalReferenceFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id]);

    return [$org, $project];
}

test('participants table has external_id and source columns', function (): void {
    expect(Schema::hasColumn('participants', 'external_id'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'source'))->toBeTrue();
});

test('external_id is a nullable bigint and source a nullable varchar(180)', function (): void {
    $columns = collect(DB::select("
        SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'participants' AND table_schema = current_schema()
          AND column_name IN ('external_id', 'source')
    "))->keyBy('column_name');

    expect($columns)->toHaveCount(2);

    expect($columns['external_id']->data_type)->toBe('bigint');
    expect($columns['external_id']->is_nullable)->toBe('YES');
    expect($columns['external_id']->column_default)->toBeNull();

    expect($columns['source']->data_type)->toBe('character varying');
    expect($columns['source']->character_maximum_length)->toBe(ExternalReference::SOURCE_MAX_LENGTH);
    expect($columns['source']->is_nullable)->toBe('YES');
    expect($columns['source']->column_default)->toBeNull();
});

test('the (organization_id, source, external_id) index exists and is partial on source', function (): void {
    $index = DB::selectOne("
        SELECT indexdef FROM pg_indexes
        WHERE tablename = 'participants' AND indexname = 'participants_org_source_external_id_index'
    ");

    expect($index)->not->toBeNull();
    expect($index->indexdef)
        ->toContain('(organization_id, source, external_id)')
        ->toContain('WHERE (source IS NOT NULL)');
});

test('the (organization_id, external_id) index exists and is partial on external_id', function (): void {
    $index = DB::selectOne("
        SELECT indexdef FROM pg_indexes
        WHERE tablename = 'participants' AND indexname = 'participants_org_external_id_index'
    ");

    expect($index)->not->toBeNull();
    expect($index->indexdef)
        ->toContain('(organization_id, external_id)')
        ->toContain('WHERE (external_id IS NOT NULL)');
});

test('both indexes are valid and neither is unique', function (): void {
    $indexes = DB::select("
        SELECT c.relname, i.indisvalid, i.indisunique
        FROM pg_index i
        JOIN pg_class c ON c.oid = i.indexrelid
        WHERE c.relname IN ('participants_org_source_external_id_index', 'participants_org_external_id_index')
    ");

    expect($indexes)->toHaveCount(2);

    foreach ($indexes as $index) {
        expect((bool) $index->indisvalid)->toBeTrue("{$index->relname} should be valid");
        expect((bool) $index->indisunique)->toBeFalse("{$index->relname} must not be unique");
    }
});

test('no unique index or constraint covers external_id or source', function (): void {
    $unique = DB::select("
        SELECT indexname, indexdef FROM pg_indexes
        WHERE tablename = 'participants'
          AND indexdef LIKE 'CREATE UNIQUE%'
          AND (indexdef LIKE '%external_id%' OR indexdef LIKE '%source%')
    ");

    expect($unique)->toBeEmpty();
});

test('no CHECK constraint couples external_id and source', function (): void {
    $checks = DB::select("
        SELECT conname, pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'participants'::regclass AND contype = 'c'
          AND (pg_get_constraintdef(oid) LIKE '%external_id%' OR pg_get_constraintdef(oid) LIKE '%source%')
    ");

    expect($checks)->toBeEmpty();
});

test('a participant created without a reference has both columns null', function (): void {
    [, $project] = externalReferenceFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    expect($participant->fresh()->external_id)->toBeNull();
    expect($participant->fresh()->source)->toBeNull();
});

test('withExternalReference() defaults to 12345 and workday', function (): void {
    [, $project] = externalReferenceFixtures();

    $participant = Participant::factory()->forProject($project)->withExternalReference()->create();

    expect($participant->fresh()->external_id)->toBe(12345);
    expect($participant->fresh()->source)->toBe('workday');
});

test('withExternalReference() round-trips an int up to the safe-integer cap', function (): void {
    [, $project] = externalReferenceFixtures();

    $participant = Participant::factory()->forProject($project)
        ->withExternalReference(ExternalReference::MAX_EXTERNAL_ID, 'greenhouse')
        ->create();

    $fresh = $participant->fresh();

    expect($fresh->external_id)->toBeInt()->toBe(ExternalReference::MAX_EXTERNAL_ID);
    expect($fresh->source)->toBe('greenhouse');
});

test('every legitimate combination of the pair persists', function (?int $externalId, ?string $source): void {
    [, $project] = externalReferenceFixtures();

    $participant = Participant::factory()->forProject($project)->create(
        (new ExternalReference($externalId, $source))->toAttributes()
    );

    expect($participant->fresh()->external_id)->toBe($externalId);
    expect($participant->fresh()->source)->toBe($source);
})->with(fn (): array => ExternalReferenceCases::validCombinations());

test('the same pair repeats across projects and organisations (no uniqueness)', function (): void {
    [, $projectA] = externalReferenceFixtures();
    $projectB = Project::factory()->create(['organization_id' => $projectA->organization_id]);
    [, $projectC] = externalReferenceFixtures();

    foreach ([$projectA, $projectB, $projectC] as $project) {
        Participant::factory()->forProject($project)->withExternalReference(4471, 'acme-ats')->create();
    }

    expect(Participant::query()->where('external_id', 4471)->where('source', 'acme-ats')->count())->toBe(3);
});

test('the same pair may even repeat inside one project (a row is an enrolment, not an identity)', function (): void {
    [, $project] = externalReferenceFixtures();

    Participant::factory()->forProject($project)->count(2)->withExternalReference(4471, 'acme-ats')->create();

    expect(Participant::query()->where('project_id', $project->id)->where('external_id', 4471)->count())->toBe(2);
});
