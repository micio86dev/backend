<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR7: the data migration that stores
 * `database/prompt-sets/baseline-1.json` as the baseline conversation prompt set
 * and activates it, so every database has an active set after `migrate`.
 *
 * `RefreshDatabase` already ran the migration once; the tests call its `up()`
 * again, or empty the three tables first (the immutability triggers are disabled
 * for that, inside the test transaction that is rolled back), to prove each
 * property. The hash algorithm is frozen INSIDE the migration, so a later change
 * to `PromptSetSeal` can never silently re-seal history; the equality test below
 * is what makes any drift visible.
 */

use App\Enums\PromptFragmentKey;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptFragmentContract;
use App\Support\Conversation\PromptSetSeal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(fn () => PromptSetResolver::flushCache());

/** The anonymous migration, with the `seal()` it freezes exposed. */
function baselineBootstrap(): Migration
{
    return require database_path('migrations/2026_10_09_100000_bootstrap_baseline_conversation_prompt_set.php');
}

/** Insert an ACTIVE, sealed set labelled `other`, the way a publish plus activate leaves it. */
function insertOtherActiveSet(): int
{
    $fragments = PromptSetPayload::fragments();
    $id = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => 'other',
        'content_sha256' => PromptSetSeal::seal($fragments),
        'is_active' => true,
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($fragments as $row) {
        DB::table('conversation_prompt_fragments')->insert([
            'prompt_set_id' => $id,
            'fragment_key' => $row['key'],
            'locale' => $row['locale'],
            'body' => $row['body'],
            'created_at' => now(),
        ]);
    }

    return $id;
}

/** @return list<array{key: string, locale: string, body: string}> */
function storedFragmentRows(int $setId): array
{
    return DB::table('conversation_prompt_fragments')->where('prompt_set_id', $setId)->get()
        ->map(static fn (object $row): array => ['key' => $row->fragment_key, 'locale' => $row->locale, 'body' => $row->body])
        ->all();
}

test('a migrated database has exactly one active baseline set, complete and sealed', function (): void {
    $sets = DB::table('conversation_prompt_sets')->get();
    $set = $sets->first();

    expect($sets)->toHaveCount(1)
        ->and($set->label)->toBe('baseline-1')
        ->and($set->is_active)->toBeTrue()
        ->and($set->activated_at)->not->toBeNull()
        ->and(DB::table('conversation_prompt_fragments')->where('prompt_set_id', $set->id)->count())->toBe(count(PromptFragmentKey::cases()) * 2)
        ->and(DB::table('conversation_prompt_overrides')->count())->toBe(0)
        ->and($set->content_sha256)->toBe(PromptSetSeal::seal(storedFragmentRows($set->id)));
});

test('the active set resolves to the baseline text for every key in en and it', function (string $locale): void {
    $resolved = app(PromptSetResolver::class)->resolveActive($locale, 'COL', null);
    $contract = new PromptFragmentContract;

    expect($resolved->setLabel)->toBe('baseline-1')
        ->and($resolved->override)->toBeNull();

    foreach (PromptFragmentKey::cases() as $key) {
        $template = $resolved->templates->template($key);

        expect($template)->toBe(BaselinePromptFragments::forLocale($locale)[$key->value])
            ->and($contract->violations($key, $template))->toBe([]);
    }
})->with(['en', 'it']);

test('the frozen hash algorithm equals PromptSetSeal for the same rows', function (array $rows): void {
    expect(baselineBootstrap()->seal($rows))->toBe(PromptSetSeal::seal($rows));
})->with([
    'unordered, unicode, slashes and quotes' => [[
        ['key' => 'opening.fallback', 'locale' => 'it', 'body' => "Caffè — \"città\" a/b\nsecond line"],
        ['key' => 'header', 'locale' => 'en', 'body' => 'Hi {{competency_code}}'],
        ['key' => 'header', 'locale' => 'it', 'body' => ''],
        ['key' => 'advance.floor_one', 'locale' => 'en', 'body' => '日本語 and emoji 🙂'],
    ]],
    'no rows' => [[]],
]);

test('the frozen hash algorithm equals PromptSetSeal for the stored baseline rows', function (): void {
    $rows = storedFragmentRows((int) DB::table('conversation_prompt_sets')->value('id'));

    expect($rows)->toHaveCount(count(PromptFragmentKey::cases()) * 2)
        ->and(baselineBootstrap()->seal($rows))->toBe(PromptSetSeal::seal($rows))
        ->and(baselineBootstrap()->seal($rows))->toBe(DB::table('conversation_prompt_sets')->value('content_sha256'));
});

test('running the bootstrap again writes nothing', function (): void {
    $before = [
        DB::table('conversation_prompt_sets')->get()->all(),
        DB::table('conversation_prompt_fragments')->orderBy('id')->get()->all(),
    ];

    baselineBootstrap()->up();

    expect(DB::table('conversation_prompt_sets')->get()->all())->toEqual($before[0])
        ->and(DB::table('conversation_prompt_fragments')->orderBy('id')->get()->all())->toEqual($before[1]);
});

test('an existing set whose stored hash differs from the file fails loudly and changes nothing', function (): void {
    DB::statement('ALTER TABLE conversation_prompt_sets DISABLE TRIGGER USER');
    DB::table('conversation_prompt_sets')->update(['content_sha256' => str_repeat('0', 64)]);
    DB::statement('ALTER TABLE conversation_prompt_sets ENABLE TRIGGER USER');

    expect(fn () => baselineBootstrap()->up())->toThrow(RuntimeException::class, 'baseline-1');
    expect(DB::table('conversation_prompt_sets')->value('content_sha256'))->toBe(str_repeat('0', 64))
        ->and(DB::table('conversation_prompt_sets')->count())->toBe(1);
});

test('an existing set whose stored rows no longer match its hash fails loudly', function (): void {
    DB::statement('ALTER TABLE conversation_prompt_fragments DISABLE TRIGGER USER');
    DB::table('conversation_prompt_fragments')->where('fragment_key', 'header')->where('locale', 'en')->update(['body' => 'tampered']);
    DB::statement('ALTER TABLE conversation_prompt_fragments ENABLE TRIGGER USER');

    expect(fn () => baselineBootstrap()->up())->toThrow(RuntimeException::class, 'do not match');
});

test('on an empty database the bootstrap inserts the set and activates exactly one', function (): void {
    PromptTables::empty();

    baselineBootstrap()->up();

    $sets = DB::table('conversation_prompt_sets')->get();

    expect($sets)->toHaveCount(1)
        ->and($sets->first()->label)->toBe('baseline-1')
        ->and($sets->first()->is_active)->toBeTrue()
        ->and(DB::table('conversation_prompt_sets')->where('is_active', true)->count())->toBe(1)
        ->and(app(PromptSetResolver::class)->resolveActive('en', 'COL', null)->setLabel)->toBe('baseline-1');
});

test('when another set is already active the baseline is inserted inactive and the incumbent stays active', function (): void {
    PromptTables::empty();
    $otherId = insertOtherActiveSet();

    baselineBootstrap()->up();

    $baseline = DB::table('conversation_prompt_sets')->where('label', 'baseline-1')->first();

    expect($baseline)->not->toBeNull()
        ->and($baseline->is_active)->toBeFalse()
        ->and($baseline->activated_at)->toBeNull()
        ->and(DB::table('conversation_prompt_sets')->where('is_active', true)->pluck('id')->all())->toBe([$otherId])
        ->and(DB::table('conversation_prompt_fragments')->where('prompt_set_id', $baseline->id)->count())->toBe(count(PromptFragmentKey::cases()) * 2);
});

test('down() removes nothing', function (): void {
    $before = DB::table('conversation_prompt_fragments')->count();

    baselineBootstrap()->down();

    expect(DB::table('conversation_prompt_sets')->where('label', 'baseline-1')->where('is_active', true)->count())->toBe(1)
        ->and(DB::table('conversation_prompt_fragments')->count())->toBe($before);
});

test('a baseline file that carries overrides is refused and no set is written', function (mixed $overrides): void {
    $file = json_decode((string) file_get_contents(database_path('prompt-sets/baseline-1.json')), true, 16, JSON_THROW_ON_ERROR);
    if ($overrides === null) {
        unset($file['overrides']);
    } else {
        $file['overrides'] = $overrides;
    }

    $migration = baselineBootstrap();
    $original = database_path();
    $dir = sys_get_temp_dir().'/baseline-overrides-'.bin2hex(random_bytes(6));
    mkdir($dir.'/prompt-sets', 0755, true);
    file_put_contents($dir.'/prompt-sets/baseline-1.json', json_encode($file, JSON_THROW_ON_ERROR));
    PromptTables::empty();
    app()->useDatabasePath($dir);

    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'overrides');
    } finally {
        app()->useDatabasePath($original);
        unlink($dir.'/prompt-sets/baseline-1.json');
        rmdir($dir.'/prompt-sets');
        rmdir($dir);
    }

    expect(DB::table('conversation_prompt_sets')->count())->toBe(0)
        ->and(DB::table('conversation_prompt_fragments')->count())->toBe(0);
})->with([
    'a non-empty list' => [[['fragment_key' => 'header', 'locale' => 'en', 'body' => 'x']]],
    'not a list at all' => ['oops'],
    'the key is absent' => [null],
]);
