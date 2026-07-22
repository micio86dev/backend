<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * EventServiceProvider — domain event listener provider (C9 PR3).
 *
 * ScoringRequested → DispatchScoringJob is registered via Laravel's automatic event
 * discovery (the framework's base EventServiceProvider scans app/Listeners/ and
 * matches DispatchScoringJob::handle(ScoringRequested $event) automatically).
 *
 * We intentionally do NOT declare listeners in $listen here to avoid double-registration:
 * the framework's built-in EventServiceProvider runs discovery with shouldDiscoverEvents()=true,
 * so adding them to $listen here would register each listener twice.
 *
 * If auto-discovery is ever disabled globally, add listeners back to $listen.
 *
 * REQ: D2 EventServiceProvider (C9 PR3 Task 21.2)
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * Empty — listeners are discovered automatically by the framework's base EventServiceProvider.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [];
}
