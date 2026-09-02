<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EntryLinkController;
use App\Http\Controllers\Api\EvaluationIndexController;
use App\Http\Controllers\Api\FrameworkController;
use App\Http\Controllers\Api\LlmCredentialController;
use App\Http\Controllers\Api\LlmModelController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationLogoController;
use App\Http\Controllers\Api\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Api\ParticipantDownloadController;
use App\Http\Controllers\Api\ParticipantRecoveryController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectQuestionController;
use App\Http\Controllers\Api\SessionReviewController;
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
use App\Http\Controllers\HealthController;
use App\Http\Controllers\M2m\AbilityCatalogController;
use App\Http\Controllers\M2m\ApiClientController;
use App\Http\Controllers\M2m\ParticipantController;
use App\Http\Controllers\M2m\SsoLinkController;
use App\Http\Controllers\M2m\WhoamiController;
use App\Http\Controllers\QueueHealthController;
use App\Http\Controllers\Sso\SsoExchangeController;
use App\Http\Middleware\ParticipantStatusGuard;
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
    Route::post('/organization/logo', [OrganizationLogoController::class, 'store']);
    Route::delete('/organization/logo', [OrganizationLogoController::class, 'destroy']);
});

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
