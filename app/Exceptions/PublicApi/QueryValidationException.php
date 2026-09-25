<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

use Illuminate\Validation\ValidationException;

/**
 * A `ValidationException` raised from a QUERY-PARAMETER check on the BEAI
 * Public API (`/v1`) — `?limit=`, `?cursor=`, `?expand=`, `metadata[…]`
 * filters (SPEC.md §3.2) — as opposed to a REQUEST-BODY field.
 *
 * G-28 (documented judgement call, resolved step 3 review follow-up,
 * item 13): the contract reserves `422` for request-BODY validation and
 * `400` for list-operation query errors; the original step 3 implementation
 * mapped `CursorPage::resolveLimit()`'s `?limit=` failure through the
 * plain, un-tagged `ValidationException` `App\Support\PublicApi\
 * PublicApiExceptionRenderer` already maps to `422` for bodies, so `?limit=`
 * answered `422` too. This subclass carries no new behaviour — it exists
 * purely so the renderer's `match(true)` can distinguish "this
 * ValidationException came from a query parameter" from "this one came from
 * a request body" and map each to its OWN contract status, without the two
 * call sites (query-parameter helpers vs. `$request->validate()`) agreeing
 * on some other out-of-band signal.
 *
 * `InvalidCursorException`/`InvalidExpandException` already answer `400`
 * and are UNCHANGED by this class — they are not `ValidationException`s at
 * all (an opaque cursor/expand token is not "a request parameter that
 * failed a validation rule", see `InvalidCursorException`'s own docblock).
 */
final class QueryValidationException extends ValidationException {}
