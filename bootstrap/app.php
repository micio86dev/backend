<?php

use App\Exceptions\Admin\LifecycleNotReadyException;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\ParticipantTransitionException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use App\Exceptions\Users\UserGuardException;
use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\RejectStaleCredentials;
use App\Http\Middleware\RequireRefreshCsrfHeader;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // queue-worker-scheduler PR2/PR3 (design.md D6): registers the scheduler
    // RUNNER (makes `schedule:work` viable) plus queue-table maintenance
    // (queue hygiene, not a domain concern — distinct from any future
    // GDPR candidate-data purge, which is C13's own retention policy).
    // ANY task added here MUST chain ->onOneServer():
    // `deploy.replicas: 1` on the scheduler compose service (wrapper PR5) is
    // overridable by --scale, so onOneServer() is structural defense-in-depth,
    // not just documentation. It is backed by whichever cache store is
    // configured, and the store is deliberately NOT named here: the compose
    // stack pins CACHE_STORE=redis per CLAUDE.md's stack table, while
    // config/cache.php still defaults to `database` outside Docker. Both work —
    // RedisStore and DatabaseStore each implement LockProvider — and naming one
    // of them in this comment is how it goes stale the next time the driver
    // moves. What matters is that the store is SHARED across processes; a
    // per-container store (CACHE_STORE=file) silently reduces this lock to no
    // lock at all. Enforced by tests/Arch/Queue/SchedulerOnOneServerArchTest.php.
    ->withSchedule(function (Schedule $schedule): void {
        // Retention windows are config-driven (queue.maintenance.*) — see
        // config/queue.php for the 168h/7-day reasoning. Never hardcode
        // --hours here.
        $schedule->command('queue:prune-failed', [
            '--hours' => (int) config('queue.maintenance.failed_jobs_retention_hours'),
        ])->dailyAt('03:10')->onOneServer();

        $schedule->command('queue:prune-batches', [
            '--hours' => (int) config('queue.maintenance.batches_retention_hours'),
        ])->dailyAt('03:20')->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // This application is API-only (CLAUDE.md: "API-only, no Blade UI").
        // There is no `login` route and there never will be — so guests must
        // never be redirected anywhere.
        //
        // Not defensive: ApplicationBuilder::withMiddleware() installs a DEFAULT
        // callback of `fn () => route('login')`, and
        // Authenticate::unauthenticated() invokes it whenever
        // $request->expectsJson() is false. That call throws
        // RouteNotFoundException INSIDE the middleware, before the
        // AuthenticationException is ever constructed — so the exception
        // handler's shouldRenderJsonWhen(api/*) rule below never runs, and a
        // caller who merely omitted an Accept header receives a 500 with a
        // stack trace instead of a 401.
        //
        // Returning null lets the AuthenticationException be thrown normally,
        // which the handler then renders as a 401 JSON body.
        $middleware->redirectGuestsTo(fn () => null);

        // Apply security headers globally (task 7.7 / D29).
        $middleware->append(SecurityHeaders::class);

        // backoffice-session-refresh-hardening D4: the refresh cookie carries
        // its own opaque, hashed-at-rest secret (256 bits of CSPRNG entropy)
        // — Laravel's app-level cookie encryption would only obscure the
        // documented wire format `{family_id}.{secret}` (design D2/D4, and
        // the D11 WebKit gate's raw Set-Cookie fixture) for zero additional
        // security. Same reasoning tymon documents for its own optional
        // cookie transport (config/jwt.php `decrypt_cookies`).
        //
        // NOT config('refresh_tokens.cookie.name') here — this closure runs
        // during Application::configure(), before LoadConfiguration has
        // bound the 'config' singleton into the container; calling config()
        // this early throws "Target class [config] does not exist." The
        // literal name is asserted equal to the config value by
        // tests/Arch/Config/CorsConfigTest.php's sibling refresh-cookie
        // invariant test so the two can never silently drift.
        $middleware->encryptCookies(except: ['beai_refresh']);

        // C2: Register TenantContext on the `api` middleware group AFTER auth:api.
        // TenantContext reads $request->user() which is only available after auth:api
        // has authenticated the bearer token and loaded the User from the DB.
        // IMPORTANT: TenantContext must never run before auth:api — it would receive null user.
        $middleware->appendToGroup('api', TenantContext::class);

        // user-profile-self-service (design D3): registered AFTER TenantContext,
        // its own middleware rather than folded into TenantContext — three route
        // groups call withoutMiddleware(TenantContext::class), and piggybacking
        // would let a future tenancy exemption silently exempt credential
        // revocation too. Relies on $request->user() already being loaded by
        // auth:api, same ordering requirement as TenantContext above.
        $middleware->appendToGroup('api', RejectStaleCredentials::class);

        // C5: Register the 'ability' middleware alias for per-route M2M ability checks.
        // e.g. Route::middleware('ability:participants:read')
        // backoffice-session-refresh-hardening D5: 'refresh.csrf' alias for
        // POST /api/auth/refresh, now publicly routable (auth:api dropped).
        $middleware->alias([
            'ability' => CheckAbility::class,
            'refresh.csrf' => RequireRefreshCsrfHeader::class,
        ]);

        // C5: Insert CheckAbility IMMEDIATELY BEFORE SubstituteBindings in the priority list.
        // Without this, per-route ability middleware could execute AFTER SubstituteBindings
        // (which IS in the default priority list), creating a 404-vs-403 resource-existence
        // enumeration oracle. prependToPriorityList inserts without replacing the full list.
        // ⚠️  NOT appendToPriorityList — that would place CheckAbility AFTER SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, CheckAbility::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // C6: ParticipantTransitionException renders HTTP 422.
        // Registered here as a backstop for direct (non-HTTP) model writes.
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (ParticipantTransitionException $e, Request $request) {
            return $e->render($request);
        });

        // C8: CompositionException and AnchorTranslationMissingException → HTTP 422.
        // Mirrors ParticipantTransitionException registration pattern.
        // Machine-readable error codes (not localized — BEAI machine-facing response policy).
        $exceptions->render(function (CompositionException $e, Request $request) {
            return response()->json(['error' => 'composition_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        $exceptions->render(function (AnchorTranslationMissingException $e, Request $request) {
            return response()->json(['error' => 'anchor_translation_missing'], Response::HTTP_UNPROCESSABLE_ENTITY);
        });

        // C11: LifecycleNotReadyException → HTTP 409 (D4 — gated admin read, not RBAC).
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (LifecycleNotReadyException $e, Request $request) {
            return $e->render($request);
        });

        // backoffice-missing-pages: UserGuardException → HTTP 422 with a
        // machine-readable error code (last_admin/self_demotion/self_deactivation).
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (UserGuardException $e, Request $request) {
            return $e->render($request);
        });

        // operator-interview-link (design D3): EntryLinkUrlNotConfigured →
        // HTTP 500 with a specific, actionable message. NOT suppressed from
        // Sentry — being noisy about an unset CANDIDATE_APP_URL is the point.
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (EntryLinkUrlNotConfigured $e, Request $request) {
            return $e->render($request);
        });
    })->create();
