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
 * REQ: conversation-llm "Org credentials are encrypted at rest and never
 *      leave the API as plaintext"
 */
test('no business logic reads llm_credentials through the raw query builder', function (): void {
    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (str_contains($source, "DB::table('llm_credentials')")) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], sprintf(
        "llm_credentials must only be read through the Eloquent model — a raw builder read returns ciphertext:\n  - %s",
        implode("\n  - ", $violations)
    ));
});
