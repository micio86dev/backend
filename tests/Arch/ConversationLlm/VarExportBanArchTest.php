<?php

declare(strict_types=1);

/**
 * Architecture guard: `var_export(` and `print_r(` appear nowhere in `app/`
 * (pluggable-conversation-llm PR P3a, design D6, Gate Correction C1;
 * `print_r` ban added closing an adversarial-review gap — `LlmBinding.php`'s
 * own docblock already disclosed `print_r()` as an equally real leak vector,
 * yet only `var_export` was banned).
 *
 * Both functions IGNORE `__debugInfo()` entirely and dump public AND
 * PRIVATE properties — `LlmBinding::$apiKey`'s redacting `__debugInfo()`
 * does nothing against either, and visibility is not a mitigation.
 *
 * HONEST LIMIT: this is a literal string scan, not a containment boundary.
 * A determined caller can defeat it trivially — assign the function name to
 * a variable, call it dynamically, or go through `call_user_func()` — and
 * this test would see nothing. It exists to catch the ACCIDENTAL debug
 * dump left in during development, not a determined exfiltration attempt.
 * The real containment is `LlmBinding`'s readonly DTO shape plus its
 * redacting `__debugInfo()`, and — one layer down, for credentials at
 * rest — `LlmCredential`'s `'encrypted'` cast and `$hidden`.
 *
 * REQ: conversation-llm — LlmBinding containment (design D6)
 */
test('var_export and print_r never appear in app/', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (str_contains($source, 'var_export(') || str_contains($source, 'print_r(')) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], sprintf(
        "var_export() and print_r() both ignore __debugInfo() and dump private properties too — never call either on a value that may hold an LlmBinding:\n  - %s",
        implode("\n  - ", $violations)
    ));
});
