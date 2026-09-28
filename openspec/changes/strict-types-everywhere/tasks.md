# Tasks: Strict Types Everywhere

> One PR, well under the 400-line budget.

- [ ] 1. RED — `tests/Arch/StrictTypesArchTest.php`, using `RecursiveDirectoryIterator`
      (not `glob()` — see design.md D2 for why), asserting every `.php` file under
      `app/` contains `declare(strict_types=1);`. Confirm it fails, listing exactly the
      9 known files.
- [ ] 2. GREEN — add `declare(strict_types=1);` to each of the 9 files, in the standard
      position (design.md D1). Re-run the arch test; confirm it passes.
- [ ] 3. `vendor/bin/pint --dirty --format agent`.
- [ ] 4. `vendor/bin/phpstan analyse --memory-limit=1G` — 0 new errors.
- [x] 5. Full-suite run **blocked by confirmed external environmental contamination**,
      not by this change:
      - First attempt completed (duration_ms 3208743) with 19 failures, all in
        `tests/Feature/C10/Schema/*MigrationTest.php` (`webhook_deliveries` table
        missing/wrong columns/missing indexes) — a domain this change never touches
        (the 9 files are `User`, `Controller`, `TenantContext`, `SecurityHeaders`,
        `AppServiceProvider`, `CreateSuperadmin`, `LLMResponse`, `LLMProvider`,
        `FakeLLMProvider`). Root cause: `.env.testing`/`phpunit.xml` pin a single
        fixed `DB_DATABASE=beai_test`, and 7 concurrent `pest`/`artisan test`
        processes were confirmed running simultaneously (`ps aux`) from sibling
        `.claude/worktrees/agent-*` checkouts — consistent with concurrent
        `RefreshDatabase` migrate/truncate cycles racing against the same Postgres
        database.
      - Second and third attempts (and a targeted single-file rerun) all hit a hard
        PHP fatal instead: `Cannot redeclare function saSuperadmin() (previously
        declared in .../.claude/worktrees/agent-a0ae7017c04ba6d11/tests/Helpers/
        SuperadminFixtures.php:34)`. Traced to root cause, not just observed:
        `vendor/composer/autoload_static.php` line 63 in THIS checkout now points
        `SuperadminFixtures.php` at the sibling worktree's absolute path, while
        `vendor/composer/autoload_files.php` line 62 still correctly points at
        `$baseDir` (this checkout) — Composer's two generated autoload maps
        disagree, so both the correct and the worktree-rooted copy get `require`d
        in the same process, hard-fatal on the second (function, not class,
        declarations can't be re-included safely). This means a sibling fork ran a
        `composer` command that overwrote this checkout's shared
        `vendor/composer/` autoload maps with paths rooted in its own worktree —
        cross-process contamination of shared `vendor/`, unrelated to any file this
        change touches.
      - Did NOT attempt to fix `vendor/composer/autoload_static.php` myself: it is
        shared, sibling-fork work may still be actively using it, and repairing
        cross-cutting shared infra is out of this change's scope.
      - What full verification I DO have, all clean: the arch test's own RED→GREEN
        cycle (task 1/2), `pint --dirty` (0 changes after its one auto-fix, already
        re-verified green), `phpstan analyse --memory-limit=1G` (0 errors). Given the
        nature of the change (a `declare()` line added to 9 files with zero logic
        changes) and that PHPStan level 8 already re-analyzed all 9 files clean, the
        residual risk of an undetected regression is low, but the 85%-floor
        full-suite proof this project's own convention requires is genuinely
        outstanding — report this honestly rather than claim it.
- [x] 6. Commit as one work unit on `feature/strict-types-everywhere`. Conventional
      Commit, no AI attribution.
