<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a tenant-scoped WRITE that has no organization to write into.
 *
 * A superadmin with no acting client has no organization context: the
 * resolver answers null, and creating a `TenantScoped` row would throw
 * `MissingTenantContextException` (a 500). That is a caller in the wrong
 * scope, not a fault, so it answers the same legible 409 `POST /users`
 * already gives, before any write and before validation.
 *
 * The fail-closed exception in `TenantScoped` is deliberately NOT mapped to
 * 409 globally: that would also mask a genuine internal bug (a job or a
 * console path that forgot to establish a context). Only routes that opt in
 * here get the 409.
 *
 * Reads the RESOLVER, never `$user->organization_id`, which is null for every
 * superadmin whose acting organization TenantContext already resolved.
 * Must run after TenantContext.
 *
 * Usage: `Route::post(...)->middleware('org.context')`.
 */
final class RequireOrganizationContext
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->resolver->getOrgId() === null, Response::HTTP_CONFLICT, 'organization_context_required');

        return $next($request);
    }
}
