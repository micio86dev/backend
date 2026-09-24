<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Exceptions\PublicApi\InvalidCursorException;
use App\Exceptions\PublicApi\InvalidExpandException;
use App\Exceptions\PublicApi\QueryValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps every uncaught exception on the BEAI Public API (`/v1`) surface to an
 * `application/problem+json` body (SPEC.md §3.2 "Errors") — public-api step
 * 3. `bootstrap/app.php` delegates its single `Throwable`-wide
 * `$exceptions->render()` callback to `render()` below so the mapping is a
 * plain, unit-testable static method rather than logic buried inside a
 * closure.
 *
 * Scoped to `api/v1/*` only: `render()` returns `null` for anything else,
 * which tells Laravel's exception handler "not mine, try the next
 * renderer" — every existing `/api/*` (backoffice) exception render in
 * `bootstrap/app.php` is untouched by this class. It also returns `null`
 * for `HttpResponseException` (review follow-up 9, see the `render()`
 * docblock below) for the SAME "not mine" reason.
 *
 * Mapping (documented judgement calls, not contract entries — the contract
 * only fixes the `ErrorCode` enum and the `Problem` shape, not which PHP
 * exception produces which code):
 *
 *   - `HttpResponseException`     → passthrough, see `render()` below.
 *   - `QueryValidationException` (a `ValidationException` subtype) → `400
 *     validation_failed` (G-28, resolved step 3 review follow-up, item 13):
 *     a malformed QUERY PARAMETER (`?limit=`, and future `?cursor=`/
 *     `?expand=`/`metadata[…]` query filters) is a list-operation error per
 *     the contract, not a request-body one. MUST be matched BEFORE the
 *     plain `ValidationException` case below (`match(true)` takes the
 *     first arm that matches, and this is a subtype of it).
 *   - `ValidationException`      → 422 `validation_failed`, `errors[]` built
 *     from `$validator->failed()` (field + snake_case rule name), except a
 *     `metadata`/`metadata.*` field, which always reports
 *     `metadata_limit_exceeded` regardless of which sub-check inside
 *     `App\Rules\PublicApi\Metadata` failed (task Part B item 6 — "keep it
 *     simple"). This branch is now REQUEST-BODY validation only.
 *   - `AuthenticationException`  → 401 `invalid_api_key` + `WWW-Authenticate:
 *     Bearer`, same shape `AuthenticatePublicApi::unauthorized()` already
 *     produces for the normal auth-failure path — this is the FALLBACK for
 *     anything that reaches Laravel's own auth layer instead (there is none
 *     today; kept for defence in depth).
 *   - `AuthorizationException` / `AccessDeniedHttpException` → 404
 *     `not_found` (G-31, resolved step 3 review follow-up, item 8): Laravel's
 *     OWN `Illuminate\Foundation\Exceptions\Handler::prepareException()`
 *     converts every `AuthorizationException` with no explicit status to
 *     `AccessDeniedHttpException` BEFORE any render callback — including
 *     this one — ever sees it, so the `AuthorizationException` arm below is
 *     defence in depth only; `AccessDeniedHttpException` is the branch that
 *     actually fires for a real Gate/policy denial. A `403 insufficient_scope`
 *     here would be MISLEADING — that code is reserved for a resolved
 *     client whose `abilities` genuinely lack a named scope (see
 *     `App\Http\Middleware\PublicApi\RequireScope`, which builds that
 *     response directly and never reaches this class at all) — and a bare
 *     403 on a policy denial would also tell an unauthorized caller a
 *     resource EXISTS at all, the same existence-oracle concern
 *     `NotFoundHttpException`'s own contract description already raises
 *     for a cross-org resource. `404 not_found` costs nothing a legitimate
 *     caller needs and reveals nothing to one that is not.
 *   - `ModelNotFoundException` / `NotFoundHttpException` → 404 `not_found`.
 *   - `MethodNotAllowedHttpException` → 404 `not_found` (G-27, documented
 *     judgement call): the `ErrorCode` enum has no `method_not_allowed`
 *     entry, and a distinct 405 would additionally leak which HTTP methods
 *     a path supports to an unauthenticated or wrongly-scoped caller — the
 *     SAME non-enumeration principle `NotFound`'s own contract description
 *     already states for a cross-org resource. Collapsing it into the
 *     existing `not_found` code costs nothing a real client needs (the
 *     `Allow` header Symfony would otherwise add is dropped for the same
 *     reason) and needs no `openapi.yaml` change.
 *   - `ThrottleRequestsException` / `TooManyRequestsHttpException` → 429
 *     `rate_limited`, `Retry-After` copied from the exception's own headers
 *     when present (Laravel's built-in `throttle` middleware sets it; this
 *     API's own `RateLimitPublicApi` middleware never throws this exception
 *     — it renders its own 429 directly — so this branch is defence in
 *     depth, not the primary path).
 *   - Any other `HttpExceptionInterface` → `not_found` for a bare 404
 *     (covered above already), `internal_error` for 5xx, `validation_failed`
 *     for every other 4xx (G-27 continued): the `ErrorCode` enum has no
 *     generic "bad request" bucket beyond the specific codes already listed
 *     in each endpoint's own contract entry, and every one of those specific
 *     codes is raised as a typed exception/explicit `Problem::make()` call
 *     BEFORE it would ever reach this generic fallback — this branch only
 *     ever sees an unmapped `abort($status)` call, which `validation_failed`
 *     describes reasonably for 4xx and is never reached for a status this
 *     class maps more specifically above. Review follow-up 10: the
 *     exception's OWN headers (e.g. `Retry-After` on a `503` from
 *     maintenance mode) are merged onto the response — they used to be
 *     silently dropped.
 *   - Anything else → 500 `internal_error`, no `detail` (never leak an
 *     internal exception message to a caller). Laravel's own exception
 *     `report()` pipeline — including the Sentry path — runs BEFORE
 *     `render()` regardless of what this method returns, so it is
 *     deliberately not duplicated here.
 */
final class PublicApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/*')) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            // Review follow-up 9: passes through UNTOUCHED. Returning null
            // here tells Laravel's exception handler "not mine" — it then
            // falls through to ITS OWN `Illuminate\Foundation\Exceptions\
            // Handler::render()`, whose own `match(true)` applies
            // `$e->getResponse()` for exactly this type. Mapping it to our
            // generic 500 `internal_error` (the old behaviour, since no
            // arm below matched it) silently discarded whatever response
            // `abort(response()->...)` — or a FormRequest's
            // `failedAuthorization()` — actually built.
            return null;
        }

        return match (true) {
            $e instanceof InvalidCursorException => Problem::make(
                $request, 400, 'invalid_cursor', 'Invalid cursor', $e->getMessage(),
            ),
            $e instanceof InvalidExpandException => Problem::make(
                $request, 400, 'invalid_expand', 'Invalid expand', $e->getMessage(),
            ),
            $e instanceof QueryValidationException => self::validation($e, $request, 400),
            $e instanceof ValidationException => self::validation($e, $request, 422),
            $e instanceof AuthenticationException => Problem::make(
                $request, 401, 'invalid_api_key', 'Invalid API key',
                extraHeaders: ['WWW-Authenticate' => 'Bearer'],
            ),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => Problem::make(
                $request, 404, 'not_found', 'Not found',
            ),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException,
            $e instanceof MethodNotAllowedHttpException => Problem::make(
                $request, 404, 'not_found', 'Not found',
            ),
            $e instanceof TooManyRequestsHttpException => self::rateLimited($e, $request),
            $e instanceof HttpExceptionInterface => self::httpException($e, $request),
            default => Problem::make($request, 500, 'internal_error', 'Internal error'),
        };
    }

    private static function validation(ValidationException $e, Request $request, int $status): JsonResponse
    {
        $errors = [];

        foreach ($e->validator->failed() as $field => $rules) {
            $field = (string) $field;
            $rules = is_array($rules) ? $rules : [];

            foreach (array_keys($rules) as $rule) {
                $errors[] = [
                    'field' => $field,
                    'code' => self::ruleCode($field, (string) $rule),
                ];
            }
        }

        return Problem::make(
            $request, $status, 'validation_failed', 'Validation failed',
            errors: $errors,
        );
    }

    /**
     * @param  string  $rule  the StudlyCase rule name (or, for an
     *                        object-implemented rule, its FQCN) as reported by `Validator::failed()`.
     */
    private static function ruleCode(string $field, string $rule): string
    {
        if ($field === 'metadata' || str_starts_with($field, 'metadata.')) {
            return 'metadata_limit_exceeded';
        }

        $shortName = str_contains($rule, '\\') ? Str::afterLast($rule, '\\') : $rule;

        return Str::snake($shortName);
    }

    private static function rateLimited(TooManyRequestsHttpException $e, Request $request): JsonResponse
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
        $retryAfter = is_string($retryAfter) || is_int($retryAfter) ? (string) $retryAfter : null;

        return Problem::make(
            $request, 429, 'rate_limited', 'Rate limited',
            extraHeaders: $retryAfter !== null ? ['Retry-After' => $retryAfter] : [],
        );
    }

    private static function httpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();

        return match (true) {
            $status >= 500 => Problem::make($request, $status, 'internal_error', 'Internal error', extraHeaders: self::stringHeaders($e)),
            default => Problem::make($request, $status, 'validation_failed', 'Validation failed', extraHeaders: self::stringHeaders($e)),
        };
    }

    /**
     * Review follow-up 10: `$e->getHeaders()` used to be dropped entirely
     * here, silently losing e.g. `Retry-After` on a maintenance-mode `503`.
     * Non-string header values are stringified the same way `rateLimited()`
     * already does for `Retry-After` above, rather than trusted as-is —
     * `HttpExceptionInterface::getHeaders()` is declared `array<string,
     * string>` but is populated by arbitrary caller code (e.g. a bare
     * `abort($status, $message, $headers)` call), so it is narrowed
     * defensively instead.
     *
     * @return array<string, string>
     */
    private static function stringHeaders(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value))) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
