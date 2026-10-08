<?php

declare(strict_types=1);

/**
 * Architecture guard: no NEW read of `->organization_id` on a user under `app/Http`.
 *
 * `users.organization_id` is the caller's HOME organization, and it is NULL for the one identity that matters here:
 * a superadmin, who works as a client through the acting organization that `TenantContext` writes to
 * `TenantResolver`. A controller, request or middleware that scopes a query, a validation rule or a created row
 * from that column therefore answers for nobody for that identity (404, 403, 422, an empty list, a silently
 * mis-scoped slug check). The repair (`c9096df`) moved those reads to `TenantResolver::getOrgId()`; this test is
 * what stops the next one from putting the column back.
 *
 * What it inspects: PHP CODE tokens only (comments and docblocks are dropped, because the repaired sites keep a
 * comment saying "never `$user->organization_id`"), in every file under `app/Http`, for a read or write of
 * `->organization_id` / `?->organization_id` whose receiver looks like a user: a variable whose name contains
 * `user`, `$request->user()`, `auth()->user()` or `Auth::user()`.
 *
 * What it does not inspect, deliberately: `app/Providers`, queued jobs and Console commands. They run outside the
 * request tenant path (a worker or a console has no acting organization), and their reads of the column are about a
 * specific subject user, not about "the organization this request operates in".
 *
 * KNOWN LIMIT, stated rather than hidden: a user aliased to a name without `user` in it
 * (`$u = $request->user(); $u->organization_id`) evades the pattern. The allowlist below is an occurrence BUDGET per
 * file, not a free pass: a file listed there fails when it gains a read.
 */

/**
 * Files under `app/Http` allowed to touch the column on a user, with the number of occurrences and the reason.
 * Adding a line here is a reviewable act.
 *
 * @var array<string, array{count: int, reason: string}>
 */
const AMBIENT_ORG_READ_ALLOWLIST = [
    'Middleware/TenantContext.php' => [
        'count' => 1,
        'reason' => 'The source of truth the resolver is built from: it reads the column once, then falls back to the acting organization.',
    ],
    'Controllers/Auth/ResetPasswordController.php' => [
        'count' => 2,
        'reason' => 'Unauthenticated route: the organization of the SUBJECT of the reset (to pick its team for role lookups), not an actor context.',
    ],
    'Controllers/Api/PlatformUserController.php' => [
        'count' => 1,
        'reason' => 'A WRITE of organization_id = null that defines a platform user; it reads nothing about the request tenant.',
    ],
];

/**
 * Every `->organization_id` access on a user-looking receiver, as source lines, ignoring comments.
 *
 * @return list<int>
 */
function ambientOrganizationReadLines(string $source): array
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($token[1], "\n"))
                : $token[1];
        } else {
            $code .= $token;
        }
    }

    $receiver = '(?:\$\w*[uU]ser\w*|user\(\)|(?:\\\\?Auth)::user\(\)|auth\([^)]*\)->user\(\))';
    $matched = preg_match_all(
        '/'.$receiver.'\s*\??->\s*organization_id\b/',
        $code,
        $matches,
        PREG_OFFSET_CAPTURE,
    );

    if ($matched === 0 || $matched === false) {
        return [];
    }

    return array_map(
        static fn (array $m): int => substr_count(substr($code, 0, $m[1]), "\n") + 1,
        $matches[0],
    );
}

test('the matcher flags every user receiver spelling and ignores comments and other receivers', function (): void {
    $violations = [
        '<?php $x = $user->organization_id;',
        '<?php $x = $request->user()->organization_id;',
        '<?php $x = $request->user()?->organization_id;',
        '<?php $x = auth()->user()->organization_id;',
        '<?php $x = Auth::user()->organization_id;',
        '<?php $x = $targetUser->organization_id;',
        '<?php $user->organization_id = null;',
        "<?php \$x = \$user\n    ->organization_id;",
    ];

    foreach ($violations as $source) {
        expect(ambientOrganizationReadLines($source))->toHaveCount(1, $source);
    }

    $clean = [
        '<?php // never $user->organization_id',
        "<?php /**\n * not \$user->organization_id\n */\n\$x = 1;",
        '<?php $x = $client->organization_id;',
        '<?php $x = $project->organization_id;',
        '<?php $x = app(TenantResolver::class)->getOrgId();',
        '<?php $x = $user->organization;',
        '<?php $x = $user->organization_idx;',
    ];

    foreach ($clean as $source) {
        expect(ambientOrganizationReadLines($source))->toBe([], $source);
    }
});

test('the matcher reports the line of the violation', function (): void {
    $source = "<?php\n\n\$a = 1;\n\$org = \$request->user()->organization_id;\n";

    expect(ambientOrganizationReadLines($source))->toBe([4]);
});

test('no file under app/Http reads organization_id from a user beyond its allowlisted budget', function (): void {
    $root = app_path('Http');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $found = [];
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
        $lines = ambientOrganizationReadLines((string) file_get_contents($file->getPathname()));

        if ($lines !== []) {
            $found[$relative] = $lines;
        }
    }

    $problems = [];
    foreach ($found as $relative => $lines) {
        $budget = AMBIENT_ORG_READ_ALLOWLIST[$relative]['count'] ?? 0;

        if (count($lines) > $budget) {
            $problems[] = sprintf(
                'app/Http/%s reads ->organization_id on a user at line(s) %s (budget %d). Resolve the organization with '
                .'TenantResolver::getOrgId() (and the org.context middleware), or justify the read in the allowlist.',
                $relative,
                implode(', ', $lines),
                $budget,
            );
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

test('every allowlisted file still exists and still uses its whole budget', function (): void {
    // A stale entry is a free pass for the next author of that file: shrink or drop it when the read goes away.
    foreach (AMBIENT_ORG_READ_ALLOWLIST as $relative => $entry) {
        $path = app_path('Http/'.$relative);

        expect(is_file($path))->toBeTrue("{$relative} is allowlisted but does not exist");
        expect(ambientOrganizationReadLines((string) file_get_contents($path)))
            ->toHaveCount($entry['count'], "{$relative}: allowlist budget no longer matches the file ({$entry['reason']})");
    }
});
