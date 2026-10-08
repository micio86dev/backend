<?php

declare(strict_types=1);

/**
 * RED — PR4a.5: PromptFragmentContract validates a fragment TEMPLATE, never
 * rendered text (db-driven-conversation-prompts, design N-2).
 *
 * The contract replaces the code-review gate that database-driven prompts
 * remove, so each rule has its own assertion and the one that guards the
 * MAX_DURATION_REACHED defect (an advance fragment without the advance
 * phrase) is asserted by name.
 */

use App\Enums\PromptFragmentKey;
use App\Support\Conversation\PromptFragmentContract;

/**
 * @return list<string>
 */
$violationsOf = static fn (PromptFragmentKey $key, string $body): array => (new PromptFragmentContract)->violations($key, $body);

$validBody = static fn (PromptFragmentKey $key): string => trim('Text for '.$key->value.' '.implode(' ', array_map(
    static fn (string $token): string => '{{'.$token.'}}',
    $key->requiredTokens(),
)));

test('a well-formed body is accepted for every key', function () use ($violationsOf, $validBody): void {
    foreach (PromptFragmentKey::cases() as $key) {
        expect($violationsOf($key, $validBody($key)))->toBe([], $key->value);
    }
});

test('a token may appear more than once and sit inside quotes', function () use ($violationsOf): void {
    expect($violationsOf(PromptFragmentKey::AdvanceWithPhrase, 'AND {{floor}}, say "{{advance_phrase}}" - {{floor}}'))->toBe([]);
});

test('every required token is enforced, and the violation names key and token', function () use ($violationsOf): void {
    foreach (PromptFragmentKey::cases() as $key) {
        foreach ($key->requiredTokens() as $token) {
            $others = array_diff($key->requiredTokens(), [$token]);
            $body = trim('Body '.implode(' ', array_map(static fn (string $t): string => '{{'.$t.'}}', $others)));

            expect($violationsOf($key, $body))
                ->toBe([sprintf('fragment [%s] is missing the required token {{%s}}', $key->value, $token)]);
        }
    }
});

test('advance.with_phrase without {{advance_phrase}} is refused: the MAX_DURATION_REACHED guard', function () use ($violationsOf): void {
    expect($violationsOf(PromptFragmentKey::AdvanceWithPhrase, 'When done AND {{floor}}, say the closing sentence exactly.'))
        ->toBe(['fragment [advance.with_phrase] is missing the required token {{advance_phrase}}']);
});

test('a token outside the key\'s own set is refused', function () use ($violationsOf): void {
    expect($violationsOf(PromptFragmentKey::Budget, 'At most {{budget}} and {{nope}}.'))
        ->toBe(['fragment [budget] contains the unknown token {{nope}}'])
        ->and($violationsOf(PromptFragmentKey::Star, 'No tokens here, but {{budget}}.'))
        ->toBe(['fragment [star] contains the unknown token {{budget}}'])
        ->and($violationsOf(PromptFragmentKey::Budget, 'At most {{budget}} and {{Budget}}.'))
        ->toBe(['fragment [budget] contains the unknown token {{Budget}}']);
});

test('a stray or malformed brace pair is refused', function () use ($violationsOf): void {
    $stray = 'fragment [budget] contains a stray "{{" or "}}"';

    foreach (['{{budget}} and {{', '{{budget}} and }}', '{{ budget }}', '{{budget}', '{{budget}} {{}}'] as $body) {
        expect($violationsOf(PromptFragmentKey::Budget, $body))->toContain($stray);
    }
});

test('leading and trailing whitespace is refused', function () use ($violationsOf): void {
    foreach ([' Ask {{budget}}', "Ask {{budget}}\n", "\tAsk {{budget}}"] as $body) {
        expect($violationsOf(PromptFragmentKey::Budget, $body))
            ->toBe(['fragment [budget] has leading or trailing whitespace']);
    }
});

test('an empty body is refused', function () use ($violationsOf): void {
    expect($violationsOf(PromptFragmentKey::LabelStar, ''))->toBe(['fragment [label.star] is empty'])
        ->and($violationsOf(PromptFragmentKey::LabelStar, "  \n"))->toContain('fragment [label.star] is empty');
});

test('every violation is reported, not just the first', function () use ($violationsOf): void {
    expect($violationsOf(PromptFragmentKey::AdvanceWithPhrase, ' {{floor}} {{nope}} {{ '))->toBe([
        'fragment [advance.with_phrase] has leading or trailing whitespace',
        'fragment [advance.with_phrase] is missing the required token {{advance_phrase}}',
        'fragment [advance.with_phrase] contains the unknown token {{nope}}',
        'fragment [advance.with_phrase] contains a stray "{{" or "}}"',
    ]);
});

test('assertValid is silent on a good body and throws the joined violations on a bad one', function (): void {
    $contract = new PromptFragmentContract;

    $contract->assertValid(PromptFragmentKey::Budget, 'At most {{budget}}.');

    expect(fn () => $contract->assertValid(PromptFragmentKey::AdvanceWithPhrase, '{{floor}} {{nope}}'))
        ->toThrow(InvalidArgumentException::class, 'missing the required token {{advance_phrase}}')
        ->and(fn () => $contract->assertValid(PromptFragmentKey::AdvanceWithPhrase, '{{floor}} {{nope}}'))
        ->toThrow(InvalidArgumentException::class, 'unknown token {{nope}}');
});

test('an override body is plain text: any placeholder or brace pair is refused', function (): void {
    $contract = new PromptFragmentContract;

    expect($contract->overrideViolations('Always probe for a measurable result.'))->toBe([])
        ->and($contract->overrideViolations('Ask at most {{budget}} questions.'))
        ->toBe(['override contains a placeholder or brace pair "{{" / "}}"'])
        ->and($contract->overrideViolations('Dangling {{'))
        ->toBe(['override contains a placeholder or brace pair "{{" / "}}"'])
        ->and($contract->overrideViolations('Dangling }}'))
        ->toBe(['override contains a placeholder or brace pair "{{" / "}}"'])
        ->and($contract->overrideViolations(''))->toBe(['override is empty']);
});

test('the contract judges templates: a value with a token inside is no concern of it', function () use ($violationsOf): void {
    // The template is valid; what an operator later supplies as a value is never re-validated.
    expect($violationsOf(PromptFragmentKey::AdvanceWithPhrase, 'AND {{floor}}, say: "{{advance_phrase}}"'))->toBe([]);
});
