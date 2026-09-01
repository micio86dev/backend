<?php

declare(strict_types=1);

/**
 * `ImageMagicBytes` decides what an upload IS, not what it claims to be.
 *
 * A browser sends whatever MIME type it likes and a filename ending in `.png`
 * costs nothing to produce, so `mimes:png` in a FormRequest checks the claim
 * rather than the content. The header is the only part a sender cannot lie
 * about without also changing what the file actually is.
 */

use App\Support\Uploads\ImageMagicBytes;

function magicBytesFile(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'magic');
    file_put_contents($path, $bytes);

    return $path;
}

test('real JPEG and PNG headers are recognised', function (): void {
    expect(ImageMagicBytes::extensionFor(magicBytesFile("\xFF\xD8\xFF\xE0 rest")))->toBe('jpg')
        ->and(ImageMagicBytes::extensionFor(magicBytesFile("\x89PNG\r\n\x1A\n rest")))->toBe('png');
});

test('a PHP script named like an image is refused', function (): void {
    // The attack this class exists for. `mimes:png` passes it; the bytes do not.
    expect(ImageMagicBytes::extensionFor(magicBytesFile('<?php system($_GET["c"]); ?>')))->toBeNull();
});

test('SVG is refused, deliberately', function (): void {
    // The obvious format for a logo, and the one this application will not
    // take: SVG is XML, XML carries `<script>`, and an inline SVG served from
    // our own origin executes with our origin's privileges. Asserted so the
    // refusal reads as a decision rather than an oversight somebody "fixes".
    expect(ImageMagicBytes::extensionFor(magicBytesFile(
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
    )))->toBeNull();
});

test('a truncated PNG signature is refused', function (): void {
    // PNG's signature includes a CRLF pair chosen to detect transfers that
    // mangled line endings. Accepting a prefix would throw that away.
    expect(ImageMagicBytes::extensionFor(magicBytesFile("\x89PNG")))->toBeNull();
});

test('an unreadable path is refused rather than assumed safe', function (): void {
    // A file we cannot open is one we cannot vouch for. Fail closed.
    expect(ImageMagicBytes::extensionFor('/nonexistent/path/logo.png'))->toBeNull();
});

test('an empty file is refused', function (): void {
    expect(ImageMagicBytes::extensionFor(magicBytesFile('')))->toBeNull();
});
