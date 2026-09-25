<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects `?api_key=` on the BEAI Public API (`/v1`) — SPEC.md §3.1 "Keys are
 * REJECTED when sent as a query parameter (`?api_key=`) → `400
 * api_key_in_query`."
 *
 * Runs BEFORE any auth check (first in the `/v1` middleware stack): a key in
 * the query string is a client mistake worth refusing outright, not a
 * credential to evaluate — query strings land in server access logs, proxy
 * logs and browser history, none of which the Authorization header does.
 */
final class RejectApiKeyInQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('api_key')) {
            return Problem::make(
                $request,
                400,
                'api_key_in_query',
                'API key in query string',
                'The api_key parameter must not be sent in the query string — use the Authorization: Bearer header instead.',
            );
        }

        return $next($request);
    }
}
