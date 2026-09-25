<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Exceptions\PublicApi\InvalidCursorException;
use App\Exceptions\PublicApi\QueryValidationException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;

/**
 * Cursor-based pagination for the BEAI Public API (`/v1`) — SPEC.md §3.2
 * "Pagination": `?limit=` (default 25, max 100), `?cursor=`, envelope
 * `{ data, next_cursor, has_more }`, stable ordering `created_at desc, id desc`.
 *
 * Stability under inserts: a row inserted AFTER the caller fetched page 1
 * never shifts page 2, because the cursor encodes an absolute
 * `(created_at, id)` position and every page is a `WHERE (created_at, id) <
 * (cursor.created_at, cursor.id)` predicate against that fixed point — never
 * an OFFSET, which a concurrent insert ahead of the window silently shifts.
 *
 * Cursor format: `base64url(hex_hmac_sha256(payload) . '.' . payload)`,
 * `payload = "{created_at, ISO 8601 with microseconds}|{id}"`. The HMAC
 * (keyed on `config('app.key')` — G-29, a documented judgement call: the
 * spec says "HMAC-signed with the app key" without naming a derivation, and
 * this API has no OTHER shared secret already scoped to this purpose)
 * exists only to make a TAMPERED cursor detectable, not to keep the
 * position confidential — a `(created_at, id)` pair on a resource the
 * caller can already list is not a secret. Microsecond precision matters
 * because two rows CAN share a whole-second `created_at` under a fast
 * insert burst; `getDateFormat()` on this project's PostgreSQL connection
 * already preserves microseconds end to end (`'Y-m-d H:i:s.u'`), so this
 * class relies on that rather than re-deriving precision itself.
 */
final class CursorPage
{
    private const DEFAULT_LIMIT = 25;

    private const MIN_LIMIT = 1;

    private const MAX_LIMIT = 100;

    /**
     * ISO 8601 with explicit microseconds — the cursor's own encoded shape
     * (SPEC.md §3.2 "Timestamps: ISO 8601 UTC with `Z`"). Never used to bind
     * the WHERE clause directly — `decodeCursor()` re-parses it into a
     * `Carbon` instance and reformats via `DB_BINDING_FORMAT` below, which
     * is what this connection's own timestamp columns actually compare
     * against.
     */
    private const CURSOR_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private const DB_BINDING_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * `$mapItem` is deliberately typed plain `callable`, not a generic
     * `callable(TModel): mixed` tied to `$query`'s own model — PHPStan
     * treats a callable typed to a NARROWER model (e.g. `fn (ApiClient
     * $c): array => …`, every real caller's shape) as unsound to pass
     * wherever a `callable(Model): mixed` is expected (contravariance), even
     * though it is exactly what every caller here needs and PHP itself
     * allows at runtime.
     *
     * `@template TModel of Model` + `Builder<TModel>` (public-api step 4,
     * first real caller checked at `phpstan --level=max`): PHPStan's
     * `Builder<TModel>` template is NOT covariant, so a plain, non-generic
     * `Builder<Model>` parameter would reject every real caller's
     * `Builder<Project>`/`Builder<ApiClient>` outright — this method binds
     * `TModel` to whatever concrete model the caller's own query already
     * carries instead, which is both sound (this method only ever reads
     * `created_at`/the primary key, columns every `Model` has) and correct
     * (the caller's own `Builder<TModel>` return type is preserved, not
     * widened to `Builder<Model>`).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  UNORDERED — this method applies its own `created_at desc, id desc` order and must own it entirely.
     * @return array{data: list<mixed>, next_cursor: string|null, has_more: bool}
     *
     * @throws QueryValidationException when `?limit=` is present and out of `[1, 100]` or non-integer (G-28: renders 400, not 422 — a QUERY parameter, not a request body).
     * @throws InvalidCursorException when `?cursor=` is present and unsigned, mistampered, or malformed.
     */
    public static function paginate(Builder $query, Request $request, callable $mapItem): array
    {
        $limit = self::resolveLimit($request);
        $cursor = self::decodeCursor($request->query('cursor'));

        $scoped = (clone $query)->reorder()->orderByDesc('created_at')->orderByDesc('id');

        if ($cursor !== null) {
            [$createdAt, $id] = $cursor;
            $bound = $createdAt->format(self::DB_BINDING_FORMAT);

            $scoped->where(function (Builder $outer) use ($bound, $id): void {
                $outer->where('created_at', '<', $bound)
                    ->orWhere(function (Builder $inner) use ($bound, $id): void {
                        $inner->where('created_at', '=', $bound)->where('id', '<', $id);
                    });
            });
        }

        $rows = $scoped->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $nextCursor = null;
        $last = $page->last();

        if ($hasMore && $last !== null) {
            $nextCursor = self::encodeCursor($last);
        }

        return [
            'data' => array_values(array_map($mapItem, $page->all())),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * The SAME cursor mechanics as `paginate()` — signed `($column, id)`
     * position, `?limit=`/`?cursor=` validation, the `{data, next_cursor,
     * has_more}` envelope — with the comparison REVERSED: oldest first
     * (`$column asc, id asc`, `WHERE ($column, id) > cursor`). A SEPARATE
     * method rather than a parameter on `paginate()` (public-api step 6,
     * G-12): `GET /v1/interviews/{id}/events` is "the one documented
     * exception" to this API's `created_at desc` convention (SPEC.md
     * §3.2/§3.3) — every other list endpoint must never accept an
     * `ascending` flag by accident, and a boolean parameter on the shared
     * method is exactly the kind of one-caller escape hatch that risks
     * leaking to a second one later.
     *
     * `$column` (default `created_at`, matching `paginate()`'s own fixed
     * choice): `InterviewEvent`'s own ordering column is `occurred_at` —
     * its LOGICAL timestamp, which the events table's own migration
     * documents as possibly PREDATING `created_at` for a queued writer —
     * never the physical insert time `paginate()` everywhere else orders
     * by. Parameterized rather than hardcoded to `occurred_at` so this
     * method stays correct for any future ascending list that genuinely
     * does want `created_at`.
     *
     * Cursor STABILITY under inserts is identical in both directions: a row
     * inserted after the caller fetched page 1 never shifts page 2, because
     * both directions fix the boundary at an absolute `($column, id)`
     * position, never an OFFSET.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  UNORDERED — this method applies its own `$column asc, id asc` order and must own it entirely.
     * @return array{data: list<mixed>, next_cursor: string|null, has_more: bool}
     *
     * @throws QueryValidationException when `?limit=` is present and out of `[1, 100]` or non-integer.
     * @throws InvalidCursorException when `?cursor=` is present and unsigned, mistampered, or malformed.
     */
    public static function paginateAscending(Builder $query, Request $request, callable $mapItem, string $column = 'created_at'): array
    {
        $limit = self::resolveLimit($request);
        $cursor = self::decodeCursor($request->query('cursor'));

        $scoped = (clone $query)->reorder()->orderBy($column)->orderBy('id');

        if ($cursor !== null) {
            [$boundary, $id] = $cursor;
            $bound = $boundary->format(self::DB_BINDING_FORMAT);

            $scoped->where(function (Builder $outer) use ($column, $bound, $id): void {
                $outer->where($column, '>', $bound)
                    ->orWhere(function (Builder $inner) use ($column, $bound, $id): void {
                        $inner->where($column, '=', $bound)->where('id', '>', $id);
                    });
            });
        }

        $rows = $scoped->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $nextCursor = null;
        $last = $page->last();

        if ($hasMore && $last !== null) {
            $nextCursor = self::encodeCursor($last, $column);
        }

        return [
            'data' => array_values(array_map($mapItem, $page->all())),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    private static function resolveLimit(Request $request): int
    {
        $raw = $request->query('limit');

        if ($raw === null) {
            return self::DEFAULT_LIMIT;
        }

        $validator = Validator::make(
            ['limit' => $raw],
            ['limit' => ['required', 'integer', 'min:'.self::MIN_LIMIT, 'max:'.self::MAX_LIMIT]],
        );

        if ($validator->fails()) {
            // G-28: Validator::validate() always throws the plain, base
            // ValidationException — replicated manually here (fails() +
            // throw) so a query-parameter failure carries the tagged
            // QueryValidationException instead, which
            // PublicApiExceptionRenderer maps to 400, not 422.
            throw new QueryValidationException($validator);
        }

        return (int) $raw;
    }

    /**
     * @return array{0: Carbon, 1: int|string}|null
     */
    private static function decodeCursor(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            throw new InvalidCursorException('Cursor must be a string.');
        }

        $decoded = self::base64UrlDecode($raw);

        if ($decoded === null) {
            throw new InvalidCursorException('Cursor is not valid base64url.');
        }

        if (! str_contains($decoded, '.')) {
            throw new InvalidCursorException('Cursor is malformed.');
        }

        [$signature, $payload] = explode('.', $decoded, 2);

        $expected = hash_hmac('sha256', $payload, config()->string('app.key'));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidCursorException('Cursor signature does not match — tampered or foreign cursor.');
        }

        if (! str_contains($payload, '|')) {
            throw new InvalidCursorException('Cursor payload is malformed.');
        }

        [$iso, $id] = explode('|', $payload, 2);

        if ($id === '') {
            throw new InvalidCursorException('Cursor payload is malformed.');
        }

        // Carbon::createFromFormat() is declared `?static` (null on a
        // malformed input) but, empirically, ALSO throws
        // Carbon\Exceptions\InvalidFormatException for some malformed
        // inputs (parsing is a runtime, string-content-dependent path
        // PHPStan's static return type cannot fully describe) — both
        // failure modes are caught here, never left to surface as a bare
        // 500.
        try {
            $createdAt = Carbon::createFromFormat(self::CURSOR_TIMESTAMP_FORMAT, $iso, 'UTC');
        } catch (InvalidArgumentException) {
            throw new InvalidCursorException('Cursor timestamp is malformed.');
        }

        if ($createdAt === null) {
            throw new InvalidCursorException('Cursor timestamp is malformed.');
        }

        return [$createdAt, ctype_digit($id) ? (int) $id : $id];
    }

    /**
     * Review follow-up 11: previously silently fell back to `Carbon::now()`
     * (and, at the call site, to an empty-string id) when `created_at` or
     * the primary key was unusable — encoding a cursor that points at the
     * WRONG row, or no row at all, rather than surfacing the data problem
     * that produced it. A model whose `created_at` is genuinely null (a
     * nullable column) or whose key is neither `int` nor `string` cannot be
     * placed in a `(created_at, id)` cursor at all; it is a configuration
     * error in the caller's query/model, not something to paper over.
     *
     * `$column` (default `created_at`, unchanged for every existing caller
     * of `paginate()`): `paginateAscending()` passes its own ordering
     * column through here so the cursor is built from the SAME column the
     * query is actually ordered and filtered by — see that method's own
     * docblock for why that column may differ from `created_at`.
     *
     * @throws LogicException naming the model class and the unusable column.
     */
    private static function encodeCursor(Model $model, string $column = 'created_at'): string
    {
        $createdAt = $model->getAttribute($column);

        // Step 3 review follow-up: a model casting the column
        // `immutable_datetime` (Evaluation::evaluated_at,
        // FrameworkCatalogRevision::published_at, and several `created_at`
        // columns across the codebase) hands back a `CarbonImmutable`, not
        // `Illuminate\Support\Carbon` — both implement `Carbon\CarbonInterface`
        // (copy()/utc()/format() below only need that interface), so the
        // instanceof check widens to it rather than rejecting a perfectly
        // usable timestamp because of which concrete Carbon subclass cast it.
        $carbon = match (true) {
            $createdAt instanceof CarbonInterface => $createdAt,
            is_string($createdAt) => Carbon::parse($createdAt),
            default => throw new LogicException(sprintf(
                '%s: cannot build a pagination cursor — %s is null or not a usable timestamp.',
                $model::class,
                $column,
            )),
        };

        $id = $model->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(sprintf(
                '%s: cannot build a pagination cursor — the primary key is neither int nor string.',
                $model::class,
            ));
        }

        $payload = $carbon->copy()->utc()->format(self::CURSOR_TIMESTAMP_FORMAT).'|'.$id;
        $signature = hash_hmac('sha256', $payload, config()->string('app.key'));

        return self::base64UrlEncode($signature.'.'.$payload);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = str_pad($value, (int) (4 * ceil(strlen($value) / 4)), '=', STR_PAD_RIGHT);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
