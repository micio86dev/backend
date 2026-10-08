<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Golden fixtures for the conversation prompt bytes.
 *
 * Each fixture is the raw output of the composer on a pre-change tree, written
 * ONCE by `capture()` and read back by `expected()`. There is deliberately no
 * update mode: `capture()` refuses to overwrite a fixture, so a changed prompt
 * can never be "fixed" by regenerating the expectation. A deliberate prompt
 * change is a reviewed edit of the fixture file itself.
 *
 * `manifest.json` records, per fixture, its sha256, byte length and the commit
 * it was captured from. `directoryHash()` folds every file in the directory
 * (name plus content hash, sorted by name) into one sha256; the single
 * `PINNED_DIRECTORY_SHA256` below is what rejects an added, removed or edited
 * fixture, manifest included.
 *
 * Capture: `PROMPT_GOLDEN_CAPTURE=1 PROMPT_GOLDEN_SOURCE_COMMIT=<40-hex> vendor/bin/pest <file>`.
 */
final class PromptGolden
{
    /**
     * The one place the fixtures directory hash is pinned. Capturing new
     * fixtures changes it; update this constant in the same commit.
     */
    public const PINNED_DIRECTORY_SHA256 = 'a39b685b3e89c2b6341246d4a6d266fe94b8932bbc7abc75b56dcd4fcebd948f';

    private const MANIFEST = 'manifest.json';

    public function __construct(private readonly string $directory) {}

    public static function fixtures(): self
    {
        return new self(dirname(__DIR__).'/Fixtures/Conversation/prompts');
    }

    public static function capturing(): bool
    {
        return getenv('PROMPT_GOLDEN_CAPTURE') === '1';
    }

    /**
     * Write one fixture and its manifest entry.
     *
     * @throws RuntimeException When the fixture exists, or no valid source commit is given.
     */
    public function capture(string $id, string $bytes, ?string $sourceCommit = null): void
    {
        $sourceCommit ??= (string) getenv('PROMPT_GOLDEN_SOURCE_COMMIT');

        if (preg_match('/^[0-9a-f]{40}$/', $sourceCommit) !== 1) {
            throw new RuntimeException('Capture needs PROMPT_GOLDEN_SOURCE_COMMIT=<40-hex commit the bytes were captured from>.');
        }

        $path = $this->path($id);
        $manifest = $this->manifest();

        if (is_file($path) || isset($manifest[$id])) {
            throw new RuntimeException("Golden fixture [{$id}] already exists; capture never overwrites. Review the diff instead.");
        }

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }

        file_put_contents($path, $bytes);

        $manifest[$id] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'source_commit' => $sourceCommit];
        ksort($manifest);
        file_put_contents(
            $this->directory.'/'.self::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * @throws RuntimeException When the fixture was never captured.
     */
    public function expected(string $id): string
    {
        $bytes = is_file($this->path($id)) ? file_get_contents($this->path($id)) : false;

        if ($bytes === false) {
            throw new RuntimeException("Golden fixture [{$id}] is missing; it is captured once on the pre-change tree.");
        }

        return $bytes;
    }

    public function matches(string $id, string $actual): bool
    {
        return hash_equals($this->expected($id), $actual);
    }

    /**
     * True when every manifest entry has a file with the recorded sha256 and
     * length, and every `.txt` file has an entry.
     */
    public function manifestIsConsistent(): bool
    {
        $manifest = $this->manifest();
        $files = array_map(basename(...), glob($this->directory.'/*.txt') ?: []);
        sort($files);

        if ($files !== array_map(static fn (string $id): string => $id.'.txt', array_keys($manifest))) {
            return false;
        }

        foreach ($manifest as $id => $entry) {
            $bytes = $this->expected($id);

            if (hash('sha256', $bytes) !== $entry['sha256'] || strlen($bytes) !== $entry['bytes']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical, order-independent hash of the directory: sorted file names,
     * each with the sha256 of its content.
     */
    public function directoryHash(): string
    {
        $files = array_map(basename(...), glob($this->directory.'/*') ?: []);
        sort($files);

        $canonical = '';

        foreach ($files as $file) {
            $canonical .= $file."\0".hash_file('sha256', $this->directory.'/'.$file)."\n";
        }

        return hash('sha256', $canonical);
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.$id.'.txt';
    }

    /**
     * @return array<string, array{sha256: string, bytes: int, source_commit: string}>
     */
    private function manifest(): array
    {
        $file = $this->directory.'/'.self::MANIFEST;

        if (! is_file($file)) {
            return [];
        }

        /** @var array<string, array{sha256: string, bytes: int, source_commit: string}> */
        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }
}
