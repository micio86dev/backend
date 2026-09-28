# Design: Strict Types Everywhere

## Technical Approach

### D1 — The declaration

Mechanical, one line per file, placed identically to every other file in this codebase:

```php
<?php

declare(strict_types=1);

namespace App\...;
```

No other change to these 9 files unless PHPStan (already run at level 8 as part of
verification) or the full test suite surfaces a genuine coercion-dependent call site —
in which case that is reported, not silently patched, per proposal.md's "Out of scope".

### D2 — Arch-test guard

New file `tests/Arch/StrictTypesArchTest.php`, following the glob+`file_get_contents`+
`str_contains` convention `ScoringFormulaIsolationTest.php`'s own docblock names as this
repo's established pattern (no `pest-plugin-arch` dependency anywhere in this codebase):

```php
test('every file under app/ declares strict_types', function (): void {
    $files = glob(app_path('**/*.php'), GLOB_BRACE) ?: [];
    // recursive glob via GLOB_BRACE alone does not descend arbitrarily deep in PHP;
    // use a RecursiveDirectoryIterator instead — see FeatureDirectoriesRegisteredArchTest.php's
    // own glob('tests/Feature/**/*.php', GLOB_BRACE) for why a flat glob undercounts,
    // and confirm this test actually walks every subdirectory before trusting it green.
    ...
});
```

**Verified during design, not left to implementation-time discovery**: `glob('app/**/*.php',
GLOB_BRACE)` finds only 125 of the real 516 `.php` files under `app/` in this repo's PHP
version — PHP's glob `**` does not recurse arbitrarily deep. A `RecursiveDirectoryIterator`
(wrapped in `RecursiveIteratorIterator`) correctly finds all 516 (confirmed via a one-off
`php -r` script against this exact tree). The arch test MUST use the recursive-iterator
form, not `glob()` — a `glob()`-based version would silently undercount and could report a
false green while missing un-annotated files outside its shallow reach.

## Why not `pest-plugin-arch`'s built-in convention assertions

Not installed in this repo (verified: not in `composer.json`), and every existing arch
test in `tests/Arch/` already uses the hand-rolled glob/string-scan style instead of it.
Introducing a new testing dependency for one check would be inconsistent with the
established pattern and is out of scope for a change this size.
