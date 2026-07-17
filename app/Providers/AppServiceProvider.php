<?php

namespace App\Providers;

use App\Contracts\LLMProvider;
use App\Testing\FakeLLMProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // D36: Bind FakeLLMProvider for APP_ENV=testing.
        // All standard tests use this fake — zero HTTP requests to external AI APIs.
        // Real provider implementations are bound in C8 for non-test environments.
        // @ai-group tests (ai-integration.yml) override this binding with a real provider.
        if ($this->app->environment('testing')) {
            $this->app->bind(LLMProvider::class, FakeLLMProvider::class);
        }

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // C2 — Phase 3: Register test-only isolation routes for the cross-tenant
        // isolation matrix tests. These routes are NEVER loaded in production.
        // They expose a minimal SampleTenantRecord CRUD surface guarded by auth:api.
        if ($this->app->environment('testing')) {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api-test-isolation.php'));
        }

        // C2 — D4: Invalidate Spatie permission cache on role assignment changes.
        // Cache-invalidation mechanism: events_enabled=true in config/permission.php
        // fires RoleAttached/RoleDetached; these listeners ensure the cache is cleared
        // before the next permission check, consistent across all Redis-backed instances.
        // AuthController::logout() also calls forgetCachedPermissions() explicitly as a
        // belt-and-suspenders guard on the logout code path.
        if (config('permission.events_enabled', false)) {
            Event::listen(RoleAttached::class, function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });

            Event::listen(RoleDetached::class, function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });
        }
    }
}
