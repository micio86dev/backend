<?php

declare(strict_types=1);

/**
 * Architecture guard: no business logic reads `llm_credentials` through the
 * raw query builder (pluggable-conversation-llm PR P2, design D2).
 *
 * `DB::table('llm_credentials')` returns CIPHERTEXT — `api_key`'s
 * `'encrypted'` cast only decrypts on an Eloquent read. A raw-builder read
 * would be POSTed to Tavus verbatim and produce a 401 nobody could explain by
 * reading the code. Enforced by construction, not by discipline — the same
 * shape as `tests/Feature/C7a/ProviderSecretTest.php`'s ai_requests guard.
 *
 * Also matches `DB::connection(...)->table('llm_credentials')` — the same
 * raw-builder read, just reached through an explicit connection name first
 * (closing an adversarial-review gap: the original ban only matched the
 * default-connection call shape).
 *
 * HONEST LIMIT: this is a literal/pattern string scan, not a containment
 * boundary. A table name built into a variable first (`DB::table($table)`)
 * defeats it completely, and no string scan can fix that. The real
 * containment is the model's `'encrypted'` cast + `$hidden`; this guard
 * exists to catch the ACCIDENTAL raw read, not a determined one.
 *
 * REQ: conversation-llm "Org credentials are encrypted at rest and never
 *      leave the API as plaintext"
 */
test('no business logic reads llm_credentials through the raw query builder', function (): void {
    $violations = [];

    // Both quote styles for the direct-connection call, plus the same
    // pattern reached through an explicit `DB::connection(...)` first (any
    // connection name, any quote style around it).
    $connectionPattern = '/DB::connection\([^)]*\)\s*->\s*table\(\s*[\'"]llm_credentials[\'"]\s*\)/';

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        // Both quote styles: a single-quoted needle alone let the identical
        // double-quoted call through, which is the same defect wearing a hat.
        // The table name is still matched literally — a name built into a
        // variable first would evade this, and no string scan can fix that.
        // The real containment is the model's 'encrypted' cast + $hidden;
        // this guard exists to catch the accidental raw read, not a determined one.
        if (str_contains($source, "DB::table('llm_credentials')")
            || str_contains($source, 'DB::table("llm_credentials")')
            || preg_match($connectionPattern, $source) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], sprintf(
        "llm_credentials must only be read through the Eloquent model — a raw builder read returns ciphertext:\n  - %s",
        implode("\n  - ", $violations)
    ));
});
