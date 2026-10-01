<?php

declare(strict_types=1);

/**
 * Architecture guard: which files may read the request key `link_token`.
 *
 * `bootstrap/app.php` excepts that KEY from the global `TrimStrings`
 * middleware, because a link token is a secret and a secret has exactly one
 * spelling: `<token>\n` must be the generic 404, never the valid token. The
 * exception is by key and therefore global. Any file that starts reading a
 * request key named `link_token` would silently inherit "never trimmed", which
 * is a trap for the next endpoint that reuses the name, so a new reader has to
 * be a deliberate, reviewed decision: add it to the allowlist below and say why
 * in the commit.
 *
 * The scan looks at PHP string LITERALS (the tokenizer, not a text search), so
 * a comment that merely mentions the key cannot trip it and a real read cannot
 * hide in one.
 *
 * REQ: Identity Input Is Trimmed And The Email Is Normalized
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

/**
 * The files allowed to carry the key, relative to the application root.
 */
const LINK_TOKEN_KEY_ALLOWLIST = [
    'app/Http/Controllers/Sso/ReusableLinkRedeemController.php',
    'app/Actions/ReusableLinks/RedeemReusableInterviewLink.php',
    'app/Services/ReusableLinkTokenGenerator.php',
    'app/Providers/AppServiceProvider.php',
    'bootstrap/app.php',
    'routes/api.php',
];

/**
 * The files, among `$sources` (path => PHP source), that hold a string literal
 * equal to `link_token` and are not allowed to.
 *
 * @param  array<string, string>  $sources
 * @param  list<string>  $allowed
 * @return list<string>
 */
function linkTokenKeyOffenders(array $sources, array $allowed): array
{
    $offenders = [];

    foreach ($sources as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === 'link_token') {
                $offenders[] = $path;

                break;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

/**
 * Every PHP file under the scanned roots, path => source.
 *
 * @return array<string, string>
 */
function linkTokenKeyScannedSources(): array
{
    $sources = [];

    foreach (['app', 'routes', 'bootstrap'] as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
                $sources[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $sources;
}

test('the detector flags a string literal of the key and ignores a comment and a different key', function (): void {
    $sources = [
        'app/Http/Controllers/NewReader.php' => '<?php $request->post(\'link_token\');',
        'app/Http/Controllers/DoubleQuoted.php' => '<?php $request->input("link_token");',
        'app/Http/Controllers/OnlyAComment.php' => '<?php // reads link_token somewhere else',
        'app/Http/Controllers/OtherKey.php' => '<?php $request->post(\'link_token_hint\');',
    ];

    expect(linkTokenKeyOffenders($sources, []))->toBe([
        'app/Http/Controllers/DoubleQuoted.php',
        'app/Http/Controllers/NewReader.php',
    ])->and(linkTokenKeyOffenders($sources, ['app/Http/Controllers/NewReader.php']))->toBe([
        'app/Http/Controllers/DoubleQuoted.php',
    ]);
});

test('only the allowlisted files read the link_token key', function (): void {
    $offenders = linkTokenKeyOffenders(linkTokenKeyScannedSources(), LINK_TOKEN_KEY_ALLOWLIST);

    expect($offenders)->toBe([], sprintf(
        "These files read a request key named `link_token`: %s.\n".
        'bootstrap/app.php excepts that key from TrimStrings (a link token has exactly one spelling), and the exception is global, so a new reader silently inherits "never trimmed". If the read is deliberate, add the file to LINK_TOKEN_KEY_ALLOWLIST in tests/Arch/ReusableLinks/LinkTokenKeyArchTest.php and explain why; otherwise use another key name.',
        implode(', ', $offenders),
    ));
});

test('every allowlisted file still exists, so the allowlist cannot silently go stale', function (): void {
    $missing = array_values(array_filter(
        LINK_TOKEN_KEY_ALLOWLIST,
        fn (string $path): bool => ! is_file(base_path($path)),
    ));

    expect($missing)->toBe([]);
});
