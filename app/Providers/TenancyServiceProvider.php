<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Registers TenantResolver as a scoped binding — re-created per HTTP request
     * (safe under Octane) and reset per queue job via the Queue::before hook.
     * Using scoped() instead of singleton() ensures state never bleeds across requests.
     */
    public function register(): void
    {
        $this->app->scoped(TenantResolver::class, fn () => new TenantResolver);
    }

    /**
     * Bootstrap any application services.
     *
     * Registers a Queue::before hook that resets BOTH:
     *   1. TenantResolver (orgId=null, bypass=false)
     *   2. Spatie team context (setPermissionsTeamId(null))
     *
     * This prevents HTTP request tenancy from bleeding into queue jobs.
     * Each job is responsible for re-establishing tenancy explicitly via
     * App\Support\Tenancy\TenantContextScope::runFor(), re-derived from its
     * own aggregate root's DB record — never from the payload.
     */
    public function boot(): void
    {
        Queue::before(function () {
            /** @var TenantResolver $resolver */
            $resolver = $this->app->make(TenantResolver::class);
            $resolver->setOrgId(null);
            $resolver->setBypass(false);

            /** @var PermissionRegistrar $registrar */
            $registrar = $this->app->make(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId(null);
        });
    }
}
