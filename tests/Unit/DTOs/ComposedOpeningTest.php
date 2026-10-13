<?php

declare(strict_types=1);

use App\DTOs\Conversation\ComposedOpening;

test('an opening carries its text and the template version that produced it', function (): void {
    $opening = new ComposedOpening('Hello, shall we begin?', 'v3');

    expect($opening->text)->toBe('Hello, shall we begin?')
        ->and($opening->version)->toBe('v3');
});

test('an empty text is refused so a silent avatar can never be issued', function (): void {
    expect(fn () => new ComposedOpening('', 'v3'))
        ->toThrow(InvalidArgumentException::class, 'text must be non-empty');
});

test('an empty version is refused so the opening is always traceable', function (): void {
    expect(fn () => new ComposedOpening('Hello', ''))
        ->toThrow(InvalidArgumentException::class, 'version must be non-empty');
});

test('a whitespace-only text is refused so a silent avatar can never be issued', function (string $blank): void {
    expect(fn () => new ComposedOpening($blank, 'v3'))
        ->toThrow(InvalidArgumentException::class, 'text must be non-empty');
})->with(['spaces' => ['   '], 'tab' => ["\t"], 'newlines' => ["\n\r\n"], 'mixed' => [" \t\n "]]);

test('a whitespace-only version is refused so the opening is always traceable', function (): void {
    expect(fn () => new ComposedOpening('Hello', "  \t"))
        ->toThrow(InvalidArgumentException::class, 'version must be non-empty');
});

test('a normal text with surrounding whitespace is kept verbatim', function (): void {
    expect((new ComposedOpening(" Hello\n", 'v3'))->text)->toBe(" Hello\n");
});

test('a text or version made only of Unicode whitespace is refused', function (string $blank): void {
    expect(fn () => new ComposedOpening($blank, 'v3'))
        ->toThrow(InvalidArgumentException::class, 'text must be non-empty');
    expect(fn () => new ComposedOpening('Hello', $blank))
        ->toThrow(InvalidArgumentException::class, 'version must be non-empty');
})->with([
    'nbsp' => ["\u{00A0}"],
    'ideographic space' => ["\u{3000}"],
    'zero-width space' => ["\u{200B}"],
    'narrow nbsp' => ["\u{202F}"],
    'mixed' => [" \u{00A0}\t\u{3000}\u{200B}\u{202F}\n"],
]);

test('a text with inner or edge Unicode whitespace is kept verbatim', function (): void {
    $text = "\u{00A0}Hello\u{00A0}world\u{3000}";

    expect((new ComposedOpening($text, 'v3'))->text)->toBe($text);
});

test('an invalid UTF-8 text does not crash and is treated as non-blank', function (): void {
    expect((new ComposedOpening("\xC3\x28", 'v3'))->text)->toBe("\xC3\x28");
});
