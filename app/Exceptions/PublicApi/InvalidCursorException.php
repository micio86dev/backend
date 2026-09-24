<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

use RuntimeException;

/**
 * Thrown by `App\Support\PublicApi\CursorPage` when a `?cursor=` value is
 * missing its HMAC signature, carries a signature that does not match, or
 * cannot be decoded into a `{created_at}|{id}` pair at all (public-api step
 * 3, SPEC.md §3.2 pagination). Mapped by
 * `App\Support\PublicApi\PublicApiExceptionRenderer` to `400 invalid_cursor`
 * — never `422 validation_failed`, which is reserved for a malformed
 * REQUEST PARAMETER (e.g. `?limit=`); a cursor is an opaque token the
 * client never constructs by hand, so a bad one is closer to "this token is
 * not valid" than "this input failed a validation rule".
 */
final class InvalidCursorException extends RuntimeException {}
