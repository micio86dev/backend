<?php

declare(strict_types=1);

/**
 * PromptTemplateUnresolvableException rides the existing CompositionException
 * `catch` (422 `composition_error`), carries a distinct machine reason per
 * failure, and never puts template text in its message.
 */

use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException as Unresolvable;

test('every failure is a CompositionException with its own reason', function (): void {
    $cases = [
        Unresolvable::NO_ACTIVE_SET => Unresolvable::noActiveSet(),
        Unresolvable::LOCALE_MISSING => Unresolvable::localeMissing('v1', 'fr'),
        Unresolvable::KEYS_INCOMPLETE => Unresolvable::keysIncomplete('v1', 'en', ['budget'], ['bogus']),
        Unresolvable::CONTRACT_VIOLATED => Unresolvable::contractViolated('v1', 'en', ['fragment [budget] is empty']),
        Unresolvable::SEAL_MISMATCH => Unresolvable::sealMismatch('v1'),
        Unresolvable::OVERRIDE_INVALID => Unresolvable::overrideInvalid('v1', 'en', 'COL', ['override is empty']),
        Unresolvable::AMBIGUOUS_ACTIVE_SET => Unresolvable::ambiguousActiveSet([3, 7]),
        Unresolvable::DUPLICATE_ROW => Unresolvable::duplicateRow('v1', 'en', 'fragment [budget]'),
    ];

    foreach ($cases as $reason => $exception) {
        expect($exception)->toBeInstanceOf(CompositionException::class)
            ->and($exception->reason)->toBe($reason)
            ->and($exception->getMessage())->not->toBe('');
    }

    expect(array_keys($cases))->toHaveCount(8)->and(array_unique(array_keys($cases)))->toHaveCount(8);
});

test('the messages name keys and sets but carry no template body', function (): void {
    $secret = 'TOP-SECRET-BODY-TEXT';

    // The violation messages the contract produces name keys and tokens only; the factories must not add a body.
    $messages = [
        Unresolvable::keysIncomplete('v1', 'en', ['budget'], ['bogus'])->getMessage(),
        Unresolvable::contractViolated('v1', 'en', ['fragment [budget] is empty'])->getMessage(),
        Unresolvable::sealMismatch('v1')->getMessage(),
    ];

    foreach ($messages as $message) {
        expect($message)->not->toContain($secret);
    }

    expect($messages[0])->toContain('budget')->toContain('bogus')
        ->and($messages[1])->toContain('fragment [budget] is empty');
});
