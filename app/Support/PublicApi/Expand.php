<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Exceptions\PublicApi\InvalidExpandException;
use Illuminate\Http\Request;

/**
 * Parses `?expand=` on the BEAI Public API (`/v1`) — SPEC.md §3.2
 * "Expansion: `?expand=project` on interview reads to inline the project."
 *
 * Comma-separated, max 3 names (SPEC.md §3.2 mirrors the same "max 3" cap it
 * states for `metadata[key]=value` filters — this class enforces its own
 * copy of that number rather than sharing a constant with
 * `App\Rules\PublicApi\Metadata`: the two limits happen to have the same
 * VALUE today but govern unrelated concepts — one is expandable relation
 * names, the other is metadata filter keys — and are free to diverge later).
 * Every name must be in the caller's own `$allowed` list — there is no
 * global allow-list, because which names are expandable differs per
 * endpoint (only `project` exists today, on interview reads).
 */
final class Expand
{
    private const MAX_NAMES = 3;

    /**
     * @param  list<string>  $allowed
     * @return list<string> de-duplicated, in the order first seen
     *
     * @throws InvalidExpandException when more than 3 names are given, or
     *                                any name is not in `$allowed`.
     */
    public static function parse(Request $request, array $allowed): array
    {
        $raw = $request->query('expand');

        if ($raw === null || $raw === '') {
            return [];
        }

        if (! is_string($raw)) {
            throw new InvalidExpandException('expand must be a comma-separated string.');
        }

        $names = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $name): bool => $name !== '',
        )));

        if (count($names) > self::MAX_NAMES) {
            throw new InvalidExpandException(sprintf('expand accepts at most %d names.', self::MAX_NAMES));
        }

        foreach ($names as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new InvalidExpandException(sprintf("expand does not recognise '%s'.", $name));
            }
        }

        return $names;
    }
}
