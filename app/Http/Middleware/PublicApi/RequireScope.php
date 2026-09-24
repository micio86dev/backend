<?php

declare(strict_types=1);

namespace App\Http\Middleware\PublicApi;

use App\Models\ApiClient;
use App\Support\PublicApi\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireScope middleware — the public-api counterpart of
 * `App\Http\Middleware\CheckAbility`, registered as the `scope` alias.
 *
 * Applied PER-ROUTE on `/v1` business routes: `->middleware('scope:interviews:read')`.
 * Scopes are stored in the SAME `abilities` jsonb column `CheckAbility`
 * already reads (`config/m2m_abilities.php` documents the shared canonical
 * set) — `ApiClient::can()` makes no distinction between an internal
 * "ability" and a public "scope", because there isn't one at the data layer.
 *
 * MUST run BEFORE `SubstituteBindings`, for the identical 404-vs-403
 * resource-existence enumeration oracle `CheckAbility`'s own docblock
 * documents: without this ordering, a route parameter could be resolved
 * (revealing whether the resource exists via a 404-vs-403 split) before the
 * scope check ever ran. `bootstrap/app.php` inserts it via
 * `prependToPriorityList`, mirroring `CheckAbility`'s own registration.
 *
 * SPEC.md §3.1: "Missing scope → `403 insufficient_scope`."
 */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        /** @var ApiClient|null $client */
        $client = Auth::guard('api-m2m')->user();

        if ($client === null || ! $client->can($scope)) {
            return Problem::make(
                $request,
                403,
                'insufficient_scope',
                'Insufficient scope',
                "Requires {$scope}",
            );
        }

        return $next($request);
    }
}
