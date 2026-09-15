<?php

declare(strict_types=1);
use PHPUnit\Framework\Assert;

/**
 * RED/GREEN — 10.6 (framework-catalogue-authoring PR3, D12): the generated
 * `openapi.json` declares 403 on EVERY catalogue-write operation. Fails the
 * next helper-extraction that buries `abort_unless` behind a shared method —
 * Scramble infers responses from what a controller VISIBLY does, and does
 * not follow a call into a private helper (see `PlatformUserController`'s
 * own docblock and design D12).
 *
 * Reads the COMMITTED `openapi.json`, not a fresh export — this test is the
 * same shape as `ResourceMatchesSpecTest`'s "compare to the committed spec"
 * doctrine; a stale spec is caught by the API Verification Commands' own
 * fresh-export diff, not re-derived here.
 */
test('every catalogue-write operation declares 403 in the committed openapi.json', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true);

    expect($spec)->toBeArray();

    $writeMethods = ['post', 'patch', 'put', 'delete'];
    $checked = [];

    foreach ($spec['paths'] ?? [] as $path => $operations) {
        if (! str_starts_with($path, '/catalogue/')) {
            continue;
        }

        foreach ($operations as $method => $operation) {
            if (! in_array($method, $writeMethods, true)) {
                continue;
            }

            $checked[] = "{$method} {$path}";
            // `array_map('strval', ...)`: json_decode(..., true) silently
            // casts a numeric-looking JSON object key ("403") to a PHP
            // integer array key (PHP's own array-key coercion, not a JSON
            // property) — `array_keys()` then returns int 403, and PHPUnit's
            // `assertContains('403', ...)` does not loosely match an int
            // against that string in this PHPUnit version.
            $responses = array_map('strval', array_keys($operation['responses'] ?? []));

            // `toContain(...$needles)` is VARIADIC, not `(needle, message)` —
            // a second string argument becomes a SECOND needle to search
            // for, which then correctly never matches
            // (`tests/Helpers/PostgresConstraintAssertions.php`'s own
            // docblock names this exact gotcha). PHPUnit's Assert directly,
            // with the operation named in the message.
            Assert::assertContains(
                '403',
                $responses,
                "{$method} {$path} must declare a 403 response in openapi.json"
            );
        }
    }

    // The contract itself: there must be at least one catalogue-write
    // operation to check, or this test would pass vacuously against an
    // empty/stale export.
    expect($checked)->not->toBeEmpty('no catalogue-write operations found under /catalogue/ in openapi.json — export is stale or routes are missing');
});
