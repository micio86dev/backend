<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B1b.2: `participants.reusable_interview_link_id`
 * (design AD-4 / AD-5).
 *
 * Verifies the structural invariants added by
 * `2026_10_01_100100_add_reusable_interview_link_id_to_participants_table.php`:
 * - the column is a nullable bigint with no default: every participant that
 *   existed before, and every one created by SSO, M2M or the operator entry link
 *   afterwards, carries NULL
 * - the foreign key is VALIDATED and `ON DELETE SET NULL`: disabling a link is
 *   soft, but if a link row ever does disappear (org/project hard-delete
 *   cascades) its visitors must survive as ordinary participants, never be
 *   deleted with it
 * - the index leads with `organization_id` (D22) and is PARTIAL (nearly every
 *   row is NULL and no query can use those entries)
 * - nothing is UNIQUE on the column: one link legitimately produces many
 *   visitors, and the existing per-project uniques are untouched
 * - `Participant::reusableInterviewLink()` and `ReusableInterviewLink::participants()`
 *   resolve, and the factory state builds a coherent visitor-side row
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

/**
 * @return array{0: Organization, 1: Project, 2: ReusableInterviewLink}
 */
function reusableLinkMarkerSchemaFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $link = ReusableInterviewLink::factory()->forProject($project)->create();

    return [$org, $project, $link];
}

/**
 * A participant created by the link (marker set through forceFill, exactly as
 * the redemption action will write it).
 */
function reusableLinkMarkerSchemaVisitor(Project $project, ReusableInterviewLink $link): Participant
{
    $visitor = Participant::factory()->forProject($project)->create();
    $visitor->forceFill(['reusable_interview_link_id' => $link->id])->save();

    return $visitor->fresh() ?? $visitor;
}

test('participants has a reusable_interview_link_id column', function (): void {
    expect(Schema::hasColumn('participants', 'reusable_interview_link_id'))->toBeTrue();
});

test('reusable_interview_link_id is a nullable bigint with no default', function (): void {
    $column = DB::selectOne("
        SELECT data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'participants' AND table_schema = current_schema()
          AND column_name = 'reusable_interview_link_id'
    ");

    expect($column)->not->toBeNull();
    expect($column->data_type)->toBe('bigint');
    expect($column->is_nullable)->toBe('YES');
    expect($column->column_default)->toBeNull();
});

test('the foreign key references reusable_interview_links, ON DELETE SET NULL, and is validated', function (): void {
    $fk = DB::selectOne("
        SELECT pg_get_constraintdef(oid) AS definition, convalidated
        FROM pg_constraint
        WHERE conrelid = 'participants'::regclass
          AND conname = 'participants_reusable_interview_link_id_foreign'
    ");

    expect($fk)->not->toBeNull();
    expect($fk->definition)
        ->toContain('FOREIGN KEY (reusable_interview_link_id)')
        ->toContain('REFERENCES reusable_interview_links(id)')
        ->toContain('ON DELETE SET NULL');
    expect((bool) $fk->convalidated)->toBeTrue();
});

test('the foreign key rejects a marker that points at no link', function (): void {
    [, $project] = reusableLinkMarkerSchemaFixtures();
    $participant = Participant::factory()->forProject($project)->create();

    // A failed statement aborts the surrounding Postgres transaction
    // (RefreshDatabase wraps every test in one), so the attempt runs in its own
    // savepoint and the assertion cannot pass on a later 25P02.
    try {
        DB::transaction(fn () => DB::table('participants')
            ->where('id', $participant->id)
            ->update(['reusable_interview_link_id' => 999_999_999]));
        Assert::fail('Expected the reusable_interview_link_id foreign key to reject the update.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23503', $e->getMessage());
    }
});

test('really deleting a link keeps its visitors and clears only their marker', function (): void {
    [, $project, $link] = reusableLinkMarkerSchemaFixtures();
    $first = reusableLinkMarkerSchemaVisitor($project, $link);
    $second = reusableLinkMarkerSchemaVisitor($project, $link);

    expect($first->reusable_interview_link_id)->toBe($link->id);

    $link->delete();

    foreach ([$first, $second] as $visitor) {
        $row = DB::table('participants')->where('id', $visitor->id)->first();

        expect($row)->not->toBeNull();
        expect($row->reusable_interview_link_id)->toBeNull();
        expect($row->candidate_ref)->toBe($visitor->candidate_ref);
    }
});

test('deleting the project removes its links and its participants, with no dangling marker', function (): void {
    [, $project, $link] = reusableLinkMarkerSchemaFixtures();
    reusableLinkMarkerSchemaVisitor($project, $link);

    DB::table('projects')->where('id', $project->id)->delete();

    expect(DB::table('participants')->where('project_id', $project->id)->count())->toBe(0);
    expect(DB::table('reusable_interview_links')->where('id', $link->id)->count())->toBe(0);
});

test('the (organization_id, reusable_interview_link_id) index exists and is partial on the marker', function (): void {
    $index = DB::selectOne("
        SELECT indexdef FROM pg_indexes
        WHERE tablename = 'participants' AND indexname = 'participants_org_reusable_link_index'
    ");

    expect($index)->not->toBeNull();
    expect($index->indexdef)
        ->toContain('(organization_id, reusable_interview_link_id)')
        ->toContain('WHERE (reusable_interview_link_id IS NOT NULL)');
});

test('the marker index is valid and not unique', function (): void {
    $index = DB::selectOne("
        SELECT i.indisvalid, i.indisunique
        FROM pg_index i
        JOIN pg_class c ON c.oid = i.indexrelid
        WHERE c.relname = 'participants_org_reusable_link_index'
    ");

    expect($index)->not->toBeNull();
    expect((bool) $index->indisvalid)->toBeTrue();
    expect((bool) $index->indisunique)->toBeFalse();
});

test('no unique index or constraint covers the marker: one link produces many visitors', function (): void {
    $unique = DB::select("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'participants'
          AND indexdef LIKE 'CREATE UNIQUE%'
          AND indexdef LIKE '%reusable_interview_link_id%'
    ");

    expect($unique)->toBeEmpty();

    [, $project, $link] = reusableLinkMarkerSchemaFixtures();
    reusableLinkMarkerSchemaVisitor($project, $link);
    reusableLinkMarkerSchemaVisitor($project, $link);

    expect(DB::table('participants')->where('reusable_interview_link_id', $link->id)->count())->toBe(2);
});

test('the per-project unique indexes on email and candidate_ref are untouched', function (): void {
    $indexes = collect(DB::select("
        SELECT indexname, indexdef FROM pg_indexes
        WHERE tablename = 'participants'
          AND indexname IN ('participants_project_id_email_unique', 'participants_project_id_candidate_ref_unique')
    "))->keyBy('indexname');

    expect($indexes)->toHaveCount(2);
    expect($indexes['participants_project_id_email_unique']->indexdef)
        ->toContain('CREATE UNIQUE INDEX')
        ->toContain('(project_id, email)');
    expect($indexes['participants_project_id_candidate_ref_unique']->indexdef)
        ->toContain('CREATE UNIQUE INDEX')
        ->toContain('(project_id, candidate_ref)');
});

test('a participant created without a link has a NULL marker', function (): void {
    [, $project] = reusableLinkMarkerSchemaFixtures();

    $participant = Participant::factory()->forProject($project)->create();

    expect($participant->fresh()->reusable_interview_link_id)->toBeNull();
    expect($participant->fresh()->reusableInterviewLink)->toBeNull();
});

test('reusableInterviewLink() resolves the link that created the participant', function (): void {
    [, $project, $link] = reusableLinkMarkerSchemaFixtures();

    $visitor = reusableLinkMarkerSchemaVisitor($project, $link);

    expect($visitor->reusable_interview_link_id)->toBeInt()->toBe($link->id);
    expect($visitor->reusableInterviewLink)->toBeInstanceOf(ReusableInterviewLink::class);
    expect($visitor->reusableInterviewLink->is($link))->toBeTrue();
});

test('ReusableInterviewLink::participants() returns the visitors of that link only', function (): void {
    [, $project, $link] = reusableLinkMarkerSchemaFixtures();
    $otherLink = ReusableInterviewLink::factory()->forProject($project)->create();

    $visitors = [
        reusableLinkMarkerSchemaVisitor($project, $link),
        reusableLinkMarkerSchemaVisitor($project, $link),
    ];
    reusableLinkMarkerSchemaVisitor($project, $otherLink);
    Participant::factory()->forProject($project)->create();

    expect($link->participants()->pluck('id')->sort()->values()->all())
        ->toBe(collect($visitors)->pluck('id')->sort()->values()->all());
    expect($link->participants()->count())->toBe(2);
    expect($otherLink->participants()->count())->toBe(1);
});

test('fromReusableLink() builds a participant of the link\'s project, tenant and language, carrying the marker', function (): void {
    [$org, , $link] = reusableLinkMarkerSchemaFixtures();
    $link->forceFill(['lang' => 'it'])->save();

    $participant = Participant::factory()->fromReusableLink($link)->create();
    $fresh = $participant->fresh();

    expect($fresh->reusable_interview_link_id)->toBe($link->id);
    expect($fresh->project_id)->toBe($link->project_id);
    expect($fresh->organization_id)->toBe($org->id);
    expect($fresh->language)->toBe('it');
    expect($fresh->reusableInterviewLink->is($link))->toBeTrue();
});
