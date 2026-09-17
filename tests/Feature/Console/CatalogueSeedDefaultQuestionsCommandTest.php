<?php

declare(strict_types=1);

/**
 * RED/GREEN — feat/seed-default-questions: `catalogue:seed-default-questions`
 * loads BEAI's authored default interview questions
 * (`database/framework/default-questions.json`, product-owner approved
 * content — the exact vendored copy of the wrapper's
 * `docs/app_description/02-domain/framework-authoring/default-questions.json`)
 * into the framework catalogue's open draft revision.
 *
 * Every write goes through `App\Actions\Catalogue\CreateDefaultQuestion` —
 * the SAME action `DefaultQuestionController::store()` now calls — so
 * validation-equivalent uniqueness, the `content_version` bump
 * (`BumpsRevisionContentVersion`, fired by `FrameworkDefaultQuestion::
 * create()` itself) and the `PlatformAuditWriter` audit row all match what
 * a superadmin's own UI action produces, never a second hand-rolled writer.
 */

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Writes a minimal `{version, questions}` fixture to a fresh temp file and
 * returns its path, for `--path` (testing only — mirrors `catalogue:import`'s
 * own `--path` override).
 *
 * @param  array<string, list<array{position: int, text: array<string, string>}>>  $questions
 */
function seedDefaultQuestionsFixturePath(array $questions): string
{
    $dir = sys_get_temp_dir().'/catalogue-seed-default-questions-'.uniqid('', true);
    mkdir($dir, recursive: true);
    $path = "{$dir}/default-questions.json";
    file_put_contents($path, json_encode(['version' => 1, 'questions' => $questions], JSON_THROW_ON_ERROR));

    return $path;
}

function seedDefaultQuestionsActor(): User
{
    return User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
}

test('seeds every competency\'s authored default questions into a fresh draft, with exact counts and text', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $exitCode = Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email]);
    expect($exitCode)->toBe(0);

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();

    /** @var array{questions: array<string, list<array{position: int, text: array<string, string>}>>} $file */
    $file = json_decode((string) file_get_contents(database_path('framework/default-questions.json')), true, 512, JSON_THROW_ON_ERROR);

    $totalExpected = 0;

    foreach ($file['questions'] as $code => $questions) {
        $competency = Competency::where('revision_id', $draft->id)->where('code', $code)->firstOrFail();
        $rows = FrameworkDefaultQuestion::where('revision_id', $draft->id)
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get();

        expect($rows)->toHaveCount(count($questions));

        foreach ($questions as $i => $expected) {
            expect($rows[$i]->position)->toBe($expected['position']);
            expect($rows[$i]->getTranslations('text'))->toBe($expected['text']);
        }

        $totalExpected += count($questions);
    }

    expect($totalExpected)->toBe(26); // 18 standard (1 each) + MTG/LAT (4 each)
    expect(FrameworkDefaultQuestion::where('revision_id', $draft->id)->count())->toBe($totalExpected);
});

test('a second run is idempotent — creates nothing new', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email]);
    $countAfterFirst = FrameworkDefaultQuestion::count();
    expect($countAfterFirst)->toBeGreaterThan(0);

    $exitCode = Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email]);

    expect($exitCode)->toBe(0);
    expect(FrameworkDefaultQuestion::count())->toBe($countAfterFirst);
    expect(Artisan::output())->toContain('already seeded');
});

test('a competency code absent from the draft is reported and skipped, not fatal', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $realCode = (string) Competency::where('revision_id', $baseline->id)->value('code');

    $path = seedDefaultQuestionsFixturePath([
        $realCode => [['position' => 0, 'text' => ['en' => 'Real EN', 'it' => 'Real IT']]],
        'NOPE_NOT_A_CODE' => [['position' => 0, 'text' => ['en' => 'x', 'it' => 'x']]],
    ]);

    $exitCode = Artisan::call('catalogue:seed-default-questions', [
        '--actor-email' => $actor->email,
        '--path' => $path,
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('NOPE_NOT_A_CODE');

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();
    $competency = Competency::where('revision_id', $draft->id)->where('code', $realCode)->firstOrFail();
    expect(FrameworkDefaultQuestion::where('revision_id', $draft->id)->where('competency_id', $competency->id)->count())->toBe(1);
});

test('--dry-run writes nothing but reports what it would create', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $countBefore = FrameworkDefaultQuestion::count();
    $draftCountBefore = FrameworkCatalogRevision::where('state', 'draft')->count();

    $exitCode = Artisan::call('catalogue:seed-default-questions', [
        '--actor-email' => $actor->email,
        '--dry-run' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(FrameworkDefaultQuestion::count())->toBe($countBefore);
    expect(FrameworkCatalogRevision::where('state', 'draft')->count())->toBe($draftCountBefore);
    expect(Artisan::output())->toContain('Would create');
});

test('--publish publishes the seeded draft when it is otherwise complete', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $exitCode = Artisan::call('catalogue:seed-default-questions', [
        '--actor-email' => $actor->email,
        '--publish' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(FrameworkCatalogRevision::where('state', 'published')->count())->toBe(2); // baseline + this one
    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
});

test('--publish reports violations and does not publish when the sweep refuses', function (): void {
    $draft = FrameworkCatalogRevision::factory()->draft()->create();
    $competency = Competency::factory()->create(['revision_id' => $draft->id, 'code' => 'PUBFAIL']);
    $role = Role::factory()->create(['revision_id' => $draft->id]);

    // A declared pair with ZERO indicators — PublishRevision::
    // emptyPairViolations() refuses publish for exactly this shape.
    DB::table('framework_role_competency')->insert([
        'revision_id' => $draft->id,
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'position' => 0,
    ]);

    $actor = seedDefaultQuestionsActor();
    $path = seedDefaultQuestionsFixturePath([
        'PUBFAIL' => [['position' => 0, 'text' => ['en' => 'x', 'it' => 'x']]],
    ]);

    $exitCode = Artisan::call('catalogue:seed-default-questions', [
        '--actor-email' => $actor->email,
        '--path' => $path,
        '--publish' => true,
    ]);

    expect($exitCode)->not->toBe(0);
    expect(Artisan::output())->toContain('pair_must_be_anchored');
    expect($draft->fresh()->state)->toBe('draft');

    // The seeding itself still happened — publish refusal does not undo it.
    expect(FrameworkDefaultQuestion::where('revision_id', $draft->id)->where('competency_id', $competency->id)->count())->toBe(1);
});

test('records an audit_logs row for every created default question, with the actor recorded', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $realCode = (string) Competency::where('revision_id', $baseline->id)->value('code');

    $path = seedDefaultQuestionsFixturePath([
        $realCode => [['position' => 0, 'text' => ['en' => 'Real EN', 'it' => 'Real IT']]],
    ]);

    Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email, '--path' => $path]);

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();
    $competency = Competency::where('revision_id', $draft->id)->where('code', $realCode)->firstOrFail();
    $question = FrameworkDefaultQuestion::where('revision_id', $draft->id)->where('competency_id', $competency->id)->firstOrFail();

    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'FrameworkDefaultQuestion')
            ->where('subject_id', $question->id)
            ->where('action', 'catalogue.default_question.created')
            ->where('actor_id', $actor->id)
            ->exists()
    )->toBeTrue();
});

test('--publish refuses cleanly, without publishing, when the source file names a competency the draft does not have', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $realCode = (string) Competency::where('revision_id', $baseline->id)->value('code');

    $path = seedDefaultQuestionsFixturePath([
        $realCode => [['position' => 0, 'text' => ['en' => 'Real EN', 'it' => 'Real IT']]],
        'NOPE_NOT_A_CODE' => [['position' => 0, 'text' => ['en' => 'x', 'it' => 'x']]],
    ]);

    $exitCode = Artisan::call('catalogue:seed-default-questions', [
        '--actor-email' => $actor->email,
        '--path' => $path,
        '--publish' => true,
    ]);

    expect($exitCode)->not->toBe(0);
    expect(Artisan::output())->toContain('NOPE_NOT_A_CODE');

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();
    expect($draft->state)->toBe('draft');

    // Only the baseline is published — PublishRevision::publish() was never
    // invoked for the seeded draft, missing competency or not.
    expect(FrameworkCatalogRevision::where('state', 'published')->count())->toBe(1);
    expect(
        DB::table('audit_logs')
            ->where('subject_type', 'FrameworkCatalogRevision')
            ->where('subject_id', $draft->id)
            ->where('action', 'revision.published')
            ->exists()
    )->toBeFalse();

    // The seeding itself still happened for the competency that does exist.
    $competency = Competency::where('revision_id', $draft->id)->where('code', $realCode)->firstOrFail();
    expect(FrameworkDefaultQuestion::where('revision_id', $draft->id)->where('competency_id', $competency->id)->count())->toBe(1);
});

test('a second run does not overwrite a hand-edited question and reports it as a conflict', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $actor = seedDefaultQuestionsActor();

    Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email]);

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();

    /** @var array{questions: array<string, list<array{position: int, text: array<string, string>}>>} $file */
    $file = json_decode((string) file_get_contents(database_path('framework/default-questions.json')), true, 512, JSON_THROW_ON_ERROR);
    $code = (string) array_key_first($file['questions']);
    $position = $file['questions'][$code][0]['position'];

    $competency = Competency::where('revision_id', $draft->id)->where('code', $code)->firstOrFail();
    $question = FrameworkDefaultQuestion::where('revision_id', $draft->id)
        ->where('competency_id', $competency->id)
        ->where('position', $position)
        ->firstOrFail();

    // Simulate an operator's own hand-edit (or a stale value) applied
    // directly to the row, bypassing CreateDefaultQuestion.
    $question->setTranslations('text', ['en' => 'Hand-edited EN', 'it' => 'Hand-edited IT']);
    $question->saveQuietly();

    $exitCode = Artisan::call('catalogue:seed-default-questions', ['--actor-email' => $actor->email]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain("{$code}:{$position}");
    expect($output)->toContain('conflicting text');

    $question->refresh();
    expect($question->getTranslations('text'))->toBe(['en' => 'Hand-edited EN', 'it' => 'Hand-edited IT']);
});

test('refuses cleanly when no --actor-email is given and there is no unambiguous platform superadmin', function (): void {
    (new FrameworkCatalogSeeder)->run();
    // No platform superadmin created at all.

    $exitCode = Artisan::call('catalogue:seed-default-questions');

    expect($exitCode)->not->toBe(0);
    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
});
