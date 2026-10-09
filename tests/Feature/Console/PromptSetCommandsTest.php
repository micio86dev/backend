<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR6b: `beai:prompt-set:publish` and
 * `beai:prompt-set:activate` exit non-zero with no side effects on invalid
 * input, zero on success, and never echo a template body.
 */

use App\Actions\Conversation\PublishPromptSet;
use App\Models\ConversationPromptFragment;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PromptSetResolver::flushCache();
    $this->counts = [ConversationPromptSet::query()->count(), ConversationPromptFragment::query()->count(), ConversationPromptOverride::query()->count()];
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function runPublish(array $payload, array $options = []): array
{
    $code = Artisan::call('beai:prompt-set:publish', ['file' => Payload::writeFile($payload)] + $options);

    return [$code, Artisan::output()];
}

function validFile(array $extra = []): array
{
    return $extra + ['label' => 'v1', 'notes' => 'from file', 'fragments' => Payload::byLocale(), 'overrides' => []];
}

test('publish stores an inactive set from the file and exits zero', function (): void {
    [$code, $output] = runPublish(validFile(['overrides' => [Payload::override('FLL', 'COL', 'en', 'Probe for a disagreement.')]]));

    $set = ConversationPromptSet::query()->where('label', 'v1')->firstOrFail();

    expect($code)->toBe(0)
        ->and($output)->toContain('v1')->toContain('64 fragments')->toContain('1 override')
        ->and($set->is_active)->toBeFalse()
        ->and($set->notes)->toBe('from file');
});

test('--label and --notes override the file', function (): void {
    [$code] = runPublish(validFile(), ['--label' => 'renamed', '--notes' => 'cli notes']);

    $set = ConversationPromptSet::query()->where('label', 'renamed')->firstOrFail();

    expect($code)->toBe(0)->and($set->notes)->toBe('cli notes');
});

test('--dry-run validates and writes nothing', function (): void {
    [$code, $output] = runPublish(validFile(), ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('valid')
        ->and(ConversationPromptSet::query()->count())->toBe($this->counts[0]);

    [$invalid] = runPublish(validFile(['fragments' => Payload::byLocale(['en'], ['advance.with_phrase' => 'no token'])]), ['--dry-run' => true]);

    expect($invalid)->toBe(1);
});

test('an invalid payload exits non-zero, persists nothing and never prints a body', function (): void {
    $fragments = Payload::byLocale(['en'], ['advance.with_phrase' => 'LEAKY-OPERATOR-TEXT no token']);

    [$code, $output] = runPublish(validFile(['fragments' => $fragments]));

    expect($code)->toBe(1)
        ->and($output)->toContain('advance.with_phrase')->not->toContain('LEAKY-OPERATOR-TEXT')
        ->and([ConversationPromptSet::query()->count(), ConversationPromptFragment::query()->count()])->toBe([$this->counts[0], $this->counts[1]]);
});

test('publish refuses an unreadable file, bad JSON, a malformed shape and a duplicate label', function (): void {
    $notJson = tempnam(sys_get_temp_dir(), 'prompt-set-');
    file_put_contents($notJson, '{not json');

    expect(Artisan::call('beai:prompt-set:publish', ['file' => '/no/such/file.json']))->toBe(1)
        ->and(Artisan::call('beai:prompt-set:publish', ['file' => $notJson]))->toBe(1);

    [$shape] = runPublish(['label' => 'x', 'fragments' => ['en' => 'not an object']]);
    [$unlabelled] = runPublish(['fragments' => Payload::byLocale()]);

    expect($shape)->toBe(1)->and($unlabelled)->toBe(1);

    app(PublishPromptSet::class)->handle('v1', null, Payload::fragments());
    [$duplicate, $output] = runPublish(validFile());

    expect($duplicate)->toBe(1)->and($output)->toContain('already exists');
});

test('activate switches the active set and exits zero', function (): void {
    app(PublishPromptSet::class)->handle('s1', null, Payload::fragments());

    $code = Artisan::call('beai:prompt-set:activate', ['label' => 's1']);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Activated')->toContain('s1')
        ->and(ConversationPromptSet::query()->where('label', 's1')->value('is_active'))->toBeTrue();
});

test('activating the already active set exits zero and says nothing changed', function (): void {
    app(PublishPromptSet::class)->handle('s1', null, Payload::fragments());
    Artisan::call('beai:prompt-set:activate', ['label' => 's1']);

    $code = Artisan::call('beai:prompt-set:activate', ['label' => 's1']);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('already active')
        ->and(ConversationPromptSet::query()->where('is_active', true)->pluck('label')->all())->toBe(['s1']);
});

test('activate refuses an unknown label and an unverifiable set, leaving the incumbent active', function (): void {
    app(PublishPromptSet::class)->handle('good', null, Payload::fragments());
    Artisan::call('beai:prompt-set:activate', ['label' => 'good']);

    expect(Artisan::call('beai:prompt-set:activate', ['label' => 'nope']))->toBe(1);

    $bad = app(PublishPromptSet::class)->handle('bad', null, Payload::fragments());
    // A late INSERT into a published set (no INSERT trigger by design) breaks its seal.
    DB::table('conversation_prompt_fragments')->insert([
        'prompt_set_id' => $bad->id, 'fragment_key' => 'extra.key', 'locale' => 'en', 'body' => 'tampered', 'created_at' => now(),
    ]);

    $code = Artisan::call('beai:prompt-set:activate', ['label' => 'bad']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->not->toContain('tampered')
        ->and(ConversationPromptSet::query()->where('is_active', true)->pluck('label')->all())->toBe(['good']);
});
