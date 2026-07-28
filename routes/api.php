<?php

declare(strict_types=1);

// TODO(D33): Versioning contract — additive changes are non-breaking;
// breaking changes require a new /api/v2/ prefix, coordinated across consumers.
// See docs/api-versioning.md for the full contract.

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FrameworkController;
use App\Http\Controllers\Api\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Api\ParticipantDownloadController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Candidate\IntegrityController;
use App\Http\Controllers\Candidate\InterviewController;
use App\Http\Controllers\Candidate\SessionController;
use App\Http\Controllers\Candidate\SnapshotController;
use App\Http\Controllers\Candidate\UtteranceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\M2m\ApiClientController;
use App\Http\Controllers\M2m\ParticipantController;
use App\Http\Controllers\M2m\SsoLinkController;
use App\Http\Controllers\M2m\WhoamiController;
use App\Http\Controllers\QueueHealthController;
use App\Http\Controllers\Sso\SsoExchangeController;
use App\Http\Middleware\ParticipantStatusGuard;
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

// ─── Auth routes (C2) ────────────────────────────────────────────────────────
// POST /api/auth/login is public (no auth middleware).
// All other auth routes use auth:api explicitly — NEVER bare `auth` middleware,
// which would silently fall back to the `web` session guard.

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function (): void {
        Route::post('/refresh', [AuthController::class, 'refresh']);
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

// ─── Project Configuration API (C4) ──────────────────────────────────────────
// Org-scoped Project CRUD. Behind auth:api + TenantContext middleware.
// RBAC via ProjectPolicy: admin/operator full CRUD; viewer read-only.
// destroy → HTTP 204 No Content (soft-delete; FV lock preserved).

Route::middleware(['auth:api', TenantContext::class])->group(function (): void {
    Route::apiResource('projects', ProjectController::class);
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

    Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);
});

// ─── M2M Machine Routes (C5) ─────────────────────────────────────────────────
// Machine-to-machine API endpoints authenticated via opaque API-key (auth:api-m2m).
//
// CRITICAL route isolation:
//   withoutMiddleware(TenantContext::class) strips the globally-appended human
//   TenantContext (bootstrap/app.php:24) from this group — without this, the
//   human TenantContext would silently pass through on a null User, potentially
//   leaving the resolver in a stale/null state.
//
// Inline middleware stack (explicit, ordered):
//   1. auth:api-m2m       — resolves ApiClient via bearer key
//   2. TenantContextM2m   — stamps TenantResolver from client.organization_id
//   3. SubstituteBindings — route-model-binding (LAST, per C4 convention)
//
// Admin credential-management routes are NOT here — they use auth:api + global
// TenantContext (see admin group below).

Route::prefix('m2m')
    ->withoutMiddleware(TenantContext::class)
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

Route::get('/sso/exchange', [SsoExchangeController::class, 'exchange'])
    ->withoutMiddleware(TenantContext::class);

// ─── Candidate Routes (C6) ───────────────────────────────────────────────────
// Protected by auth:api-candidate → TenantContextCandidate → SubstituteBindings.
// withoutMiddleware(TenantContext::class) strips the globally-appended human
// TenantContext — same isolation as M2M routes.
//
// Middleware stack (explicit, ordered):
//   1. auth:api-candidate      — resolves Participant via candidate JWT
//   2. TenantContextCandidate  — stamps TenantResolver from participant.organization_id
//   3. SubstituteBindings      — route-model-binding (LAST)

Route::prefix('candidate')
    ->withoutMiddleware(TenantContext::class)
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
    Route::post('/clients', [ApiClientController::class, 'store']);
    Route::get('/clients', [ApiClientController::class, 'index']);
    Route::delete('/clients/{apiClient}', [ApiClientController::class, 'destroy']);
    // Intentionally NO: Route::get('/clients/{apiClient}', ...) — returns 404 per design
});
