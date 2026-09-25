<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns and echoes the BEAI Public API (`/v1`) request id — SPEC.md §3.2
 * "Every response carries `X-Request-Id`. Clients may send their own; it is
 * echoed and logged."
 *
 * FIRST in every `/v1` middleware stack — including the unauthenticated
 * `/v1/health` route — so every later piece of code (`App\Support\PublicApi\
 * Problem`, application log lines, any future handler) can rely on
 * `$request->attributes->get('public_api.request_id')` already being set,
 * and so the header lands on every response, success or error, with no
 * later middleware able to skip it.
 *
 * A client-supplied id is trusted only when it matches
 * `^[A-Za-z0-9._-]{1,128}$` — safe to log and to echo back verbatim, and
 * short enough to never become a header-size DoS vector. Anything else
 * (missing, empty, too long, or carrying a character outside that set —
 * commas, whitespace, control characters, anything that could smuggle a
 * second header value) is replaced with a fresh SERVER-generated id, never
 * rejected: a malformed request id is a client's problem, not a reason to
 * fail the whole request.
 *
 * Generated shape: `req_` + a lowercase 26-char ULID — the same convention
 * `App\Support\PublicApi\Problem`'s own (now-redundant) fallback generator
 * already used, kept identical here so a request that reaches this
 * middleware but somehow never reaches `Problem::make()` (a success
 * response) still gets an id in the same family.
 */
final class AssignRequestId
{
    /**
     * `^[A-Za-z0-9._-]{1,128}$` — see class docblock.
     */
    private const CLIENT_ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        $request->attributes->set('public_api.request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $clientId = $request->header('X-Request-Id');

        if (is_string($clientId) && preg_match(self::CLIENT_ID_PATTERN, $clientId) === 1) {
            return $clientId;
        }

        return 'req_'.strtolower((string) Str::ulid());
    }
}
