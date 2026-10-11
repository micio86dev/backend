<?php

declare(strict_types=1);

use App\Enums\PromptSource;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;

/**
 * RED — Task 1.1: config/conversation.php (C8 Phase 1).
 *
 * Asserts:
 * (a) config('conversation.prompt_version') returns a non-empty string.
 * (b) config('conversation.followup_budget') returns an int.
 *
 * REQ: config/conversation.php (C8 RV-4)
 */
test('(a) conversation.prompt_version is a non-empty string', function (): void {
    $version = config('conversation.prompt_version');

    expect($version)->toBeString()->not->toBeEmpty();
});

test('(b) conversation.followup_budget is an integer', function (): void {
    $budget = config('conversation.followup_budget');

    expect($budget)->toBeInt();
});

test('(c) conversation.min_questions is an integer', function (): void {
    expect(config('conversation.min_questions'))->toBeInt();
});

test('(d) parity: every conversation config default matches .env.example', function (): void {
    // Mirrors the D8 parity guard in PromptBuilderTest. It guards the two FILE
    // defaults only — an environment that pins these variables explicitly (as
    // Railway's api service does for SCORING_PROMPT_VERSION) must be bumped at
    // deploy time, and no test can see that.
    $env = (string) file_get_contents(base_path('.env.example'));

    $pairs = [
        'CONVERSATION_PROMPT_VERSION' => (string) config('conversation.prompt_version'),
        'CONVERSATION_PROMPT_SOURCE' => (string) config('conversation.prompt_source'),
        'CONVERSATION_FOLLOWUP_BUDGET' => (string) config('conversation.followup_budget'),
        'CONVERSATION_MIN_QUESTIONS' => (string) config('conversation.min_questions'),
    ];

    foreach ($pairs as $key => $configured) {
        $pattern = "/^{$key}=(.+)$/m";

        expect($env)->toMatch($pattern);

        preg_match($pattern, $env, $m);

        expect(trim($m[1]))->toBe(
            $configured,
            "{$key} in .env.example must match config('conversation.*') — they drift silently otherwise."
        );
    }
});

test('(e) min_questions never exceeds what followup_budget permits, by default', function (): void {
    // Not a clamp test (the composer owns that) — a config-sanity test. If the
    // shipped defaults themselves disagree, every interview relies on the clamp
    // to paper over it, and the stated intent is lost.
    expect((int) config('conversation.min_questions'))
        ->toBeLessThanOrEqual((int) config('conversation.followup_budget') + 1);
});

test('(f) the prompt source defaults to the database and only db and baseline are sources', function (): void {
    expect(config('conversation.prompt_source'))->toBe('db')
        ->and(array_map(static fn (PromptSource $source): string => $source->value, PromptSource::cases()))->toBe(['db', 'baseline']);
});

test('(g) PromptSource::configured() resolves a valid value and refuses anything else, naming it', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    expect(PromptSource::configured())->toBe(PromptSource::Baseline);

    foreach (['', 'DB', 'bogus', ' db'] as $invalid) {
        config(['conversation.prompt_source' => $invalid]);

        expect(fn () => PromptSource::configured())->toThrow(PromptTemplateUnresolvableException::class, "[{$invalid}]");
    }

    config(['conversation.prompt_source' => null]);
    expect(fn () => PromptSource::configured())->toThrow(PromptTemplateUnresolvableException::class, '[null]');
});

test('(h) PromptSource::configured() names a boolean value as true or false, not as 1 or an empty string', function (): void {
    foreach ([true => '[true]', false => '[false]'] as $value => $named) {
        config(['conversation.prompt_source' => (bool) $value]);

        expect(fn () => PromptSource::configured())->toThrow(PromptTemplateUnresolvableException::class, $named);
    }
});
