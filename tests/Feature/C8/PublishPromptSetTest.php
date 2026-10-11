<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR6b: publishing validates the whole payload
 * BEFORE writing, seals it, inserts the set INACTIVE with its children in one
 * transaction and never activates. Real rows throughout.
 */

use App\Actions\Conversation\ActivatePromptSet;
use App\Actions\Conversation\PublishPromptSet;
use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Models\ConversationPromptFragment;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptSetSeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PromptTables::empty();
    PromptSetResolver::flushCache();
    $this->counts = rowCounts();
});

/** The refusal's violations, or a failure when publishing did not refuse. */
function publishRefusal(array $fragments, array $overrides = [], string $label = 'v-test'): PromptSetException
{
    try {
        app(PublishPromptSet::class)->handle($label, null, $fragments, $overrides);
    } catch (PromptSetException $e) {
        return $e;
    }

    throw new RuntimeException('Expected PromptSetException, none thrown.');
}

/** @return list<int> sets, fragments, overrides */
function rowCounts(): array
{
    return [ConversationPromptSet::query()->count(), ConversationPromptFragment::query()->count(), ConversationPromptOverride::query()->count()];
}

test('a valid payload is stored inactive with a seal that matches its stored rows', function (): void {
    $override = Payload::override('FLL', 'COL', 'en', 'Probe for a time the candidate disagreed with a manager.');

    $set = app(PublishPromptSet::class)->handle('v1', 'first set', Payload::fragments(), [$override]);

    expect($set->is_active)->toBeFalse()
        ->and($set->activated_at)->toBeNull()
        ->and($set->notes)->toBe('first set')
        ->and(ConversationPromptFragment::query()->where('prompt_set_id', $set->id)->count())->toBe(64)
        ->and(ConversationPromptOverride::query()->where('prompt_set_id', $set->id)->count())->toBe(1);

    $stored = ConversationPromptSet::query()->findOrFail($set->id);
    $fragments = ConversationPromptFragment::query()->where('prompt_set_id', $set->id)->get()
        ->map(fn ($f): array => ['key' => $f->fragment_key, 'locale' => $f->locale, 'body' => $f->body])->all();
    $overrides = ConversationPromptOverride::query()->where('prompt_set_id', $set->id)->get()
        ->map(fn ($o): array => $o->only(['role_code', 'competency_code', 'locale', 'body']))->all();

    expect($stored->content_sha256)->toBe(PromptSetSeal::seal($fragments, $overrides))
        ->and($stored->content_sha256)->toBe(PromptSetSeal::seal(Payload::fragments(), [$override]));
});

test('a published set is resolvable once activated, per locale, and publishing alone activates nothing', function (): void {
    app(PublishPromptSet::class)->handle('v1', null, Payload::fragments());

    expect(ConversationPromptSet::query()->where('is_active', true)->exists())->toBeFalse();

    app(ActivatePromptSet::class)->handle('v1');
    $resolver = app(PromptSetResolver::class);
    $italian = $resolver->resolveActive('it', 'COL', null);
    $english = $resolver->resolveActive('en', 'COL', null);
    $published = Payload::byLocale();

    expect($italian->setLabel)->toBe('v1')
        ->and($italian->templates->template(PromptFragmentKey::Header))->toBe($published['it']['header'])
        ->and($english->templates->template(PromptFragmentKey::Header))->toBe($published['en']['header'])
        ->and($italian->templates->template(PromptFragmentKey::Header))->not->toBe($english->templates->template(PromptFragmentKey::Header));
});

test('a locale with an incomplete key set is refused and nothing is persisted', function (): void {
    $rows = array_values(array_filter(
        Payload::fragments(),
        static fn (array $r): bool => ! ($r['locale'] === 'it' && $r['key'] === 'advance.floor_one'),
    ));

    $refusal = publishRefusal($rows);

    expect($refusal->reason)->toBe(PromptSetException::INVALID)
        ->and(implode(';', $refusal->violations))->toContain('advance.floor_one')->toContain('[it]')
        ->and(rowCounts())->toBe($this->counts);
});

test('a key outside the enum and a duplicated key are refused', function (): void {
    $unknown = publishRefusal([...Payload::fragments(['en']), ['key' => 'made.up', 'locale' => 'en', 'body' => 'x']]);
    $duplicate = publishRefusal([...Payload::fragments(['en']), ['key' => 'header', 'locale' => 'en', 'body' => 'again']]);

    expect(implode(';', $unknown->violations))->toContain('made.up')
        ->and(implode(';', $duplicate->violations))->toContain('header')->toContain('twice')
        ->and(rowCounts())->toBe($this->counts);
});

test('advance.with_phrase without its advance phrase token is refused without leaking the body', function (): void {
    $body = 'UNIQUE-OPERATOR-TEXT with no token at all';
    $refusal = publishRefusal(Payload::fragments(['en'], ['advance.with_phrase' => $body]));

    expect(implode(';', $refusal->violations))->toContain('advance.with_phrase')->toContain('advance_phrase')
        ->and($refusal->getMessage())->not->toContain('UNIQUE-OPERATOR-TEXT')
        ->and(rowCounts())->toBe($this->counts);
});

test('invalid overrides are refused', function (string $label, array $override, string $expected): void {
    $refusal = publishRefusal(Payload::fragments(['en']), [$override]);

    expect(implode(';', $refusal->violations))->toContain($expected)
        ->and(rowCounts())->toBe($this->counts);
})->with([
    'placeholder' => ['placeholder', Payload::override(null, 'COL', 'en', 'Ask about {{budget}}.'), 'placeholder'],
    'no competency' => ['no competency', Payload::override(null, '', 'en', 'Plain text.'), 'competency_code'],
    'empty role' => ['empty role', Payload::override('', 'COL', 'en', 'Plain text.'), 'role_code'],
    'locale without fragments' => ['locale without fragments', Payload::override(null, 'COL', 'it', 'Plain text.'), 'locale [it]'],
]);

test('a duplicate label is refused and the first set is untouched', function (): void {
    app(PublishPromptSet::class)->handle('v1', null, Payload::fragments());

    $refusal = publishRefusal(Payload::fragments(['en'], ['budget' => BaselinePromptFragments::forLocale('en')['budget'].' changed']), [], 'v1');

    expect($refusal->reason)->toBe(PromptSetException::DUPLICATE_LABEL)
        ->and(rowCounts())->toBe([$this->counts[0] + 1, $this->counts[1] + 64, $this->counts[2]]);
});

test('a failure after the set and fragments were inserted rolls everything back', function (): void {
    ConversationPromptOverride::creating(static function (): never {
        throw new RuntimeException('boom');
    });

    try {
        expect(fn () => app(PublishPromptSet::class)->handle('v1', null, Payload::fragments(), [Payload::override(null, 'COL', 'en', 'Plain.')]))
            ->toThrow(RuntimeException::class, 'boom');
    } finally {
        ConversationPromptOverride::flushEventListeners();
    }

    expect(rowCounts())->toBe($this->counts);
});

test('a stored set that fails the read-back verification rolls everything back', function (): void {
    // After the last fragment is written, a row the seal never covered lands in the same set.
    ConversationPromptFragment::created(static function (ConversationPromptFragment $fragment): void {
        if (ConversationPromptFragment::query()->where('prompt_set_id', $fragment->prompt_set_id)->count() === 64) {
            DB::table('conversation_prompt_fragments')->insert([
                'prompt_set_id' => $fragment->prompt_set_id, 'fragment_key' => 'extra.key', 'locale' => 'en', 'body' => 'late row', 'created_at' => now(),
            ]);
        }
    });

    try {
        $refusal = null;

        try {
            app(PublishPromptSet::class)->handle('v1', null, Payload::fragments());
        } catch (PromptTemplateUnresolvableException $e) {
            $refusal = $e;
        }
    } finally {
        ConversationPromptFragment::flushEventListeners();
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->reason)->toBe(PromptTemplateUnresolvableException::SEAL_MISMATCH)
        ->and(rowCounts())->toBe($this->counts);
});
