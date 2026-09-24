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
 * Deliberately minimal: this is the step-2 shape (auth-path errors only, no
 * `errors[]` validation-detail array — that lands with step 3's request
 * validation layer) and the request-id it stamps is a SELF-CONTAINED
 * fallback, not the full request-id middleware SPEC.md §3.2 promises
 * ("clients may send their own; it is echoed and logged") — that middleware
 * is also step 3. Reading `public_api.request_id` from the request
 * ATTRIBUTE bag (not a header) is what lets that later middleware seed one
 * value this class then reuses instead of generating a second, disagreeing
 * id for the same request.
 */
final class Problem
{
    /**
     * @param  'invalid_api_key'|'api_key_in_query'|'browser_origin_forbidden'|'insufficient_scope'  $code
     * @param  array<string, string>  $extraHeaders  merged onto the response — e.g. `WWW-Authenticate` on a 401.
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $title,
        ?string $detail = null,
        array $extraHeaders = [],
    ): JsonResponse {
        $requestId = self::requestId($request);

        $base = rtrim(
            (string) (config('public_api.developers_url') ?: 'https://developers.beai.example'),
            '/'
        );

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
