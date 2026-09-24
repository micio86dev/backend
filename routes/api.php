<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\Api\Catalogue\BarsIndicatorController;
use App\Http\Controllers\Api\Catalogue\CompetencyController as CatalogueCompetencyController;
use App\Http\Controllers\Api\Catalogue\DefaultQuestionController;
use App\Http\Controllers\Api\Catalogue\RevisionController;
use App\Http\Controllers\Api\Catalogue\RoleController as CatalogueRoleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntryLinkController;
use App\Http\Controllers\Api\EvaluationAuditController;
use App\Http\Controllers\Api\EvaluationIndexController;
use App\Http\Controllers\Api\FrameworkController;
use App\Http\Controllers\Api\LlmCredentialController;
use App\Http\Controllers\Api\LlmModelController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationLogoController;
use App\Http\Controllers\Api\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Api\ParticipantDownloadController;
use App\Http\Controllers\Api\ParticipantRecoveryController;
use App\Http\Controllers\Api\ParticipantScheduleController;
use App\Http\Controllers\Api\PlatformUserController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectQuestionController;
use App\Http\Controllers\Api\SessionReviewController;
use App\Http\Controllers\Api\SuperadminController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\AvatarTemplateController;
use App\Http\Controllers\AvatarTemplatePortabilityController;
use App\Http\Controllers\Candidate\IntegrityController;
use App\Http\Controllers\Candidate\InterviewController;
use App\Http\Controllers\Candidate\SessionController;
use App\Http\Controllers\Candidate\SnapshotController;
use App\Http\Controllers\Candidate\UtteranceController;
use App\Http\Controllers\Embed\ExchangeController as EmbedExchangeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\M2m\AbilityCatalogController;
use App\Http\Controllers\M2m\ApiClientController;
use App\Http\Controllers\M2m\ParticipantController;
use App\Http\Controllers\M2m\SsoLinkController;
use App\Http\Controllers\M2m\WhoamiController;
use App\Http\Controllers\PublicApi\HealthController as PublicApiHealthController;
use App\Http\Controllers\PublicApi\InterviewController as PublicApiInterviewController;
use App\Http\Controllers\PublicApi\OrganizationController as PublicApiOrganizationController;
use App\Http\Controllers\PublicApi\ProjectController as PublicApiProjectController;
use App\Http\Controllers\PublicApi\SessionTokenController as PublicApiSessionTokenController;
use App\Http\Controllers\QueueHealthController;
use App\Http\Controllers\Sso\SsoExchangeController;
use App\Http\Middleware\ParticipantStatusGuard;
use App\Http\Middleware\PublicApi\AssignRequestId;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Http\Middleware\PublicApi\RateLimitPublicApi;
use App\Http\Middleware\PublicApi\RejectApiKeyInQuery;
use App\Http\Middleware\RejectStaleCredentials;
use App\Http\Middleware\RequireRefreshCsrfHeader;
use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextCandidate;
use App\Http\Middleware\TenantContextM2m;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// queue-worker-scheduler PR4 (design.md D7): unauthenticated so Docker/
// Railway probes can reach it without credentials — the body carries
// counts/booleans/ages ONLY, never a candidate/tenant identifier. Doubles
// as the worker HEALTHCHECK (wrapper PR5).
Route::get('/health/queue', QueueHealthController::class);

// ─── BEAI Public API (/v1) ────────────────────────────────────────────────
//
// docs/specs/public-api/SPEC.md §0 "Contract governance": the public API
// lives here, versioned at `/v1` (so `/api/v1/...` on this route file's own
// `/api` prefix). Everything under this group is governed by the vendored
// contract `public-api/openapi.yaml` — no field ships here without an entry
// in it, and CI asserts the Scramble-exported spec stays equivalent
// (step 11, T-CONTRACT-001). This is a SEPARATE, versioned surface from the
// existing backoffice-facing routes above; organization API-key auth and
// tenancy (SPEC.md §3.1) land in step 2 — `/health` is the only unauthenticated
// operation the contract declares (SPEC.md §5.2).
Route::prefix('v1')
    ->name('public-api.')
    ->middleware([AssignRequestId::class])
    ->group(function (): void {
        Route::get('/health', PublicApiHealthController::class)->name('health');
    });

// ─── BEAI Public API (/v1) — authenticated surface (public-api step 2) ───────
//
// SPEC.md §3.1: `Authorization: Bearer <api_key>` — a SEPARATE credential
// system from the human `auth:api` JWT and, at the ROUTE level, from the
// internal `auth:api-m2m` M2M surface below (`/api/m2m/*`) even though both
// ultimately resolve the SAME ApiClient model and guard
// (App\Support\PublicApi\ApiKeyResolver is shared — see its docblock).
//
// withoutMiddleware([TenantContext, RejectStaleCredentials]): identical
// isolation to the M2M/candidate/SSO route groups above — both are appended
// to the whole `api` group in bootstrap/app.php and both read
// $request->user() on the DEFAULT 'api' guard, which would resolve against
// whatever bearer key is present here and 500 rather than pass through.
//
// Inline middleware stack (explicit, ordered, per SPEC.md §3.1/§3.2 — public-api step 3):
//   1. AssignRequestId       — stamps public_api.request_id; echoed on every response
//   2. RejectApiKeyInQuery   — `?api_key=` → 400, before any auth check
//   3. AuthenticatePublicApi — resolves ApiClient via bearer key; sets api-m2m guard
//   4. PublicApiTenantContext — stamps TenantResolver + ApiMode from client
//   5. RateLimitPublicApi    — per-org/mode token bucket (needs org from step 4)
//   6. SubstituteBindings    — route-model-binding (LAST, per C4 convention)
//
// public-api step 4: first business routes. `GET /organization` needs no
// extra scope beyond authentication (SPEC.md §3.3 "any"); `GET /projects`
// and `GET /projects/{project}` require `projects:read`.
//
// `SubstituteBindings` is applied PER-ROUTE below, never in this outer
// array (a C4 convention — see `bootstrap/app.php`'s own comment above its
// `prependToPriorityList()` calls for the FULL account of why `/v1`'s
// entire authenticated stack — not just `SubstituteBindings` — is on that
// priority list, and what broke before it was: G-35,
// `docs/specs/public-api/DECISIONS-NEEDED.md`).
Route::prefix('v1')
    ->name('public-api.')
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class])
    ->middleware([
        AssignRequestId::class,
        RejectApiKeyInQuery::class,
        AuthenticatePublicApi::class,
        PublicApiTenantContext::class,
        RateLimitPublicApi::class,
    ])
    ->group(function (): void {
        Route::get('/organization', [PublicApiOrganizationController::class, 'show'])
            ->name('organization.show')
            ->middleware(SubstituteBindings::class);

        Route::middleware('scope:projects:read')->group(function (): void {
            Route::get('/projects', [PublicApiProjectController::class, 'index'])
                ->name('projects.index')
                ->middleware(SubstituteBindings::class);
            Route::get('/projects/{project}', [PublicApiProjectController::class, 'show'])
                ->name('projects.show')
                ->middleware(SubstituteBindings::class);
        });

        // public-api step 5: `Interview` (SPEC.md §3.3). `scope:` is always
        // given BEFORE `SubstituteBindings`, on every route, same convention
        // as `/projects` above (G-35's own history is why this order is
        // still given explicitly even though the priority list no longer
        // strictly needs it to be).
        Route::middleware('scope:interviews:write')->group(function (): void {
            Route::post('/interviews', [PublicApiInterviewController::class, 'store'])
                ->name('interviews.store')
                ->middleware(['idempotent', SubstituteBindings::class]);
            Route::post('/interviews/{interview}/session-tokens', [PublicApiSessionTokenController::class, 'store'])
                ->name('interviews.session-tokens.store')
                ->middleware(SubstituteBindings::class);
        });

        Route::middleware('scope:interviews:read')->group(function (): void {
            Route::get('/interviews', [PublicApiInterviewController::class, 'index'])
                ->name('interviews.index')
                ->middleware(SubstituteBindings::class);
            Route::get('/interviews/{interview}', [PublicApiInterviewController::class, 'show'])
                ->name('interviews.show')
                ->middleware(SubstituteBindings::class);
        });
    });

// ─── Auth routes (C2, refresh flow hardened by backoffice-session-refresh-hardening D8) ──
// POST /api/auth/login is public (no auth middleware).
// POST /api/auth/refresh is PUBLIC too — authenticated by the httpOnly
// refresh cookie + the refresh.csrf middleware, NEVER auth:api. This is
// deliberate and load-bearing: tymon's auth:api guard rejects an EXPIRED
// access token before the controller runs, so refreshing an expired session
// would be structurally impossible behind auth:api (D8's second, independent
// fix for the operator's "logged out constantly" complaint).
// /logout and /me keep auth:api explicitly — NEVER bare `auth` middleware,
// which would silently fall back to the `web` session guard.

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware(RequireRefreshCsrfHeader::class);

    // ─── Self-service password reset (self-service-password-reset AD-7) ──────
    // Both PUBLIC by necessity — the caller cannot log in, which is why they
    // are here — and both throttled inline, following the /profile/password
    // convention below rather than inventing a number:
    //
    //   forgot-password: unauthenticated, with a side effect on ANOTHER
    //     person's inbox. Unthrottled it is a mail-bomb primitive and a cost
    //     primitive (every call is a queued job and a paid Resend send).
    //   reset-password: unauthenticated token submission, i.e. a brute-force
    //     surface against the reset token itself.
    //
    // The limiter keys on the caller's IP for both, so the limit cannot differ
    // between a known and an unknown address — a limit that kicked in sooner
    // for real accounts would be an enumeration oracle of its own.
    //
    // NOT ADDED HERE, deliberately: a per-EMAIL hourly cap. It trades
    // mail-bombing against a targeted recovery-DENIAL attack (an attacker who
    // knows a victim's address could lock them out of recovery), and that is an
    // open product decision — proposal question 4 — not an implementation
    // choice. The broker's own per-user throttle (config/auth.php
    // passwords.users.throttle, 60s) already prices repeat sends to ONE inbox;
    // it runs inside the queued job and must not be mistaken for a route limit.
    //
    // login/refresh/logout/me stay unthrottled — an explicit non-goal here.
    Route::post('/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:6,1');
    Route::post('/reset-password', ResetPasswordController::class)
        ->middleware('throttle:6,1');

    Route::middleware('auth:api')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// ─── Framework Catalog API (C3) ──────────────────────────────────────────────
// Read-only endpoints serving the global BEAI framework catalog.
// Org-scoped via auth:api + TenantContext middleware (C2).
// FrameworkVersion is NOT required to exist — missing pin → 200 + pin_context: null.

Route::middleware(['auth:api', TenantContext::class])->prefix('framework')->group(function (): void {
    Route::get('/roles', [FrameworkController::class, 'index']);
    Route::get('/roles/{roleCode}/competencies', [FrameworkController::class, 'roleCompetencies']);
    Route::get('/roles/{roleCode}/competencies/{competencyCode}/indicators', [FrameworkController::class, 'competencyBars']);

    // NOT under /roles: MTG and LAT belong to no role, which is exactly what
    // makes them the `potential` set. Declared BEFORE nothing and after the
    // role routes purely for readability — the path shares no prefix with them.
    Route::get('/potential-competencies', [FrameworkController::class, 'potentialCompetencies']);

    // C4 — GET /api/framework/versions: list org-scoped FrameworkVersions available for pinning.
    Route::get('/versions', [FrameworkController::class, 'versions']);
});

// ─── Conversation LLM Registry (pluggable-conversation-llm PR P1) ────────────
// GET /api/llm-models — a public price list, readable by all three
// authorization roles (design D9). Global, like the framework catalog above:
// no policy check, no ownership to authorize.
Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/llm-models', [LlmModelController::class, 'index']);
});

// ─── Conversation LLM Credentials (pluggable-conversation-llm PR P2) ─────────
// Org-scoped, admin-only vault CRUD (LlmCredentialPolicy, design D9).
// `throttle:5,1` on store/update — both routes reach GeminiKeyValidator, and
// there is deliberately no "test without saving" endpoint (design D9).
Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/llm-credentials', [LlmCredentialController::class, 'index']);
    Route::post('/llm-credentials', [LlmCredentialController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::get('/llm-credentials/{id}', [LlmCredentialController::class, 'show']);
    Route::patch('/llm-credentials/{id}', [LlmCredentialController::class, 'update'])
        ->middleware('throttle:5,1');
    Route::delete('/llm-credentials/{id}', [LlmCredentialController::class, 'destroy']);
});

// ─── Project Configuration API (C4) ──────────────────────────────────────────
// Org-scoped Project CRUD. Behind auth:api + TenantContext middleware.
// RBAC via ProjectPolicy: admin/operator full CRUD; viewer read-only.
// destroy → HTTP 204 No Content (soft-delete; FV lock preserved).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::apiResource('projects', ProjectController::class);

    // Predefined interview questions, nested under the project
    // (potential-competencies-and-authored-questions).
    //
    // `{project}` is route-model bound to a TenantModel, so another
    // organization's id does not resolve and the request 404s — never 403,
    // which would confirm the project exists and turn this into an existence
    // oracle across tenants.
    //
    // `order` is declared BEFORE `{question}`: registered the other way round,
    // `PUT /questions/order` would match the update route with the literal
    // "order" as the id, and fail as a bad integer instead of reordering.
    Route::get('projects/{project}/questions', [ProjectQuestionController::class, 'index']);
    Route::post('projects/{project}/questions', [ProjectQuestionController::class, 'store']);
    Route::put('projects/{project}/questions/order', [ProjectQuestionController::class, 'reorder']);
    Route::patch('projects/{project}/questions/{question}', [ProjectQuestionController::class, 'update']);
    Route::delete('projects/{project}/questions/{question}', [ProjectQuestionController::class, 'destroy']);
});

// ─── Superadmin: clients and the acting-organization switch ──────────────────
// RATIFIED 2026-09-02 (option b): the switch is SERVER-SIDE. The rejected
// alternative was `X-Organization-Id` on every request, which puts a
// cross-tenant lever anywhere a caller can set a header — every endpoint would
// then have to honour it correctly, and one mistake is a cross-tenant leak.
//
// Behind TenantContext like everything else: a superadmin reaches it through
// the same middleware that grants their bypass, so there is no second path
// into the tenant layer to keep in step with the first.
Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('admin/organizations', [SuperadminController::class, 'organizations']);
    Route::get('admin/clients', [SuperadminController::class, 'clients']);
    Route::put('admin/acting-organization', [SuperadminController::class, 'setActingOrganization']);

    // Platform-wide settings — BEAI's own knobs, not a tenant's. Superadmin
    // only; every tenant role gets 403 (see SuperadminController's doctrine on
    // 403-vs-404 for capability endpoints).
    Route::get('admin/settings', [SuperadminController::class, 'settings']);
    Route::patch('admin/settings', [SuperadminController::class, 'updateSettings']);

    // BEAI's own PEOPLE, not a tenant's (platform-user-management D1). The
    // all-clients counterpart of /users: same five verbs, a different
    // population, and a reader whose predicate is the exact inverse of the
    // org one's — so neither surface can ever reach the other's rows.
    //
    // Separate from /users rather than a scope-aware branch inside it:
    // UserAdminReader states "a platform superadmin is never manageable
    // through a tenant's user-management surface" in its WHERE clause,
    // precisely because a conditional is what gets forgotten.
    Route::get('admin/platform-users', [PlatformUserController::class, 'index']);
    Route::post('admin/platform-users', [PlatformUserController::class, 'store']);
    Route::patch('admin/platform-users/{id}', [PlatformUserController::class, 'update']);
    Route::post('admin/platform-users/{id}/deactivate', [PlatformUserController::class, 'deactivate']);
    Route::post('admin/platform-users/{id}/activate', [PlatformUserController::class, 'activate']);
});

// ─── Framework Catalogue Authoring (framework-catalogue-authoring PR3, D12) ──
// Superadmin-only, platform-global — no organization_id anywhere in this
// surface. Behind TenantContext like every other platform surface above
// (PlatformUserController's own note applies verbatim): a superadmin
// reaches it through the same middleware that grants their bypass.
Route::middleware(['auth:api', TenantContext::class])->prefix('catalogue')->group(function (): void {
    Route::get('revisions/current', [RevisionController::class, 'current']);
    Route::post('revisions/draft', [RevisionController::class, 'openDraft']);
    Route::delete('revisions/draft', [RevisionController::class, 'discard']);
    Route::post('revisions/publish', [RevisionController::class, 'publish']);

    Route::get('roles', [CatalogueRoleController::class, 'index']);
    Route::post('roles', [CatalogueRoleController::class, 'store']);
    Route::patch('roles/{role}', [CatalogueRoleController::class, 'update'])->whereNumber('role');
    Route::delete('roles/{role}', [CatalogueRoleController::class, 'destroy'])->whereNumber('role');
    // framework-catalogue-authoring PR8b: attach/detach/reorder a role's
    // competency set in one idempotent write — see `RoleController::
    // updateCompetencies()`'s own docblock.
    Route::put('roles/{role}/competencies', [CatalogueRoleController::class, 'updateCompetencies'])->whereNumber('role');

    Route::get('competencies', [CatalogueCompetencyController::class, 'index']);
    Route::post('competencies', [CatalogueCompetencyController::class, 'store']);
    Route::patch('competencies/{competency}', [CatalogueCompetencyController::class, 'update'])->whereNumber('competency');
    Route::delete('competencies/{competency}', [CatalogueCompetencyController::class, 'destroy'])->whereNumber('competency');

    Route::get('bars-indicators', [BarsIndicatorController::class, 'index']);
    Route::post('bars-indicators', [BarsIndicatorController::class, 'store']);
    Route::patch('bars-indicators/{indicator}', [BarsIndicatorController::class, 'update'])->whereNumber('indicator');
    Route::delete('bars-indicators/{indicator}', [BarsIndicatorController::class, 'destroy'])->whereNumber('indicator');

    // framework-catalogue-authoring PR4: catalogue-level default questions,
    // scoped to the open draft revision exactly like the three above.
    Route::get('default-questions', [DefaultQuestionController::class, 'index']);
    Route::post('default-questions', [DefaultQuestionController::class, 'store']);
    Route::patch('default-questions/{defaultQuestion}', [DefaultQuestionController::class, 'update'])->whereNumber('defaultQuestion');
    Route::delete('default-questions/{defaultQuestion}', [DefaultQuestionController::class, 'destroy'])->whereNumber('defaultQuestion');
});

// ─── Organization Settings (backoffice-missing-pages, D2) ────────────────────
// Singular, self-resolving resource — NO id in the path, ever. The org
// resolves exclusively from the authenticated user's organization_id, so
// there is no `{organization}` route variant and no IDOR surface to guard.
// Read for all roles, write admin-only (OrganizationPolicy).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/organization', [OrganizationController::class, 'show']);
    Route::patch('/organization', [OrganizationController::class, 'update']);
    // Separate from the PATCH above, deliberately: `logo_path` is written ONLY
    // by an endpoint that knows a file was actually stored. Accepting it as a
    // field on the settings PATCH would let a client point the logo at any path
    // on the disk.
    // throttle:10,1, matching `POST /profile/photo` below and for the reason
    // that block already records: every call costs an object-storage PUT, so
    // an unthrottled upload is a storage-burn primitive for a stolen bearer
    // token. This endpoint is the same primitive with a wider blast radius —
    // it also runs `getimagesize()` on a decompression-bomb candidate, so the
    // burn is CPU as well as storage. Admin-only narrows who can reach it; it
    // does not make the loop cheaper. DELETE stays free: idempotent, no PUT.
    Route::post('/organization/logo', [OrganizationLogoController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::delete('/organization/logo', [OrganizationLogoController::class, 'destroy']);
});

// ─── Organization Logo Read (PUBLIC) ─────────────────────────────────────────
// The one id-addressed organization route, and the only PUBLIC one. Both are
// departures from the singular self-resolving doctrine directly above, and
// both are forced by WHO reads this: an email client fetching a remote image
// through its own proxy, and the candidate app painting the organization's
// mark before the candidate has exchanged their link for a token. Neither can
// present a bearer token, and neither has an org id to resolve from one.
//
// It serves a REDIRECT to a short-lived presigned object URL, never the
// object's own store URL — the bucket is private and shared with candidate
// proctoring snapshots, so its S3 endpoint answers 401 to a browser. That 401
// is the defect this route exists to close. `OrganizationLogoController::show`
// refuses to presign any key outside `organization-logos/`.
//
// withoutMiddleware([TenantContext, RejectStaleCredentials]): both are
// appended to the whole `api` group in bootstrap/app.php and both read
// `$request->user()`. Same isolation the SSO and candidate blocks below apply,
// for the same reason — this request is unauthenticated on the `api` guard.
Route::get('/organizations/{organization}/logo', [OrganizationLogoController::class, 'show'])
    ->whereNumber('organization')
    ->name('organizations.logo')
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class]);

// ─── User Self-Service Profile (user-profile-self-service, design D1) ────────
// Singular, self-resolving resource — NO id in the path, ever, mirroring the
// Organization Settings block above exactly. The subject resolves
// EXCLUSIVELY from the authenticated user's token; there is no policy check
// here (no object to authorize) and this surface is entirely separate from
// the admin-only User Management block below — UserPolicy is untouched.
//
// throttle:6,1 on the password route only (design D5): without it the
// endpoint is a current-password oracle for a stolen bearer token.
//
// user-avatar-image (design D1): POST/DELETE /profile/photo join this SAME
// block — a binary sub-resource beside the JSON /profile resource, never
// inside it. `PATCH /profile`'s `only(['name','email','locale'])` line
// (ProfileController::update) stays byte-unchanged by this addition.
// throttle:10,1 on POST only (design D1): every call costs an object-storage
// PUT, so an unthrottled upload is a storage-burn primitive for a stolen
// bearer token. DELETE is idempotent and free — no throttle.

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:6,1');
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy']);
});

// ─── User Management (backoffice-missing-pages, D4) ───────────────────────────
// Admin-only, org-scoped CRUD + Spatie role assignment — a privilege-
// escalation surface (it can grant `admin`). RBAC via UserPolicy: every
// ability admin-only, unlike ProjectPolicy/ParticipantPolicy/EvaluationPolicy
// which are all-roles-read.
//
// Deliberately absent: GET /api/roles (D4 — the admin/operator/viewer
// allow-list is a code-level enum, App\Enums\OrgRole, exported into
// openapi.json, never a runtime endpoint — and one path segment away from
// the UNRELATED GET /api/framework/roles below, which serves the BEAI
// organizational roles ICO/FLL/MLL/BUL/SRX) and DELETE (D5 — soft
// deactivation only, via the two explicit verbs below).
//
// IDs are resolved manually inside UserAdminReader (D4) — never route-model
// binding, per ProjectController.php:23-28's documented reason.

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/users/{user}/activate', [UserController::class, 'activate']);
});

// ─── Avatar Templates (C14) ───────────────────────────────────────────────────
// Org-scoped CRUD plus activation. Admin-only via AvatarTemplatePolicy —
// including READ, because choosing the face and voice every candidate of an
// organization meets is a brand decision rather than a day-to-day one.
//
// `field-specs` is declared BEFORE the {id} routes. Registered after, Laravel
// would match "field-specs" as an id, and the endpoint would 404 with no hint
// as to why.
Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    // Portability (C14). Admin-only both ways: export is the fastest way to
    // lift configuration out of a tenant, import changes what future
    // interviews run on. Declared BEFORE /{id} so the literal paths win.
    Route::get('/avatar-templates/export', [AvatarTemplatePortabilityController::class, 'export']);
    Route::post('/avatar-templates/import', [AvatarTemplatePortabilityController::class, 'import']);
    // Declared BEFORE /{id}, like `field-specs` and for the same reason:
    // registered after, Laravel matches "options" as an id and the endpoint
    // 404s with no hint as to why.
    //
    // NOT admin-only, unlike every other route in this group. It returns id,
    // name and provider — what choosing a template for a project requires —
    // because `projects.avatar_template_id` is NOT NULL and operators create
    // projects. See AvatarTemplateController::options().
    Route::get('/avatar-templates/options', [AvatarTemplateController::class, 'options']);
    Route::get('/avatar-templates/field-specs', [AvatarTemplateController::class, 'fieldSpecs']);
    // avatar-template-catalogue PR1 (D1): one generic endpoint, provider and
    // resource as query params. Declared BEFORE /{id}, same reason as
    // `field-specs`/`options` above — registered after, Laravel would match
    // "catalogue" as an id and 404 with no hint why.
    Route::get('/avatar-templates/catalogue', [AvatarTemplateController::class, 'catalogue']);
    Route::post('/avatar-templates/{id}/activate', [AvatarTemplateController::class, 'activate']);
    Route::post('/avatar-templates/{id}/deactivate', [AvatarTemplateController::class, 'deactivate']);

    Route::get('/avatar-templates', [AvatarTemplateController::class, 'index']);
    Route::post('/avatar-templates', [AvatarTemplateController::class, 'store']);
    Route::get('/avatar-templates/{id}', [AvatarTemplateController::class, 'show']);
    Route::patch('/avatar-templates/{id}', [AvatarTemplateController::class, 'update']);
    Route::delete('/avatar-templates/{id}', [AvatarTemplateController::class, 'destroy']);
});

// ─── Admin Read API (C11) ─────────────────────────────────────────────────────
// Org-scoped, read-only endpoints for participants, transcripts, evaluations,
// downloads, and dashboard metrics. Behind auth:api + TenantContext middleware.
// RBAC via ParticipantPolicy/EvaluationPolicy: admin/operator/viewer all read
// (ProjectPolicy::viewAny pattern — no owner filter).
//
// IDs are resolved manually inside AdminParticipantReader (D1) — never
// route-model binding, per ProjectController.php:23-28's documented reason.
// Every Participant access goes through AdminParticipantReader; a bare
// `Participant::` static call anywhere in this file's controllers is
// arch-tested against (AdminTenancySafetyArchTest, task 2.3b).
//
// Lifecycle-gated reads (transcript >= in_valutazione, evaluation ===
// completato) return 409 lifecycle_not_ready via LifecycleNotReadyException
// (D4) — registered once in bootstrap/app.php, covering every route below.
//
// Named download routes so ParticipantDetailResource's `files` open map (D9)
// can generate real URLs via route() rather than inventing/hardcoding paths.

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/participants', [AdminParticipantController::class, 'index']);
    Route::get('/participants/{id}', [AdminParticipantController::class, 'show']);
    Route::get('/participants/{id}/transcript', [AdminParticipantController::class, 'transcript']);
    Route::get('/participants/{id}/evaluation', [AdminParticipantController::class, 'evaluation']);

    Route::get('/participants/{id}/transcript/download', [ParticipantDownloadController::class, 'transcript'])
        ->name('admin.participants.transcript.download');
    Route::get('/participants/{id}/evaluation/download', [ParticipantDownloadController::class, 'evaluation'])
        ->name('admin.participants.evaluation.download');

    // Interview session review (C11). BACKOFFICE-ONLY by design: the proctoring
    // taxonomy is the list of behaviours being counted, so it must never be
    // reachable with a candidate token. Guarded by
    // tests/Arch/C11/CandidateCannotReadProctoringArchTest.php.
    Route::get('/participants/{participant}/sessions', [SessionReviewController::class, 'index']);
    Route::get('/interview-sessions/{session}/review', [SessionReviewController::class, 'show']);

    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
    Route::get('/dashboard/activity', [DashboardController::class, 'activity']);
});

// ─── Entry Link Mint (operator-interview-link) ────────────────────────────
// POST /api/entry-links — human-facing mint for an authenticated backoffice
// operator. Own route group, adjacent to (NOT inside) the Admin Read API
// block above — it is a WRITE (starts an assessment), not a read.
// ParticipantPolicy::create denies viewer; EntryLinkController resolves the
// project scoped by TenantContext's TenantScoped global scope (cross-org →
// 404) before delegating to the shared EntryLinkMinter (design D1/D2).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::post('/entry-links', [EntryLinkController::class, 'store']);
});

// ─── Participant Recovery (participant-error-recovery) ────────────────────
// POST /api/participants/{id}/recover — the SOLE authorized path back out of
// `errore`. Own route group, adjacent to (NOT inside) the Admin Read API
// block above — it is a WRITE (atomically resets the participant + its
// errored session(s)), not a read. ParticipantPolicy::recover denies viewer;
// RecoverFailedParticipant resolves the participant scoped by TenantContext
// (cross-org -> 404) under a row lock (design D1/D4/D6).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::post('/participants/{id}/recover', [ParticipantRecoveryController::class, 'store']);
});

// ─── Participant Scheduling (interview-scheduling PR-E) ────────────────────
// PATCH/DELETE /api/participants/{id}/schedule — reschedule/cancel a
// scheduled interview. Own route group, adjacent to (NOT inside) the Admin
// Read API block above — it is a WRITE, not a read. ParticipantPolicy::update
// denies viewer; RescheduleParticipant/CancelParticipantSchedule resolve the
// row scoped by TenantResolver's org id (cross-org -> 404) under a row lock
// (design AD-5/AD-7).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::patch('/participants/{id}/schedule', [ParticipantScheduleController::class, 'update']);
    Route::delete('/participants/{id}/schedule', [ParticipantScheduleController::class, 'destroy']);
});

// ─── Post-hoc Audit Trigger (scoring-audit-jev) ────────────────────────────
// POST /api/participants/{id}/evaluation/audit — the SOLE authorized path to
// request a TypeSafe/Jev audit run over an already-completed evaluation. Own
// route group, adjacent to (NOT inside) the Admin Read API block — it is a
// WRITE, not a read (design D12).
//
// Every accepted call fans out into up to 18 paid third-party AI calls — the
// most expensive per-request primitive in this API. throttle:6,1 follows the
// cost-primitive precedent already recorded for POST /forgot-password
// (:107-108), deliberately tighter than the storage-burn routes' 10,1.
// EvaluationPolicy::audit denies operator AND viewer (admin only, D12),
// diverging from every other ability on that policy.

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::post('/participants/{id}/evaluation/audit', [EvaluationAuditController::class, 'store'])
        ->middleware('throttle:6,1');
});

// ─── Admin Read API delta: Evaluations (backoffice-missing-pages D6/D7) ──────
// GET /evaluations          — org-scoped, paginated, lifecycle-gated index.
// GET /evaluations/summary  — mean competency score per code, over the SAME
//                              filtered population as the index (D7).
// Both route through the identical EvaluationIndexQuery::build() builder —
// the lifecycle gate (completato / completed|pending) lives there once,
// never at this call site. RBAC via EvaluationPolicy::viewAny — all roles.

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::get('/evaluations/summary', [EvaluationIndexController::class, 'summary']);
    Route::get('/evaluations', [EvaluationIndexController::class, 'index']);
});

// ─── M2M Machine Routes (C5) ─────────────────────────────────────────────────
// Machine-to-machine API endpoints authenticated via opaque API-key (auth:api-m2m).
//
// CRITICAL route isolation:
//   withoutMiddleware(TenantContext::class) strips the globally-appended human
//   TenantContext (bootstrap/app.php:24) from this group — without this, the
//   human TenantContext would silently pass through on a null User, potentially
//   leaving the resolver in a stale/null state.
//   withoutMiddleware(RejectStaleCredentials::class) (user-profile-self-service,
//   design D3) — same reasoning: that middleware blindly reads $request->user()
//   on the default 'api' guard, which on an M2M request resolves the human JWT
//   guard against whatever bearer key is present and would 500 rather than
//   pass through cleanly.
//
// Inline middleware stack (explicit, ordered):
//   1. auth:api-m2m       — resolves ApiClient via bearer key
//   2. TenantContextM2m   — stamps TenantResolver from client.organization_id
//   3. SubstituteBindings — route-model-binding (LAST, per C4 convention)
//
// Admin credential-management routes are NOT here — they use auth:api + global
// TenantContext (see admin group below).

Route::prefix('m2m')
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class])
    ->middleware(['auth:api-m2m', TenantContextM2m::class, SubstituteBindings::class])
    ->group(function (): void {
        // GET /api/m2m/whoami — identity for the authenticated M2M client.
        // No ability required — authentication alone is sufficient.
        Route::get('/whoami', WhoamiController::class);

        // ─── C6: Participant CRUD ─────────────────────────────────────────────
        // POST /api/m2m/participants (participants:create)
        // GET  /api/m2m/participants (participants:read)
        // GET  /api/m2m/participants/{id} (participants:read)
        Route::post('/participants', [ParticipantController::class, 'store'])
            ->middleware('ability:participants:create');
        Route::get('/participants', [ParticipantController::class, 'index'])
            ->middleware('ability:participants:read');
        Route::get('/participants/{id}', [ParticipantController::class, 'show'])
            ->middleware('ability:participants:read');

        // ─── interview-scheduling PR-E: reschedule/cancel ──────────────────────
        // PATCH/DELETE /api/m2m/participants/{id}/schedule (participants:schedule)
        // Deliberately a NARROWER ability than participants:create (AD-7).
        Route::patch('/participants/{id}/schedule', [ParticipantController::class, 'updateSchedule'])
            ->middleware('ability:participants:schedule');
        Route::delete('/participants/{id}/schedule', [ParticipantController::class, 'cancelSchedule'])
            ->middleware('ability:participants:schedule');

        // ─── C6: SSO-Link Mint ────────────────────────────────────────────────
        // POST /api/m2m/sso-link (sso_link:generate)
        Route::post('/sso-link', [SsoLinkController::class, 'store'])
            ->middleware('ability:sso_link:generate');
    });

// ─── SSO Exchange (PUBLIC) (C6) ───────────────────────────────────────────────
// PUBLIC endpoint — no guard, no TenantContext.
// CRITICAL: withoutMiddleware(TenantContext::class) prevents the globally-appended
// human TenantContext (bootstrap/app.php) from running on this public request.
// withoutMiddleware(RejectStaleCredentials::class) (user-profile-self-service,
// design D3): same reasoning — this route is PUBLIC and unauthenticated on the
// 'api' guard, but $request->user() still attempts to resolve whatever bearer
// token is present (here, a structurally-JWT-but-not-a-User sso-link token),
// which 500s rather than passing through.

Route::get('/sso/exchange', [SsoExchangeController::class, 'exchange'])
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class]);

// ─── BEAI Public API session-token exchange (PUBLIC) (public-api step 5) ─────
// PUBLIC endpoint, OUTSIDE /v1 — no API key, no TenantContext (SPEC.md §3.5,
// G-32). Same TenantContext/RejectStaleCredentials isolation as
// `/sso/exchange` immediately above, and for the identical reason.
// `throttle:30,1`: a session token is single-use, so this route is a
// brute-force-guessing surface against `?token=` the same way a password-
// reset token endpoint is — unlike `/sso/exchange`, which has no throttle
// today, this is a NEW route this step adds and the task instruction is
// explicit about the limit.
Route::get('/embed/exchange', [EmbedExchangeController::class, 'exchange'])
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class])
    ->middleware('throttle:30,1');

// ─── Candidate Routes (C6) ───────────────────────────────────────────────────
// Protected by auth:api-candidate → TenantContextCandidate → SubstituteBindings.
// withoutMiddleware(TenantContext::class) strips the globally-appended human
// TenantContext — same isolation as M2M routes.
// withoutMiddleware(RejectStaleCredentials::class) (user-profile-self-service,
// design D3) — same isolation reasoning: the candidate JWT's `sub` is a
// Participant identifier, not a users.id, so resolving it against the human
// 'api' guard's User provider would 500 instead of passing through.
//
// Middleware stack (explicit, ordered):
//   1. auth:api-candidate      — resolves Participant via candidate JWT
//   2. TenantContextCandidate  — stamps TenantResolver from participant.organization_id
//   3. SubstituteBindings      — route-model-binding (LAST)

Route::prefix('candidate')
    ->withoutMiddleware([TenantContext::class, RejectStaleCredentials::class])
    ->middleware(['auth:api-candidate', TenantContextCandidate::class, SubstituteBindings::class])
    ->group(function (): void {
        // GET /api/candidate/session — candidate whoami + project config
        Route::get('/session', [SessionController::class, 'show']);

        // ─── C7a: Interview sub-routes ────────────────────────────────────────
        // ParticipantStatusGuard is applied ONLY to this NESTED group (FIX-7):
        // terminal-status participants (completato/errore) are blocked here but
        // MAY still call GET /api/candidate/session above (read-only, acceptable).
        //
        // Middleware order in this group (inherits parent + adds guard):
        //   auth:api-candidate → TenantContextCandidate → SubstituteBindings (inherited)
        //   → ParticipantStatusGuard (nested only)
        //
        // PR 2 + PR 3 routes registered here: start, end, utterance, integrity, snapshot.
        Route::prefix('interview')
            ->middleware(ParticipantStatusGuard::class)
            ->group(function (): void {
                // POST /api/candidate/interview/start — create/resume provider session (PR 3)
                Route::post('/start', [InterviewController::class, 'start']);

                // POST /api/candidate/interview/suspend — pause: tear the
                // provider session down so it stops billing. There is no
                // matching /resume: suspend leaves the session in_corso with a
                // null ref, which is the state /start already resumes.
                Route::post('/suspend', [InterviewController::class, 'suspend']);

                // POST /api/candidate/interview/end — end session, reconcile, dispatch scoring (PR 3)
                Route::post('/end', [InterviewController::class, 'end']);

                // POST /api/candidate/interview/utterance — live transcript ingestion
                Route::post('/utterance', [UtteranceController::class, 'store']);

                // POST /api/candidate/interview/integrity — proctoring event batch
                Route::post('/integrity', [IntegrityController::class, 'store']);

                // POST /api/candidate/interview/snapshot — JPEG snapshot to S3
                Route::post('/snapshot', [SnapshotController::class, 'store']);
            });
    });

// ─── M2M Credential Management API (C5) ──────────────────────────────────────
// Admin-only CRUD for managing ApiClient credentials.
// Behind auth:api + global TenantContext (NOT inline — avoids double-execution).
// No inline TenantContext here — the global appendToGroup('api', ...) already supplies it.
// RBAC via ApiClientPolicy: admin-only (operator/viewer → 403).
// NO show endpoint — GET /api/m2m/clients/{id} → 404.

Route::middleware(['auth:api', TenantContext::class])->prefix('m2m')->group(function (): void {
    // The ability vocabulary a client may be granted. Published so the
    // backoffice can offer the real set instead of mirroring it in a constant
    // that would drift the moment an ability is added or removed.
    Route::get('/abilities', AbilityCatalogController::class);
    Route::post('/clients', [ApiClientController::class, 'store']);
    Route::get('/clients', [ApiClientController::class, 'index']);
    Route::delete('/clients/{apiClient}', [ApiClientController::class, 'destroy']);
    // Intentionally NO: Route::get('/clients/{apiClient}', ...) — returns 404 per design
});
