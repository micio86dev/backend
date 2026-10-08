<?php

declare(strict_types=1);

/**
 * Architecture guard: every BarsIndicator read on the scoring / interview path
 * MUST be constrained by role_id.
 *
 * Regression origin: scoring once loaded indicators by competency only, so a
 * competency shared by several roles leaked the OTHER roles' indicators into
 * the prompt and the score (fixed in 6087bbd). The invariant is that a read is
 * constrained by `where('role_id', ...)`, `whereNull('role_id')` (the role-less
 * `potential` MTG/LAT rows) or `whereIn('role_id', ...)`.
 *
 * Scope, deliberately narrow: only code that feeds an interview or a score.
 * Catalogue authoring, publish, import/export and the framework read API read
 * indicators by revision, without a role, on purpose, and are NOT scanned.
 * `app/Services/Admin/AdminEvaluationSerializer.php` is scanned by name because
 * it re-reads indicators to render a finished evaluation next to its scores.
 *
 * Pragmatic source scan, not an AST engine (same shape as
 * tests/Arch/C2/TenantModelArchTest.php): a read passes when a role constraint
 * appears between its start and the end of the enclosing method. Limitation: a
 * second, unscoped BarsIndicator query in the SAME method as a scoped one is
 * not caught. Relation reads (`$revision->barsIndicators`) are not matched.
 */

/** Query-builder entry points on the model; create/insert/PK lookups are not reads of a set. */
const BARS_ROLE_SCOPE_ENTRY = 'where|whereIn|whereNull|whereNotNull|whereHas|whereRaw|query|all|with|select|orderBy|pluck|firstWhere|count|exists|has|lazy|cursor|chunk';

/**
 * Return a description of every BarsIndicator read in $source that has no
 * role_id constraint before the end of its enclosing method.
 *
 * @return list<string>
 */
function unscopedBarsIndicatorReads(string $source): array
{
    $starts = [];

    preg_match_all(
        '/BarsIndicator::(?:'.BARS_ROLE_SCOPE_ENTRY.')\b|DB::table\(\s*[\'"]framework_bars_indicators[\'"]/',
        $source,
        $starts,
        PREG_OFFSET_CAPTURE,
    );

    $violations = [];

    foreach ($starts[0] as [$match, $offset]) {
        $rest = substr($source, $offset);
        // Methods close at four-space indent; a bare string fixture has no such close.
        $end = preg_match('/\n    \}\n/', $rest, $close, PREG_OFFSET_CAPTURE) === 1 ? $close[0][1] : strlen($rest);
        $window = substr($rest, 0, $end);

        $scoped = preg_match('/(?:where|whereNull|whereIn)\(\s*[\'"](?:\w+\.)?role_id[\'"]|[\'"]role_id[\'"]\s*=>/', $window) === 1;

        if (! $scoped) {
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $violations[] = "line {$line}: {$match}";
        }
    }

    return $violations;
}

/**
 * Scoring / interview path sources, keyed by repo-relative path.
 *
 * @return array<string, string>
 */
function barsIndicatorRoleScopeScannedSources(): array
{
    $files = [base_path('app/Services/Admin/AdminEvaluationSerializer.php')];

    foreach (['app/Services/Conversation', 'app/Services/Scoring', 'app/Actions/Scoring', 'app/Jobs'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    $sources = [];

    foreach ($files as $path) {
        $sources[str_replace(base_path().'/', '', $path)] = (string) file_get_contents($path);
    }

    return $sources;
}

test('matcher flags a competency-only BarsIndicator read', function (): void {
    $source = "BarsIndicator::where('competency_id', \$x)->orderBy('position')->get();";

    expect(unscopedBarsIndicatorReads($source))->toHaveCount(1);
});

test('matcher flags a competency-only raw table read', function (): void {
    $source = "DB::table('framework_bars_indicators')->where('competency_id', \$x)->get();";

    expect(unscopedBarsIndicatorReads($source))->toHaveCount(1);
});

test('matcher accepts role_id constrained reads', function (string $source): void {
    expect(unscopedBarsIndicatorReads($source))->toBe([]);
})->with([
    'where' => ["BarsIndicator::where('competency_id', \$x)->where('role_id', \$r)->get();"],
    'whereNull (role-less potential)' => ["BarsIndicator::where('competency_id', \$x)->whereNull('role_id')->get();"],
    'whereIn' => ["BarsIndicator::where('competency_id', \$x)->whereIn('role_id', \$roles)->get();"],
    'where array key' => ["BarsIndicator::where(['competency_id' => \$x, 'role_id' => \$r])->get();"],
    'constraint added on a later statement' => ["\$q = BarsIndicator::where('competency_id', \$x);\nif (\$r === null) { \$q->whereNull('role_id'); }\n\$q->get();"],
]);

test('matcher does not borrow a constraint from the next method', function (): void {
    $source = "    public function a() {\n        return BarsIndicator::where('competency_id', 1)->get();\n    }\n\n    public function b() {\n        return \$q->where('role_id', 1);\n    }\n";

    expect(unscopedBarsIndicatorReads($source))->toHaveCount(1);
});

test('every BarsIndicator read on the scoring and interview path is role-scoped', function (): void {
    $violations = [];

    foreach (barsIndicatorRoleScopeScannedSources() as $path => $source) {
        foreach (unscopedBarsIndicatorReads($source) as $violation) {
            $violations[] = "{$path} {$violation}";
        }
    }

    expect($violations)->toBe([]);
});

test('the scan reaches the scoring loader', function (): void {
    expect(barsIndicatorRoleScopeScannedSources())->toHaveKey('app/Services/Conversation/BarsIndicatorLoader.php');
});
