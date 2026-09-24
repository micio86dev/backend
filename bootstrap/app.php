<?php

use App\Console\Commands\DispatchScheduledInterviewInvitations;
use App\Console\Commands\ReapStaleInterviews;
use App\Console\Commands\ReconcileLlmUsage;
use App\Exceptions\Admin\LifecycleNotReadyException;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\ConversationLlm\InvalidLlmBindingException;
use App\Exceptions\ConversationLlm\UnsupportedLlmModeException;
use App\Exceptions\ParticipantTransitionException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use App\Exceptions\Users\UserGuardException;
use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\IdempotencyKey;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RateLimitPublicApi;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Http\Middleware\PublicApi\RequireScope;
use App\Http\Middleware\RejectStaleCredentials;
use App\Http\Middleware\RequireRefreshCsrfHeader;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocaleFromRequest;
use App\Http\Middleware\TenantContext;
use App\Models\RefreshToken;
use App\Support\PublicApi\PublicApiExceptionRenderer;
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

        // backoffice-session-refresh-hardening (storage corrected from Redis
        // to PostgreSQL — see design.md): dead refresh_tokens rows (past
        // their absolute ceiling, or already revoked_at) never become live
        // again, so the Laravel-standard Prunable convention deletes them on
        // a schedule rather than requiring an operator to remember to.
        $schedule->command('model:prune', [
            '--model' => [RefreshToken::class],
        ])->dailyAt('03:30')->onOneServer();

        // pluggable-conversation-llm PR P6b (design D10 W4): reconciles the
        // LLM cost of a session that ended via server-detected error (or a
        // lost /end request) without ever calling POST /end. NOT a
        // best-effort approximation — the SAME estimator, over the SAME
        // persisted rows, `firstOrCreate()`-guarded against a late /end.
        $schedule->command(ReconcileLlmUsage::class)->dailyAt('04:00')->onOneServer();

        // End interviews the browser never ended (stale-interview-reaper).
        //
        // `POST /end` is the only thing that closes a session, so the whole
        // chain rests on the candidate's tab living long enough to make one
        // more HTTP call. When it does not — the avatar never speaks its
        // closing phrase, the tab is closed, the laptop sleeps — the
        // transcript is already safe on the server and nothing scores it.
        //
        // Every fifteen minutes rather than daily: the cost of waiting is an
        // operator staring at a participant stuck on "in corso" with no way to
        // tell whether anything is happening.
        //
        // `withoutOverlapping()` as well as `onOneServer()`: two sweeps over
        // the same rows would both try to end the same session, and the second
        // would spend a scoring job on a participant the first already
        // advanced.
        $schedule->command(ReapStaleInterviews::class)
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping();

        // interview-scheduling (design AD-4): every minute, not every 15 like
        // its neighbor above — the notice-at-T-15 / start-at-T-0 thresholds
        // ARE the feature, unlike ReapStaleInterviews's coarser staleness
        // window, where being a few minutes late changes nothing observable.
        // withoutOverlapping() as well as onOneServer(): a concurrent tick
        // must not double-send either email — enforced structurally by this
        // AND by the command's own per-row lockForUpdate() (design AD-5).
        $schedule->command(DispatchScheduledInterviewInvitations::class)
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping();
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
        // Prepended, so it runs BEFORE anything that might render a
        // user-facing message — a validation failure or an authorization
        // refusal answered in the wrong language is exactly the case the
        // "every server message is localized" rule exists for, and both of
        // those happen inside middleware that would otherwise run first.
        //
        // Replaces `FrameworkController::resolveLocale()`, which three
        // endpoints called by hand: the backoffice could be in Italian and an
        // evaluation report still came back in English, because the surface
        // serving it had never heard of the mechanism. A rule enforced by
        // remembering to call a private method is not a rule.
        $middleware->prependToGroup('api', SetLocaleFromRequest::class);

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
            // public-api step 2: the `/v1` counterpart of `ability` — e.g.
            // Route::middleware('scope:interviews:read').
            'scope' => RequireScope::class,
            // public-api step 3: opt-in per-route Idempotency-Key support —
            // e.g. Route::middleware('idempotent') on a POST endpoint that
            // documents `idempotencyKey` in openapi.yaml.
            'idempotent' => IdempotencyKey::class,
        ]);

        // C5: Insert CheckAbility IMMEDIATELY BEFORE SubstituteBindings in the priority list.
        // Without this, per-route ability middleware could execute AFTER SubstituteBindings
        // (which IS in the default priority list), creating a 404-vs-403 resource-existence
        // enumeration oracle. prependToPriorityList inserts without replacing the full list.
        // ⚠️  NOT appendToPriorityList — that would place CheckAbility AFTER SubstituteBindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, CheckAbility::class);

        // public-api step 2: same 404-vs-403 oracle, same fix — RequireScope
        // MUST run before SubstituteBindings resolves a route-bound model.
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireScope::class);

        // public-api step 4 (review finding): `SortedMiddleware` does not
        // treat "priority-listed" as "reorder relative to everything given"
        // — it reorders EVERY middleware present in the priority list to
        // sit together, in priority order, ahead of every middleware that
        // is NOT in the priority list, regardless of the GIVEN array's own
        // order. `RequireScope` (above) and `SubstituteBindings` (a Laravel
        // default priority entry) are the only two `/v1` middleware in that
        // list — so on any route combining them with the rest of the `/v1`
        // stack (`AssignRequestId`, `RejectApiKeyInQuery`,
        // `AuthenticatePublicApi`, `PublicApiTenantContext`,
        // `RateLimitPublicApi`, none of which are priority-listed), those
        // five were silently pulled to run AFTER `RequireScope` — i.e.
        // `RequireScope` ran BEFORE `AuthenticatePublicApi` ever resolved a
        // client, "successfully" reading whatever `Auth::guard('api-m2m')`
        // returned from its OWN lazy `viaRequest` fallback
        // (`allowTestMode: false`) instead — silently WRONG for a real
        // `/v1` request (a `beai_test_` key would be rejected by the wrong
        // rule) and, because `Illuminate\Auth\RequestGuard` caches its
        // resolved user for the guard instance's lifetime, capable of
        // authenticating a LATER request in the same PHP process as an
        // EARLIER request's client. Only discovered here because step 4 is
        // the first step to combine `scope:` with real `/v1` business
        // routes — `T-AUTH-005`'s original probe-route version never
        // exercised the real `routes/api.php` registration at all.
        //
        // Fix: put the entire `/v1` authenticated stack in the SAME
        // priority chain, in its intended order, so nothing in it is
        // "unlisted" any more and `SortedMiddleware` has nothing left to
        // silently reshuffle.
        $middleware->prependToPriorityList(RequireScope::class, RateLimitPublicApi::class);
        $middleware->prependToPriorityList(RateLimitPublicApi::class, PublicApiTenantContext::class);
        $middleware->prependToPriorityList(PublicApiTenantContext::class, AuthenticatePublicApi::class);
        $middleware->prependToPriorityList(AuthenticatePublicApi::class, RejectApiKeyInQuery::class);
        $middleware->prependToPriorityList(RejectApiKeyInQuery::class, AssignRequestId::class);
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

        // pluggable-conversation-llm PR P3a (design D4/D11): I2/I3/I4 binding
        // invariants, enforced in AvatarTemplate::booted() on `saving` — the
        // one hook `forceFill()->save()` (the portability import path)
        // cannot dodge. Same machine-readable 422 shape as UserGuardException
        // above.
        $exceptions->render(function (UnsupportedLlmModeException $e, Request $request) {
            return $e->render($request);
        });

        $exceptions->render(function (InvalidLlmBindingException $e, Request $request) {
            return $e->render($request);
        });

        // operator-interview-link (design D3): EntryLinkUrlNotConfigured →
        // HTTP 500 with a specific, actionable message. NOT suppressed from
        // Sentry — being noisy about an unset CANDIDATE_APP_URL is the point.
        // The exception's own render() method handles the JSON response.
        $exceptions->render(function (EntryLinkUrlNotConfigured $e, Request $request) {
            return $e->render($request);
        });

        // public-api step 3: every uncaught exception on `/v1` renders as
        // `application/problem+json` (SPEC.md §3.2) — see
        // App\Support\PublicApi\PublicApiExceptionRenderer's own docblock for
        // the full status/code mapping and its documented judgement calls
        // (G-27). Registered with the widest possible type hint
        // (`Throwable`) so it is reached for every exception type;
        // `render()` itself returns null for anything outside `api/v1/*`,
        // deferring to every renderer above for the existing `/api/*`
        // (backoffice) surface, which this callback never touches.
        $exceptions->render(fn (Throwable $e, Request $request) => PublicApiExceptionRenderer::render($e, $request));
    })->create();
