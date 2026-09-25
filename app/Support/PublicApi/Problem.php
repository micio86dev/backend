<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds an RFC 9457 `application/problem+json` error response for the BEAI
 * Public API (`/v1`) — SPEC.md §3.2 "Errors" and `public-api/openapi.yaml`'s
 * `Problem`/`ErrorCode` schemas.
 *
 * The request-id it stamps reuses `public_api.request_id` whenever
 * `App\Http\Middleware\PublicApi\AssignRequestId` (public-api step 3) has
 * already run — which it always does for every real `/v1` route, since it is
 * first in both middleware stacks (`routes/api.php`) — so the header this
 * class sets and the body's `request_id` field are always the SAME value as
 * the one on `X-Request-Id`. The `Str::ulid()` fallback below only fires for
 * a genuinely middleware-free caller (a unit test constructing a bare
 * `Request`), never for a real HTTP response.
 */
final class Problem
{
    /**
     * The `application/problem+json` body shape every `self::make()` call
     * produces — a PHPDoc array-shape TYPE STRING (not a real PHP type),
     * for `#[Dedoc\Scramble\Attributes\Response(status, type: self::
     * PROBLEM_SHAPE)]`/`Problem::PROBLEM_SHAPE` on a controller method's
     * own attribute (step 6 review follow-up, Part A item 6). Public and
     * on THIS class specifically because a PHP attribute argument must be
     * a compile-time constant expression — `Fqcn::CONST` qualifies, from
     * ANY class, not only `self`/a parent — so every `/v1` controller that
     * needs to document a `Problem::make()`-shaped error response can
     * reference the ONE constant here instead of each re-declaring its own
     * copy (`InterviewController`/`ProjectController` previously did,
     * verbatim, because at the time neither had a shared ancestor this
     * could live on without widening either class's own responsibility —
     * `Problem` already is that shared thing, no widening needed).
     */
    public const PROBLEM_SHAPE = 'array{type: string, title: string, status: int, code: string, request_id: string, detail?: string, errors?: list<array{field: string, code: string, message?: string}>}';

    /**
     * `token_invalid`/`token_consumed` (public-api step 5, G-32) are NOT
     * `/v1` `ErrorCode` enum members — `App\Http\Controllers\Embed\
     * ExchangeController` lives outside `/v1` entirely (SPEC.md §3.5, a
     * separate public surface with its own two error codes), and reuses
     * this class purely for the shared `application/problem+json` shape
     * and request-id stamping, not for contract membership. Reported as a
     * G-item rather than silently added to the `/v1` contract.
     *
     * @param  'invalid_api_key'|'api_key_in_query'|'browser_origin_forbidden'|'insufficient_scope'|'not_found'|'validation_failed'|'invalid_cursor'|'invalid_expand'|'invalid_state'|'duplicate_enrolment'|'project_not_active'|'idempotency_key_reused'|'idempotency_in_progress'|'redirect_url_not_allowed'|'metadata_limit_exceeded'|'not_ready'|'transcript_not_ready'|'scoring_not_ready'|'recording_not_ready'|'export_in_progress'|'rate_limited'|'internal_error'|'token_invalid'|'token_consumed'  $code
     * @param  array<string, string>  $extraHeaders  merged onto the response — e.g. `WWW-Authenticate` on a 401, `Retry-After` on a 429.
     * @param  list<array{field: string, code: string, message?: string}>|null  $errors  the contract's `Problem.errors[]` validation-detail array (step 3) — omitted from the body entirely when null, per `components.schemas.Problem` (`errors` is not in `required`).
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $title,
        ?string $detail = null,
        array $extraHeaders = [],
        ?array $errors = null,
    ): JsonResponse {
        $requestId = self::requestId($request);

        $configuredDevelopersUrl = config('public_api.developers_url');
        $developersUrl = is_string($configuredDevelopersUrl) && $configuredDevelopersUrl !== ''
            ? $configuredDevelopersUrl
            : 'https://developers.beai.example';

        $base = rtrim($developersUrl, '/');

        $body = [
            'type' => $base.'/errors/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'request_id' => $requestId,
        ];

        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $response = response()
            ->json($body, $status)
            ->header('Content-Type', 'application/problem+json; charset=utf-8')
            ->header('X-Request-Id', $requestId);

        foreach ($extraHeaders as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Reuses `public_api.request_id` when an earlier middleware already
     * stamped one on the request (step 3's request-id middleware); otherwise
     * generates a fresh `req_` + lowercase 26-char ULID. Lowercase
     * deliberately — `Str::ulid()` renders Crockford base32 uppercase, and
     * this API's ids are lowercase throughout (SPEC.md §3.2 id prefixes).
     */
    private static function requestId(Request $request): string
    {
        $existing = $request->attributes->get('public_api.request_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return 'req_'.strtolower((string) Str::ulid());
    }
}
