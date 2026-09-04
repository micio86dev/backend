<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Log context for a database exception, with the bound values withheld.
 *
 * A QueryException's `getMessage()` MUST NOT be logged on any path that writes
 * candidate content. `QueryException::formatMessage()` builds that string as
 * `$previous->getMessage() . ' (Connection: ..., SQL: ' . Str::replaceArray('?', $bindings, $sql) . ')'`
 * — every bound value is interpolated straight into it. On the utterance paths
 * the bindings are the candidate's verbatim speech, and GDPR is in this
 * product's binding NFR list.
 *
 * The bindings are NOT in the trace, which is where this reasoning first put
 * them. The driver message reached through `getPrevious()` carries the SQLSTATE
 * and the failure without the interpolated SQL — but not unconditionally
 * without VALUES: PostgreSQL appends a `DETAIL:` line to NOT NULL and CHECK
 * violations ("Failing row contains (...)") and to unique violations
 * ("Key (col)=(value) already exists"), and on these paths that row is the
 * candidate's speech. Only the FIRST LINE is therefore kept: Postgres puts the
 * primary message there and every value-echoing section — DETAIL, HINT, CONTEXT
 * — on subsequent lines. Matching on the label instead would be a blocklist
 * against a LOCALIZED string: `lc_messages = it_IT` emits `DETTAGLIO:`, the
 * match misses, and the failing row is logged in full. On a product with a
 * binding it/en mandate that is not a hypothetical setting.
 */
final class SafeDbContext
{
    /**
     * @return array{exception: class-string<Throwable>, message: string}
     */
    public static function for(Throwable $e): array
    {
        $message = $e instanceof QueryException
            ? self::firstLineOnly($e->getPrevious()?->getMessage())
            : $e->getMessage();

        return [
            'exception' => $e::class,
            // Empty counts as absent. The fallback exists so a log line is never
            // blank, and a driver that returns '' is exactly as unhelpful as one
            // that returns nothing.
            'message' => ($message === null || $message === '') ? '(no driver message)' : $message,
        ];
    }

    /**
     * Keep only the first line, where PostgreSQL puts the primary message.
     *
     * Everything that can echo a value — DETAIL, HINT, CONTEXT — is on a later
     * line, so this is an allowlist rather than a blocklist, and it holds under
     * any `lc_messages`.
     */
    private static function firstLineOnly(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        // explode(), not strtok(): strtok leaves global tokenizer state behind
        // and returns false on an empty subject, which then needs special-casing.
        return rtrim(explode("\n", $message, 2)[0]);
    }
}
