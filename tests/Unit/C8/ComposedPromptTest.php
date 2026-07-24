<?php

declare(strict_types=1);

/**
 * RED — Task 1.3: ComposedPrompt DTO (C8 Phase 1).
 *
 * Asserts:
 * (a) ComposedPrompt holds 'text' (string) and 'version' (string); both accessible.
 * (b) Construction with empty string text throws or produces an assertable non-empty constraint.
 * (c) The DTO is readonly (attempting mutation throws Error).
 *
 * REQ: ComposedPrompt value object (C8 Phase 1)
 */

use App\DTOs\Conversation\ComposedPrompt;

test('(a) ComposedPrompt holds text and version; both accessible as strings', function (): void {
    $dto = new ComposedPrompt(
        text: 'You are an adaptive interviewer.',
        version: 'conv-2026-07-23',
    );

    expect($dto->text)->toBe('You are an adaptive interviewer.');
    expect($dto->version)->toBe('conv-2026-07-23');
});

test('(b) ComposedPrompt with empty text throws InvalidArgumentException', function (): void {
    expect(fn () => new ComposedPrompt(text: '', version: 'conv-2026-07-23'))
        ->toThrow(\InvalidArgumentException::class);
});

test('(c) ComposedPrompt with empty version throws InvalidArgumentException', function (): void {
    expect(fn () => new ComposedPrompt(text: 'Some prompt text.', version: ''))
        ->toThrow(\InvalidArgumentException::class);
});

test('(d) ComposedPrompt is readonly — mutation throws Error', function (): void {
    $dto = new ComposedPrompt(text: 'Some text.', version: 'conv-1.0');

    expect(fn () => $dto->text = 'mutated')->toThrow(Error::class);
});
