<?php

declare(strict_types=1);

namespace App\Support\Uploads;

/**
 * The extension an uploaded file's OWN BYTES justify, or null.
 *
 * Extracted from `ProfilePhotoController::verdictExtension()` when a second
 * uploader (the organization logo) needed it. Copying sixty lines of
 * security-critical parsing into a second controller is how two versions of a
 * check start disagreeing, and the one that matters is always the one nobody
 * updated.
 *
 * WHY BYTES AND NOT THE CLIENT'S WORD. A browser sends whatever MIME type it
 * likes and a filename ending in `.png` costs nothing to produce. `mimes:png`
 * in a FormRequest checks the claim, not the content — so a PHP script named
 * `logo.png` passes it. The header is the only part of an upload the sender
 * cannot lie about without also changing what the file actually is.
 *
 * SVG IS DELIBERATELY REFUSED. It is the obvious format for a logo and the
 * reason it is not accepted is worth stating: SVG is XML, XML can carry
 * `<script>`, and an inline SVG served from our own origin executes with our
 * origin's privileges. Sanitising it safely means a real XML parser and an
 * allow-list that has to be maintained against a moving target. A raster logo
 * has none of that surface, and an operator can export one in seconds.
 */
final class ImageMagicBytes
{
    /** JPEG files begin FF D8 FF. */
    private const JPEG = "\xFF\xD8\xFF";

    /** PNG files begin 89 50 4E 47 0D 0A 1A 0A — eight bytes, all significant. */
    private const PNG = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    /**
     * @return 'jpg'|'png'|null Null means "no format this application accepts",
     *                          which includes an unreadable file — a file we
     *                          cannot open is one we cannot vouch for.
     */
    public static function extensionFor(string $realPath): ?string
    {
        $handle = @fopen($realPath, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fread($handle, 8);
        fclose($handle);

        if ($header === false || strlen($header) < 3) {
            return null;
        }

        if (str_starts_with($header, self::JPEG)) {
            return 'jpg';
        }

        // Compared over the full eight bytes, not a prefix: PNG's signature
        // includes a CRLF pair chosen precisely to detect transfers that
        // mangled line endings, and accepting a shorter match would throw that
        // away.
        if ($header === self::PNG) {
            return 'png';
        }

        return null;
    }
}
