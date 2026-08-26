<?php

declare(strict_types=1);

/**
 * Architecture guard: `var_export(` appears nowhere in `app/`
 * (pluggable-conversation-llm PR P3a, design D6, Gate Correction C1).
 *
 * `var_export()` IGNORES `__debugInfo()` entirely and dumps public AND
 * PRIVATE properties — `LlmBinding::$apiKey`'s redacting `__debugInfo()`
 * does nothing against it, and visibility is not a mitigation either. The
 * real boundary is banning the function outright.
 *
 * REQ: conversation-llm — LlmBinding containment (design D6)
 */
test('var_export never appears in app/', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (str_contains($source, 'var_export(')) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], sprintf(
        "var_export() ignores __debugInfo() and dumps private properties too — never call it on a value that may hold an LlmBinding:\n  - %s",
        implode("\n  - ", $violations)
    ));
});
