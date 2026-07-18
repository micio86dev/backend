<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckAbility middleware (C5 — M2M API Authentication).
 *
 * Applied PER-ROUTE on M2M business routes that require a specific ability.
 * Registered as alias 'ability' in bootstrap/app.php so routes declare:
 *   ->middleware('ability:participants:read')
 *
 * MUST run BEFORE SubstituteBindings to prevent a 404-vs-403 resource-existence
 * enumeration oracle. bootstrap/app.php inserts it via prependToPriorityList.
 *
 * GET /api/m2m/whoami requires NO ability middleware — guard authentication alone.
 *
 * REQ-6 / design §CheckAbility ordering
 */
final class CheckAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var ApiClient|null $client */
        $client = Auth::guard('api-m2m')->user();

        if ($client === null || ! $client->can($ability)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
