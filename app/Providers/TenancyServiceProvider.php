<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Mail\EmailBranding;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
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

        // Scoped for the same reason and with the same hazard: a queue worker
        // is a long-lived process handling one tenant's mail after another's,
        // and a brand colour that outlived its send would put one
        // organization's colour on another organization's message.
        $this->app->scoped(EmailBranding::class, fn () => new EmailBranding);
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
     *
     * AND IT PUTS BACK WHAT IT FOUND
     * ------------------------------
     * The reset above is correct and stays. What was missing is the other
     * half: under the `sync` driver a job runs INSIDE the dispatching request,
     * so the reset landed on the REQUEST's own context and nothing ever undid
     * it. Every line after a `dispatch()` then ran with no tenant and no team
     * — including the response serialization, which is where it actually bit:
     * `POST /api/users` created the user correctly, assigned the role
     * correctly, dispatched the invitation, and then rendered
     * `getRoleNames()` against a null team and returned `"role": null`.
     *
     * Nothing about that is specific to tests. `sync` is a supported
     * configuration, and the failure is silent, request-wide, and lands on
     * whatever happens to come after the dispatch.
     *
     * Under a real worker this changes nothing: each job's `before` resets
     * again, so restoring null between jobs is what already happened.
     *
     * Restored on FAILURE as well as success. A job that throws is exactly
     * when leaving a request half-scoped would do the most damage, and it is
     * the case an `after`-only hook silently misses.
     */
    public function boot(): void
    {
        $previousOrgId = null;
        $previousBypass = false;
        $previousTeamId = null;

        Queue::before(function () use (&$previousOrgId, &$previousBypass, &$previousTeamId) {
            /** @var TenantResolver $resolver */
            $resolver = $this->app->make(TenantResolver::class);

            /** @var PermissionRegistrar $registrar */
            $registrar = $this->app->make(PermissionRegistrar::class);

            $previousOrgId = $resolver->getOrgId();
            $previousBypass = $resolver->isBypass();
            $previousTeamId = $registrar->getPermissionsTeamId();

            $resolver->setOrgId(null);
            $resolver->setBypass(false);
            $registrar->setPermissionsTeamId(null);

            // A job that brands a message must set this itself, from its own
            // organization. Inheriting whatever the previous job left is how
            // one tenant's colour ends up on another tenant's mail.
            $this->app->make(EmailBranding::class)->forget();
        });

        $restore = function () use (&$previousOrgId, &$previousBypass, &$previousTeamId): void {
            /** @var TenantResolver $resolver */
            $resolver = $this->app->make(TenantResolver::class);
            $resolver->setOrgId($previousOrgId);
            $resolver->setBypass($previousBypass);

            /** @var PermissionRegistrar $registrar */
            $registrar = $this->app->make(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($previousTeamId);
        };

        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }
}
