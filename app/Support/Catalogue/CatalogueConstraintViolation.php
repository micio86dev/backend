<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use Illuminate\Database\QueryException;

/**
 * Z4 (R3-validate-then-lock-500, REQUIRED BEFORE ARCHIVE): every catalogue
 * write FormRequest checks its own uniqueness/count invariants (4th-indicator
 * count, `position` uniqueness, role/competency `code` uniqueness) BEFORE
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()` takes its lock
 * — a genuine TOCTOU window. Two concurrent stores can both pass the
 * FormRequest's own pre-check (each sees the OTHER's row as not-yet-
 * committed) and then both attempt the write; the DB layer's own backstops
 * (the partial unique indexes, the pair-cap trigger — H6) correctly refuse
 * the loser, but nothing translated that refusal into the 422 the winner's
 * sibling already got — an uncaught `QueryException` propagated to Laravel's
 * default handler as an HTTP 500 instead.
 *
 * This maps the SPECIFIC, already-named constraints/triggers this change
 * ships back to the SAME stable error codes their own FormRequest-layer
 * checks already use, so a losing concurrent request gets the identical 422
 * shape as a sequential one that failed the ordinary way — never a 500.
 * Deliberately an ALLOWLIST, not a blanket "any QueryException is a 422":
 * an unrecognized `QueryException` is a genuine, unexpected failure and must
 * still surface as a 500 for someone to investigate, not be swallowed here.
 */
final class CatalogueConstraintViolation
{
    /**
     * Ordered `[needle, errorCode]` pairs — the needle is matched against the
     * driver's own exception message (Postgres names the exact constraint or
     * the trigger's own `RAISE EXCEPTION` text verbatim), the same technique
     * `OpenDraftRevision::isOneDraftUniqueViolation()` already uses.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const MATCHERS = [
        ['framework_roles_revision_code_unique', 'code_taken'],
        ['framework_competencies_revision_code_unique', 'code_taken'],
        ['framework_bars_indicators_rev_role_comp_position_unique', 'position_taken_for_pair'],
        ['framework_bars_indicators_roleless_position_unique', 'position_taken_for_pair'],
        ['framework_default_questions_rev_competency_position_unique', 'position_taken_for_competency'],
        ['framework_bars_indicators_role_pair_cap', 'bars_indicator_pair_full'],
        ['framework_bars_indicators_roleless_pair_cap', 'bars_indicator_pair_full'],
    ];

    /**
     * @return string|null The stable error code a recognized violation maps
     *                     to, or null when this exception is not one of the
     *                     catalogue's own known constraints — the caller
     *                     MUST rethrow in that case.
     */
    public static function toErrorCode(QueryException $e): ?string
    {
        $message = $e->getMessage();

        foreach (self::MATCHERS as [$needle, $errorCode]) {
            if (str_contains($message, $needle)) {
                return $errorCode;
            }
        }

        return null;
    }
}
