<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

use RuntimeException;

/**
 * Thrown by `App\Support\PublicApi\CursorPage` when a `?cursor=` value is
 * missing its HMAC signature, carries a signature that does not match, or
 * cannot be decoded into a `{created_at}|{id}` pair at all (public-api step
 * 3, SPEC.md §3.2 pagination). Mapped by
 * `App\Support\PublicApi\PublicApiExceptionRenderer` to `400 invalid_cursor`.
 *
 * G-28 (resolved step 3 review follow-up): a malformed REQUEST PARAMETER
 * (e.g. `?limit=`) is ALSO `400` today — it is raised as
 * `App\Exceptions\PublicApi\QueryValidationException`, not this class,
 * because a bad `?limit=` really did fail a validation RULE (`min`/`max`/
 * `integer`) with a per-field `errors[]` entry to report, which an opaque,
 * hand-unconstructable cursor token has no equivalent of — the two classes
 * stay separate for that reason, even though they now render the SAME
 * status.
 */
final class InvalidCursorException extends RuntimeException {}
