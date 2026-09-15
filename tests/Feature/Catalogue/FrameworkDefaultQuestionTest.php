<?php

declare(strict_types=1);

/**
 * `FrameworkDefaultQuestion` — the catalogue template a superadmin authors per
 * competency, and the one table in this change whose constraints had never
 * been watched to refuse anything.
 *
 * The review gate caught it: the model shipped with no factory and no
 * behavioural test, so `framework_default_questions_rev_competency_position_unique`
 * and `framework_default_questions_competency_revision_fk` were authored,
 * migrated, and never exercised. Every sibling table in this PR has a test
 * that sees its constraints fire. This is that test.
 *
 * Nothing reads this table at interview time — `ApplyCompetencySelection`
 * (PR 5) copies from it into `project_questions`, and only the copy is ever
 * asked. That makes the constraints here the whole safety story: a duplicate
 * position or a cross-revision competency would propagate silently into every
 * project that later selects the competency.
 */

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;

test('it stores its text as a locale map, because the catalogue is bilingual', function (): void {
    // `HasTranslations` with a bare string would store the string under the
    // current locale and silently lose the other — and CLAUDE.md's i18n
    // mandate makes `{en, it}` the shape the whole catalogue is authored in.
    $question = FrameworkDefaultQuestion::factory()->create([
        'text' => ['en' => 'Tell me about a time you disagreed.', 'it' => 'Mi racconti una volta in cui non era d’accordo.'],
    ]);

    expect($question->getTranslation('text', 'en'))->toBe('Tell me about a time you disagreed.')
        ->and($question->getTranslation('text', 'it'))->toBe('Mi racconti una volta in cui non era d’accordo.');
});

test('it resolves its revision and its competency', function (): void {
    $question = FrameworkDefaultQuestion::factory()->create();

    expect($question->revision)->toBeInstanceOf(FrameworkCatalogRevision::class)
        ->and($question->competency)->toBeInstanceOf(Competency::class);
});

test('two defaults cannot share a position within one competency', function (): void {
    // Position is the order the avatar asks them in. Two rows claiming the
    // same slot make that order whatever Postgres returns first, which is the
    // kind of non-determinism that shows up as "the interview asked them in a
    // different order this time" and is never reproducible.
    $first = FrameworkDefaultQuestion::factory()->create(['position' => 1]);

    assertPostgresConstraintViolation(
        fn () => FrameworkDefaultQuestion::factory()->create([
            'revision_id' => $first->revision_id,
            'competency_id' => $first->competency_id,
            'position' => 1,
        ]),
        '23505',
        'framework_default_questions_rev_competency_position_unique',
    );
});

test('a default question cannot point at a competency from another revision', function (): void {
    // THE COMPOSITE FOREIGN KEY, doing the job it exists for. A default
    // authored in revision 2 attached to revision 1's competency would mean a
    // project resolving revision 1 copies a question that belongs to content
    // it is pinned away from — the cross-revision mixing this whole schema is
    // shaped to make impossible.
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    $baselineCompetency = FrameworkDefaultQuestion::factory()->create()->competency;

    assertPostgresConstraintViolation(
        fn () => FrameworkDefaultQuestion::factory()->create([
            'revision_id' => $draft->getKey(),
            'competency_id' => $baselineCompetency->getKey(),
            'position' => 1,
        ]),
        '23503',
        'framework_default_questions_competency_revision_fk',
    );
});
