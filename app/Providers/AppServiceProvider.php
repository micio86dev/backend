<?php

namespace App\Providers;

use App\Contracts\LLMProvider;
use App\Models\ApiClient;
use App\Models\Project;
use App\Policies\ApiClientPolicy;
use App\Policies\ProjectPolicy;
use App\Testing\FakeLLMProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Translatable\Facades\Translatable;

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
        // C4 — Register ProjectPolicy for Gate-based authorization.
        Gate::policy(Project::class, ProjectPolicy::class);

        // C5 — Register ApiClientPolicy for Gate-based authorization.
        Gate::policy(ApiClient::class, ApiClientPolicy::class);

        // C5 — Register the api-m2m RequestGuard.
        //
        // BOTH this viaRequest call AND the 'api-m2m' entry in config/auth.php
        // are REQUIRED in the same deploy:
        //   - AuthManager::resolve() reads config("auth.guards.api-m2m") FIRST and
        //     throws InvalidArgumentException if absent.
        //   - viaRequest registers the closure in customCreators under 'api-m2m'.
        //
        // Guard closure:
        //   1. Extract Bearer token from Authorization header.
        //   2. SHA-256 hash → raw ApiClient lookup via unique key_hash index (active scope).
        //   3. Redis client_revoked:{id} denylist check — exception-guarded (fail-safe
        //      = DB re-query on outage, NEVER fail-open).
        //   4. Throttled last_used_at update (best-effort, exception-guarded).
        //   5. Return ApiClient or null (null → 401 by the Authenticate middleware).
        Auth::viaRequest('api-m2m', function (Request $request): ?ApiClient {
            $header = $request->header('Authorization', '');
            if (! str_starts_with((string) $header, 'Bearer ')) {
                return null;
            }

            $raw = substr((string) $header, 7);
            if ($raw === '') {
                return null;
            }

            $hash = hash('sha256', $raw);

            // Raw unscoped lookup — ApiClient is NOT a TenantModel; TenantResolver is
            // not stamped yet at this point (TenantContextM2m runs after the guard).
            $client = ApiClient::active()->where('key_hash', $hash)->first();

            if ($client === null) {
                return null;
            }

            // Redis denylist check: client_revoked:{id}
            // Fail-safe: on Redis outage, fall back to a FRESH DB active() re-query.
            // NEVER fail-open — is_active is the durable authoritative revocation flag.
            try {
                $denylistKey = 'client_revoked:' . $client->id;
                if (Cache::has($denylistKey)) {
                    return null;
                }
            } catch (\Throwable) {
                // Redis is down — re-query DB with full active() scope (fresh read,
                // not the in-memory model which could be stale).
                $client = ApiClient::active()->where('key_hash', $hash)->first();
                if ($client === null) {
                    return null;
                }
            }

            // Throttled last_used_at update — best-effort, non-fatal.
            // Write only if null or older than 5 minutes to avoid per-request writes.
            try {
                if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinutes(5))) {
                    $client->updateQuietly(['last_used_at' => now()]);
                }
            } catch (\Throwable) {
                // Non-fatal — telemetry write failure must never reject an authenticated request.
            }

            return $client;
        });

        // C3 — Task 1.3: Configure spatie/laravel-translatable fallback explicitly.
        // spatie/laravel-translatable ^6.x does not publish a config file; the
        // Translatable singleton is configured programmatically here.
        // Values are read from config/translatable.php for traceability.
        Translatable::fallback(
            fallbackLocale: config('translatable.fallback_locale', 'en'),
            fallbackAny: (bool) config('translatable.fallback_any', true),
        );

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
