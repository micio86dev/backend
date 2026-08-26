<?php

declare(strict_types=1);

/**
 * Architecture guard: `LlmBinding` never reaches an HTTP resource, a
 * controller, or a `Log::` call (pluggable-conversation-llm PR P3a,
 * design D6).
 *
 * A readonly DTO with a redacting `__debugInfo()` is the smallest object
 * that cannot accidentally leak its key through a `toArray()` or lazy
 * relation — but only if it never gets handed to the two classes whose job
 * is to serialize things to callers, or to the log.
 */

use App\Services\ConversationLlm\LlmBinding;

test('LlmBinding is absent from app/Http/Resources', function (): void {
    $violations = [];

    foreach (glob(app_path('Http/Resources/*.php')) ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), LlmBinding::class)) {
            $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }

    expect($violations)->toBe([]);
});

test('LlmBinding is absent from app/Http/Controllers', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers'))) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), LlmBinding::class)) {
            $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($violations)->toBe([]);
});

test('LlmBinding never appears as a Log:: argument anywhere in app/', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, 'Log::')) {
            continue;
        }

        foreach (preg_split('/\r\n|\r|\n/', $source) as $line) {
            if (str_contains($line, 'Log::') && str_contains($line, 'LlmBinding')) {
                $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).': '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});
