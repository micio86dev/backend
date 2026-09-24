<?php

declare(strict_types=1);

namespace Tests\Helpers\PublicApi;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throws `HttpResponseException` from MIDDLEWARE rather than a route
 * action — `Illuminate\Routing\Route::run()` already catches this
 * exception when it is thrown from the route's own controller/closure
 * (`return $e->getResponse()` — Route.php's own `catch (HttpResponseException
 * $e)` block), so a probe route that throws it directly never reaches
 * `App\Support\PublicApi\PublicApiExceptionRenderer` at all and cannot
 * exercise its own passthrough handling (public-api step 3 review
 * follow-up 9). Thrown from middleware instead, it propagates past
 * `Route::run()` and into the global exception-handling pipeline, exactly
 * like `abort(response())` called from a middleware (or a FormRequest's
 * `failedAuthorization()`) would.
 */
final class ThrowsHttpResponseExceptionMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        throw new HttpResponseException(response()->json(['custom' => 'shape'], 418));
    }
}
