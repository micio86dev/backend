<?php

namespace App\Providers;

use App\Contracts\AuditJudge;
use App\Contracts\LLMProvider;
use App\Contracts\RedisEvictionPolicyProbe;
use App\Http\Middleware\PublicApi\RateLimitPublicApi;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Evaluation;
use App\Models\LlmCredential;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Policies\ApiClientPolicy;
use App\Policies\AvatarTemplatePolicy;
use App\Policies\EvaluationPolicy;
use App\Policies\LlmCredentialPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ParticipantPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\UserPolicy;
use App\Services\Audit\TypesafeJevJudge;
use App\Services\LLM\AnthropicLLMProvider;
use App\Services\Scoring\AssessableFractionReliability;
use App\Services\Scoring\Contracts\ReliabilityStrategy;
use App\Services\Scoring\Contracts\ValidityPredicate;
use App\Services\Scoring\ThresholdValidityPredicate;
use App\Support\Auth\RedisConfigEvictionPolicyProbe;
use App\Support\PublicApi\ApiKeyResolver;
use App\Support\PublicApi\ApiMode;
use App\Testing\FakeAuditJudge;
use App\Testing\FakeLLMProvider;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Translatable\Facades\Translatable;
use Tymon\JWTAuth\JWTAuth;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // D36: Bind FakeLLMProvider for APP_ENV=testing.
        // All standard tests use this fake — zero HTTP requests to external AI APIs.
        // @ai-group tests (ai-integration.yml) run outside the testing environment and
        // therefore resolve AnthropicLLMProvider — the only place real API calls occur.
        if ($this->app->environment('testing')) {
            $this->app->bind(LLMProvider::class, FakeLLMProvider::class);
        } else {
            // C9 D7 (resolved): production binding calls the Anthropic Messages API
            // directly via Laravel's Http client — NO third-party SDK, NO D25 blocker.
            $this->app->bind(LLMProvider::class, AnthropicLLMProvider::class);
        }

        // scoring-audit-jev (proposal AD-5, design C-B/D3): AuditJudge joins the
        // exact if/else above, in the same method, immediately below it. All
        // standard tests use FakeAuditJudge — zero HTTP requests to TypeSafe.
        // The @ai-group real-API lane (workflow_dispatch) runs outside the
        // testing environment and therefore resolves TypesafeJevJudge.
        if ($this->app->environment('testing')) {
            $this->app->bind(AuditJudge::class, FakeAuditJudge::class);
        } else {
            $this->app->bind(AuditJudge::class, TypesafeJevJudge::class);
        }

        // C9 PR3: D5 — Bind injectable ReliabilityStrategy and ValidityPredicate.
        // Default implementations are config-swappable without code changes:
        //   ReliabilityStrategy → AssessableFractionReliability (R-A: assessed/total)
        //   ValidityPredicate   → ThresholdValidityPredicate (V-A: reliability >= T)
        // Override bindings in tests by calling $this->app->instance() or rebinding.
        $this->app->bind(ReliabilityStrategy::class, AssessableFractionReliability::class);
        $this->app->bind(ValidityPredicate::class, ThresholdValidityPredicate::class);

        // backoffice-session-refresh-hardening D3 — real CONFIG GET probe by
        // default; tests bind a fake via $this->app->instance() (LLMProvider
        // pattern) rather than mocking the final concrete implementation.
        $this->app->bind(RedisEvictionPolicyProbe::class, RedisConfigEvictionPolicyProbe::class);

        // public-api step 2 — request-scoped, mirroring TenantResolver's own
        // registration exactly (scoped(), not singleton(): Octane-safe, and
        // reset per request rather than leaking between requests sharing a
        // worker).
        $this->app->scoped(ApiMode::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forcePublicRootUrl();

        // Public API contract document (T-CONTRACT-001): `/v1` only, exported to
        // `openapi.v1.json`, compared against docs/specs/public-api/openapi.yaml.
        // The default document keeps documenting the whole `/api` surface.
        Scramble::registerApi('v1', [
            'api_path' => 'api/v1',
            'export_path' => 'openapi.v1.json',
        ]);

        // C4 — Register ProjectPolicy for Gate-based authorization.
        /**
         * A superadmin passes every gate.
         *
         * The tenancy bypass alone was not enough, and the gap was invisible
         * until a superadmin actually tried to read something: `TenantScoped`
         * stopped filtering rows, and then the POLICY refused the request —
         * because a superadmin has no organization, therefore no Spatie team,
         * therefore no roles and no permissions at all. Every read returned
         * 403 while the data layer was working perfectly.
         *
         * Returning `true` short-circuits; returning NULL (not `false`) falls
         * through to the normal policy for everyone else. `false` here would
         * deny every ordinary user in the product.
         *
         * The narrowing to one client is NOT done here. It happens in
         * TenantContext, which scopes the resolver — so acting as a client
         * gives that client's DATA with the superadmin's own authority, rather
         * than impersonating one of their users.
         */
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->is_superadmin === true ? true : null;
        });

        Gate::policy(Project::class, ProjectPolicy::class);

        // C5 — Register ApiClientPolicy for Gate-based authorization.
        Gate::policy(ApiClient::class, ApiClientPolicy::class);

        // C11 — Register ParticipantPolicy/EvaluationPolicy for admin read RBAC (D3).
        Gate::policy(Participant::class, ParticipantPolicy::class);
        Gate::policy(Evaluation::class, EvaluationPolicy::class);

        // backoffice-missing-pages — Register OrganizationPolicy/UserPolicy for
        // the org settings + admin-only user management RBAC (D2/D4).
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // C14 — Register AvatarTemplatePolicy. Laravel would auto-discover it by
        // name, but every other policy here is registered explicitly, and an
        // authorization rule that works by convention is one that breaks
        // silently the day a namespace moves.
        Gate::policy(AvatarTemplate::class, AvatarTemplatePolicy::class);

        // pluggable-conversation-llm PR P2 — Register LlmCredentialPolicy.
        // Every ability admin-only, mirroring AvatarTemplatePolicy exactly: a
        // credential is closer to a secret than a setting.
        Gate::policy(LlmCredential::class, LlmCredentialPolicy::class);

        // superadmin-clients-console D4 — a Gate with no policy and no model.
        // There is no Client model to authorize against: the subject is the
        // CALLER, not a row, which is the same reasoning assertSuperadmin()
        // gives for not being a policy either. This is NOT the enforcement
        // point — SuperadminController::assertSuperadmin() still aborts 403 —
        // it exists so UserAbilities::for() answers from a real Gate call
        // instead of re-deriving is_superadmin a second time.
        Gate::define('viewAnyClients', static fn (User $user): bool => $user->is_superadmin === true);

        // The platform SETTINGS section, for the same reason and by the same
        // mechanism. `GET/PATCH /api/admin/settings` is superadmin-only and is
        // enforced by the same assertSuperadmin(), but it published no ability
        // — so the backoffice had to re-derive that nav item from
        // `is_superadmin` while deriving the neighbouring one from an ability.
        // Two capabilities of the same kind answered two different ways in the
        // same menu is exactly the drift UserAbilities exists to end.
        Gate::define('viewPlatformSettings', static fn (User $user): bool => $user->is_superadmin === true);

        // framework-catalogue-authoring PR3, D12 — same shape, same
        // reasoning: the catalogue is platform content, not a tenant's, so
        // no org-scoped policy can describe who may edit it. NOT the
        // enforcement point: every catalogue controller action repeats
        // `abort_unless($this->isSuperadmin($request), 403)` inline
        // (`PlatformUserController`'s precedent) — this Gate exists only so
        // `UserAbilities::for()` answers `catalogue.manage` from a real
        // `Gate::allows()` call instead of re-deriving `is_superadmin` a
        // second time for the backoffice's rendering hint.
        Gate::define('manageCatalogue', static fn (User $user): bool => $user->is_superadmin === true);

        // C13 — Gate the Laravel Pulse dashboard (task 5.2).
        //
        // Two conditions, deliberately, and this is a considered DEVIATION from
        // spec.md:242 ("authenticated users with the `admin` RBAC role").
        //
        // `admin` here is ORG-SCOPED: spatie runs in teams mode with
        // team_id = organization_id, so every customer has an admin of their
        // own. Pulse has no organization_id anywhere — it aggregates the slow
        // queries, exception messages and job payloads of every tenant onto a
        // single page, and there is no scoping to apply to it. Read literally,
        // the spec hands each customer's admin a view of every other customer's
        // data, which CLAUDE.md's "a tenant must never see another tenant's
        // data" forbids outright.
        //
        // So: the admin role AND an explicit platform-operator allowlist. Both,
        // not either — the allowlist is a deployment artifact that outlives the
        // person it names, while the role is revoked the day they leave. Each
        // one covers the other going stale.
        Gate::define('viewPulse', function (User $user): bool {
            $operators = config('pulse.operators', []);

            if (! is_array($operators) || ! in_array($user->email, $operators, true)) {
                return false;
            }

            // Teams mode: the registrar's team id must be set before hasRole()
            // means anything, and Pulse's routes carry no TenantContext
            // middleware to have set it. Without this the check silently
            // resolves against team_id NULL and denies everyone — fail-closed,
            // but for the wrong reason and impossible to debug.
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->organization_id);

            return $user->hasRole('admin');
        });

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
        //   2. Delegate resolution — hash/prefix lookup, denylist check,
        //      throttled last_used_at update — to App\Support\PublicApi\
        //      ApiKeyResolver (public-api step 2), SHARED with the new `/v1`
        //      AuthenticatePublicApi middleware. Every pre-existing (live)
        //      key still resolves through the resolver's legacy hash-only
        //      fallback (see that class's docblock), so this guard's own
        //      test suite (tests/Feature/C5/GuardResolutionTest.php etc.)
        //      stays green untouched. Review follow-up (finding 1): passes
        //      allowTestMode: false — a `beai_test_` key, which exists only
        //      for the public `/v1` surface (SPEC.md §3.7), must never
        //      authenticate here and reach live tenant data.
        //   3. Return ApiClient or null (null → 401 by the Authenticate middleware).
        Auth::viaRequest('api-m2m', function (Request $request): ?ApiClient {
            $header = $request->header('Authorization', '');
            if (! str_starts_with((string) $header, 'Bearer ')) {
                return null;
            }

            $raw = substr((string) $header, 7);
            if ($raw === '') {
                return null;
            }

            // Review follow-up (finding 1): allowTestMode=false — this
            // internal surface must never authenticate a beai_test_ key.
            return ApiKeyResolver::resolve($raw, allowTestMode: false);
        });

        // C6 — Register the api-candidate RequestGuard.
        //
        // BOTH this viaRequest call AND the 'api-candidate' entry in config/auth.php
        // are REQUIRED in the same deploy (same dual-registration pattern as C5 api-m2m).
        //
        // Guard closure (5 steps — security-critical):
        //   1. Extract Bearer token from Authorization header.
        //   2. SINGLE decode: $payload = JWTAuth::setToken($raw)->checkOrFail()
        //      NOT authenticate() (resolves via User provider, prv mismatch risk).
        //      NOT two separate setToken calls (facade singleton state pollution risk).
        //   3. Assert $payload->get('typ') === 'candidate' (PRIMARY defense).
        //      tymon does NOT check custom claims — explicit assertion is mandatory.
        //      Any other typ (user, m2m, sso-link, absent) → null → 401.
        //   4. Validate sub is a positive integer (non-numeric sub = sso-link token).
        //   5. Participant::find((int) $sub) — unscoped (not TenantModel; TenantResolver
        //      not stamped yet; same reason as ApiClient::find in M2M guard).
        //
        // SECONDARY layer: candidate JWTs carry prv=hash(Participant) via fromUser.
        // On the `api` (User) guard, prv mismatch → TokenInvalidException → null → 401.
        Auth::viaRequest('api-candidate', function (Request $request): ?Participant {
            $header = $request->header('Authorization', '');
            if (! str_starts_with((string) $header, 'Bearer ')) {
                return null;
            }

            $raw = substr((string) $header, 7);
            if ($raw === '') {
                return null;
            }

            try {
                // SINGLE decode — returns validated Payload or throws.
                $payload = app(JWTAuth::class)->setToken($raw)->checkOrFail();
            } catch (\Throwable) {
                return null;
            }

            // PRIMARY defense: explicit typ assertion (tymon does NOT check custom claims).
            if ($payload->get('typ') !== 'candidate') {
                return null;
            }

            // sub must be a positive integer — sso-link tokens have a string candidate_ref as sub.
            $sub = $payload->get('sub');
            if (! is_numeric($sub) || (int) $sub <= 0) {
                return null;
            }

            return Participant::find((int) $sub);
        });

        // C3 — Task 1.3: Configure spatie/laravel-translatable fallback explicitly.
        // spatie/laravel-translatable ^6.x does not publish a config file; the
        // Translatable singleton is configured programmatically here.
        // Values are read from config/translatable.php for traceability.
        //
        // `config()` returns `mixed` — narrowed here (step 5 review follow-up,
        // PHPStan level-max on this touched file) rather than trusted as a
        // string, matching `forcePublicRootUrl()`'s own discipline below for
        // the identical reason.
        $fallbackLocale = config('translatable.fallback_locale', 'en');

        Translatable::fallback(
            fallbackLocale: is_string($fallbackLocale) ? $fallbackLocale : 'en',
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

        // public-api step 3: the `public-api` named rate limiter — SPEC.md
        // §3.2 "Rate limiting: per organization, token bucket". G-30
        // (documented judgement call, step 3 review follow-up 5): despite
        // that wording, this is a FIXED-WINDOW counter, not a token bucket
        // — `Illuminate\Cache\RateLimiter` has no other mode. A fixed
        // window can let a client push up to `2 * max` requests through a
        // short span straddling a window boundary (`max` at the end of one
        // window, `max` more at the start of the next); a true token
        // bucket would not. That cross-window burst is an accepted
        // deviation from the spec's literal wording — see
        // `RateLimitPublicApi`'s own docblock for the full reasoning and
        // for the WITHIN-window atomicity guarantee this class does hold
        // (a single window can never itself exceed `max`).
        //
        // This declarative registration is the single source of truth for
        // the bucket DEFINITION (key + limit) AND the live bucket
        // `RateLimitPublicApi` resolves through `$limiter->limiter(
        // 'public-api')` rather than calling `keyFor()`/`maxAttemptsFor()`
        // directly — see that class's own docblock for why the middleware
        // still talks to the underlying `Illuminate\Cache\RateLimiter`
        // counter directly instead of through the `throttle:public-api`
        // alias (contract-mandated header names/shape Laravel's built-in
        // middleware does not produce). `Limit::none()` for a request with
        // no resolved client is unreachable in the real `/v1` stack
        // (`AuthenticatePublicApi` always runs first) but keeps this
        // callback total.
        RateLimiter::for('public-api', function (Request $request) {
            /** @var ApiClient|null $client */
            $client = $request->attributes->get('public_api.client');

            if (! $client instanceof ApiClient) {
                return Limit::none();
            }

            return Limit::perMinute(RateLimitPublicApi::maxAttemptsFor($client))
                ->by(RateLimitPublicApi::keyFor($client));
        });

        // `embed-exchange` — GET /api/embed/exchange (public-api step 5,
        // SPEC.md §3.5, G-32). A NAMED limiter (step 5 review follow-up,
        // item 2), not the numeric `throttle:30,1` this route used before:
        // Laravel's numeric form resolves its bucket key through
        // `ThrottleRequests::resolveRequestSignature()`, which ALWAYS calls
        // `$request->user()` on the application's DEFAULT guard first —
        // regardless of whether this route runs any auth middleware at
        // all. That guard is tymon's JWTGuard, whose own token parser
        // chain reads a `token` QUERY/INPUT parameter by the SAME name
        // this endpoint's own `?token=` contract parameter uses
        // (`Tymon\JWTAuth\Http\Parser\QueryString`/`InputSource`, tymon's
        // own default chain) — an array-shaped `?token[]=` value (never
        // valid here, but never rejected before this middleware runs
        // either) reached `explode('.', $array)` inside tymon's token
        // validator and 500'd, before `ExchangeController::exchange()`
        // ever got a chance to answer its own `401 token_invalid`. A named
        // limiter's callback owns the bucket key OUTRIGHT (`->by()` below)
        // and Laravel never calls `resolveRequestSignature()`/`$request->
        // user()` for it — keyed on IP, matching this route's own
        // docblock reasoning (a brute-force-guessing surface against
        // `?token=`, not a per-account one).
        RateLimiter::for('embed-exchange', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip() ?? 'unknown');
        });
    }

    /**
     * Generate absolute URLs from `APP_URL`, never from the request's Host.
     *
     * This API is always reached through something else — the Nuxt apps proxy
     * `/api/*` in development, Railway's edge terminates TLS in production — so
     * the `Host` header Laravel sees is the INTERNAL one. Left to its default,
     * the URL generator built links against it, and a signed profile-photo URL
     * came back as `http://api:8000/storage/...`: the compose service name,
     * which no browser can resolve. The image simply did not load, with a 200
     * response and a well-formed signature.
     *
     * `Storage::url()` alone was not enough, because the `local` disk with
     * `serve => true` issues a temporary SIGNED ROUTE, and signed routes are
     * built from the request root rather than the disk's configured `url`.
     * Forcing the root is what makes both agree.
     *
     * Skipped when `APP_URL` is unset or malformed rather than guessed at:
     * falling back to the request root restores exactly the previous behaviour,
     * which is wrong here but is at least the behaviour every existing test was
     * written against.
     */
    private function forcePublicRootUrl(): void
    {
        // `config()` returns `mixed` — narrowed here rather than blindly
        // `(string)`-cast (step 5 review follow-up, PHPStan level-max on
        // this touched file: a blind cast on a non-scalar config value
        // produces a PHP warning and an unhelpful string like "Array" at
        // runtime, not a clean empty-string fallback).
        $configuredAppUrl = config('app.url');
        $appUrl = is_string($configuredAppUrl) ? $configuredAppUrl : '';

        if ($appUrl === '' || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            return;
        }

        URL::forceRootUrl($appUrl);

        // A signed URL generated against `http://` and verified against
        // `https://` does not match, so the scheme has to be pinned with the
        // root rather than left to the (proxied, and therefore plain) request.
        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
