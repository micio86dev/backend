<?php

declare(strict_types=1);

/**
 * Postgres constraint assertions, shared by the catalogue-revision suites.
 *
 * LIVES HERE, NOT IN `tests/Pest.php`, and the move was a review-gate finding.
 * The original placement reasoned that `Pest.php` is the bootstrap so every
 * ParaTest worker loads it, which is true — and beside the point. The
 * convention says a helper used by more than one test file goes in
 * `tests/Helpers/` and is registered in `composer.json`'s
 * `autoload-dev.files`, the directory already held five such files, and "it
 * happens to work from somewhere else" is exactly how a convention stops
 * being one.
 */

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;

/**
 * Assert a callback fails with the EXACT Postgres constraint the test exists
 * to prove — never a bare `->toThrow(QueryException::class)` /
 * `->toThrow(Exception::class)`, which passes just as happily against a
 * missing table, a typo'd column, or any OTHER constraint as against the
 * specific one under test (framework-catalogue-authoring PR1 review-gate
 * fix — `rules.design`: "Assert specific failure modes, not bare exception
 * classes").
 *
 * `$sqlstate` is the Postgres error class: '23505' unique violation, '23503'
 * foreign-key violation, '23514' CHECK violation. `Illuminate\Database\
 * QueryException::$code` carries it verbatim (set from the underlying
 * PDOException in `QueryException::__construct()`). `$constraintName` is
 * asserted against the driver message, where Postgres always names the
 * exact constraint it refused.
 *
 * Shared here (not duplicated per test file, unlike the migration-count
 * constants elsewhere in this suite) because `tests/Pest.php` is guaranteed
 * to load before every test file — a genuine bootstrap concern, not a
 * load-order risk.
 */
function assertPostgresConstraintViolation(callable $operation, string $sqlstate, string $constraintName): void
{
    try {
        $operation();
    } catch (QueryException $e) {
        // PHPUnit's Assert directly, not expect()->toContain() — that
        // method's signature is `toContain(mixed ...$needles)`, a
        // VARIADIC list of things to find, not `(needle, message)`; passing
        // a custom message as the second argument turns it into a SECOND
        // needle that (correctly) never matches, which is the exact kind of
        // bare/loose assertion this helper exists to replace.
        Assert::assertSame(
            $sqlstate,
            $e->getCode(),
            "Expected SQLSTATE {$sqlstate} (constraint \"{$constraintName}\"), got {$e->getCode()}: {$e->getMessage()}"
        );
        Assert::assertStringContainsString(
            $constraintName,
            $e->getMessage(),
            "Expected the driver message to name constraint \"{$constraintName}\", got: {$e->getMessage()}"
        );

        return;
    }

    throw new Exception(
        "Expected a QueryException with SQLSTATE {$sqlstate} naming constraint \"{$constraintName}\" — none was thrown."
    );
}
