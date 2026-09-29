<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Declarative authorization matrix: EVERY `/api` route, who may call it, and
 * what each actor must get back.
 *
 * Hand-maintained on purpose (the `ExposureCatalogue` idiom). Computing it
 * from the router would make the coverage guard vacuous. Each entry is
 * derived by READING the controller / FormRequest / policy / middleware that
 * answers the route — not by guessing — and where that code looks like a bug
 * or is ambiguous the cell is {@see AuthMatrix::UNRESOLVED} and explained in
 * {@see self::knownQuestions()} instead of being encoded as truth.
 *
 * HOW AUTHORIZATION ACTUALLY WORKS HERE (so the cells can be audited)
 * -------------------------------------------------------------------
 *  - No Spatie role middleware on any route. Authorization is a controller /
 *    FormRequest `authorize()` against a policy (`hasRole`), or an explicit
 *    `is_superadmin` check for platform surfaces.
 *  - `Gate::before` (AppServiceProvider.php:156) answers TRUE for a
 *    superadmin on every ability, so a policy can never deny one; superadmin
 *    cells are therefore driven by tenancy, not by roles.
 *  - `TenantContext` runs on the `api` group: a user with an organization
 *    is scoped to it; a superadmin with none is "bare" (bypass ON); a
 *    superadmin acting as a client is scoped like a member of that client
 *    (`authTokenForRole('platform')`).
 *  - Order matters and is encoded: for id routes the org filter runs BEFORE
 *    the role check, so a foreign id is 404 for every role; only then does a
 *    wrong role get 403. Model-less abilities (`authorize('recover', …)`)
 *    run first, so a wrong role is 403 even on a foreign id.
 *
 * SCOPE OF THE EXPECTATIONS
 * -------------------------
 * A cell is the outcome of an otherwise VALID request (the target exists, in
 * the actor's org except for `cross_tenant_admin`; the payload validates).
 * `superadmin_bare` cells on writes are derived from static reading only and
 * are confirmed (or corrected) by the per-actor request tests that consume
 * this catalogue.
 *
 * KEY FORMAT: `<METHODS> <uri>` where METHODS is the route's verbs minus
 * HEAD joined with `|` (`PUT|PATCH api/projects/{project}`).
 */
final class AuthMatrixCatalogue
{
    private const A = AuthMatrix::ADMIN;

    private const O = AuthMatrix::OPERATOR;

    private const V = AuthMatrix::VIEWER;

    /**
     * Every `/api` route the router currently registers, in catalogue key
     * format. The coverage guard diffs this against {@see self::entries()}.
     *
     * `api/test-isolation/*` is excluded: those routes are a test fixture
     * registered only under APP_ENV=testing (AppServiceProvider.php:357-361),
     * never part of the shipped surface this matrix is about.
     *
     * @return list<string>
     */
    public static function registeredRouteKeys(): array
    {
        $keys = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api') || str_starts_with($route->uri(), 'api/test-isolation/')) {
                continue;
            }

            $keys[] = self::keyFor($route);
        }

        sort($keys);

        return $keys;
    }

    private static function keyFor(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return implode('|', $methods).' '.$route->uri();
    }

    /**
     * @return array<string, array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}>
     */
    public static function entries(): array
    {
        return [
            // ─── auth ────────────────────────────────────────────────────────
            'POST api/auth/forgot-password' => self::open('auth'),
            'POST api/auth/login' => self::open('auth'),
            'POST api/auth/logout' => self::own('auth'),
            'GET api/auth/me' => self::own('auth'),
            // Public by design: guarded by RequireRefreshCsrfHeader + the refresh cookie, no auth:api.
            'POST api/auth/refresh' => self::open('auth'),
            'POST api/auth/reset-password' => self::open('auth'),

            // ─── admin (platform surface: superadmin only) ───────────────────
            'PUT api/admin/acting-organization' => self::superadminOnly('admin'),
            'GET api/admin/clients' => self::superadminOnly('admin'),
            'GET api/admin/organizations' => self::superadminOnly('admin'),
            'POST api/admin/organizations' => self::superadminOnly('admin'),
            'GET api/admin/organizations/{id}' => self::superadminOnly('admin'),
            'PATCH api/admin/organizations/{id}' => self::superadminOnly('admin'),
            'GET api/admin/platform-users' => self::superadminOnly('admin'),
            'POST api/admin/platform-users' => self::superadminOnly('admin'),
            'PATCH api/admin/platform-users/{id}' => self::superadminOnly('admin'),
            'POST api/admin/platform-users/{id}/activate' => self::superadminOnly('admin'),
            'POST api/admin/platform-users/{id}/deactivate' => self::superadminOnly('admin'),
            'GET api/admin/settings' => self::superadminOnly('admin'),
            'PATCH api/admin/settings' => self::superadminOnly('admin'),

            // ─── catalogue (framework authoring: superadmin only, reads too) ─
            'GET api/catalogue/bars-indicators' => self::superadminOnly('catalogue'),
            'POST api/catalogue/bars-indicators' => self::superadminOnly('catalogue'),
            'PATCH api/catalogue/bars-indicators/{indicator}' => self::superadminOnly('catalogue'),
            'DELETE api/catalogue/bars-indicators/{indicator}' => self::superadminOnly('catalogue'),
            'GET api/catalogue/competencies' => self::superadminOnly('catalogue'),
            'POST api/catalogue/competencies' => self::superadminOnly('catalogue'),
            'PATCH api/catalogue/competencies/{competency}' => self::superadminOnly('catalogue'),
            'DELETE api/catalogue/competencies/{competency}' => self::superadminOnly('catalogue'),
            'GET api/catalogue/default-questions' => self::superadminOnly('catalogue'),
            'POST api/catalogue/default-questions' => self::superadminOnly('catalogue'),
            'PATCH api/catalogue/default-questions/{defaultQuestion}' => self::superadminOnly('catalogue'),
            'DELETE api/catalogue/default-questions/{defaultQuestion}' => self::superadminOnly('catalogue'),
            'GET api/catalogue/revisions/current' => self::superadminOnly('catalogue'),
            'POST api/catalogue/revisions/draft' => self::superadminOnly('catalogue'),
            'DELETE api/catalogue/revisions/draft' => self::superadminOnly('catalogue'),
            'POST api/catalogue/revisions/publish' => self::superadminOnly('catalogue'),
            'GET api/catalogue/roles' => self::superadminOnly('catalogue'),
            'POST api/catalogue/roles' => self::superadminOnly('catalogue'),
            'PATCH api/catalogue/roles/{role}' => self::superadminOnly('catalogue'),
            'DELETE api/catalogue/roles/{role}' => self::superadminOnly('catalogue'),
            'PUT api/catalogue/roles/{role}/competencies' => self::superadminOnly('catalogue'),

            // ─── llm credentials / models ────────────────────────────────────
            'GET api/llm-credentials' => self::superadminOnly('llm'),
            'POST api/llm-credentials' => self::superadminOnly('llm'),
            'GET api/llm-credentials/{id}' => self::superadminOnly('llm'),
            'PATCH api/llm-credentials/{id}' => self::superadminOnly('llm'),
            'DELETE api/llm-credentials/{id}' => self::superadminOnly('llm'),
            // No policy on purpose (LlmModelController.php:15-19): a global read-only price list
            // for any authenticated user of any organization.
            'GET api/llm-models' => self::user('llm', self::orgScoped([self::A, self::O, self::V], noRole: AuthMatrix::ALLOW)),

            // ─── framework (global read-only catalogue for any authenticated user) ──
            'GET api/framework/potential-competencies' => self::user('framework', self::authenticatedRead()),
            'GET api/framework/roles' => self::user('framework', self::authenticatedRead()),
            'GET api/framework/roles/{roleCode}/competencies' => self::user('framework', self::authenticatedRead()),
            'GET api/framework/roles/{roleCode}/competencies/{competencyCode}/indicators' => self::user('framework', self::authenticatedRead()),
            'GET api/framework/versions' => self::user('framework', self::authenticatedRead()),

            // ─── avatar templates ────────────────────────────────────────────
            // Reads (`viewAny`/`view`) are admin-only; the four writes are
            // denied to EVERY role (AvatarTemplatePolicy) and reachable only
            // through Gate::before, i.e. by a superadmin. `options` alone is
            // open to all three roles.
            'GET api/avatar-templates' => self::user('avatar-templates', self::orgScoped([self::A])),
            'POST api/avatar-templates' => self::user('avatar-templates', self::superadminWrite(bare: AuthMatrix::UNRESOLVED)),
            'GET api/avatar-templates/catalogue' => self::user('avatar-templates', self::orgScoped([self::A])),
            'GET api/avatar-templates/export' => self::user('avatar-templates', self::superadminWrite()),
            'GET api/avatar-templates/field-specs' => self::user('avatar-templates', self::orgScoped([self::A])),
            'POST api/avatar-templates/import' => self::user('avatar-templates', self::superadminWrite(bare: AuthMatrix::UNRESOLVED)),
            'GET api/avatar-templates/options' => self::user('avatar-templates', self::orgScoped([self::A, self::O, self::V])),
            'GET api/avatar-templates/{id}' => self::user('avatar-templates', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND)),
            'PATCH api/avatar-templates/{id}' => self::user('avatar-templates', self::superadminWrite(cross: AuthMatrix::NOT_FOUND)),
            'DELETE api/avatar-templates/{id}' => self::user('avatar-templates', self::superadminWrite(cross: AuthMatrix::NOT_FOUND)),
            'POST api/avatar-templates/{id}/activate' => self::user('avatar-templates', self::superadminWrite(cross: AuthMatrix::NOT_FOUND)),
            'POST api/avatar-templates/{id}/deactivate' => self::user('avatar-templates', self::superadminWrite(cross: AuthMatrix::NOT_FOUND)),

            // ─── organization (own organization) ─────────────────────────────
            'GET api/organization' => self::user('organization', self::orgScoped([self::A, self::O, self::V])),
            'PATCH api/organization' => self::user('organization', self::orgScoped([self::A], bare: AuthMatrix::UNRESOLVED)),
            'POST api/organization/logo' => self::user('organization', self::orgScoped([self::A], bare: AuthMatrix::UNRESOLVED)),
            'DELETE api/organization/logo' => self::user('organization', self::orgScoped([self::A], bare: AuthMatrix::UNRESOLVED)),
            // Public on purpose: a branded login page must render before anyone signs in.
            'GET api/organizations/{organization}/logo' => self::open('organization', AuthMatrix::TENANCY_NONE),

            // ─── users (admin only) ──────────────────────────────────────────
            'GET api/users' => self::user('users', self::orgScoped([self::A])),
            'POST api/users' => self::user('users', self::orgScoped([self::A], bare: AuthMatrix::CONFLICT)),
            'PATCH api/users/{user}' => self::user('users', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::CONFLICT)),
            'POST api/users/{user}/activate' => self::user('users', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::UNRESOLVED)),
            'POST api/users/{user}/deactivate' => self::user('users', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::UNRESOLVED)),

            // ─── projects ────────────────────────────────────────────────────
            'GET api/projects' => self::user('projects', self::orgScoped([self::A, self::O, self::V])),
            // A bare superadmin has no organization whose framework version / avatar template
            // it may reference, so the (org-scoped) validation refuses: 422, and nothing is created.
            'POST api/projects' => self::user('projects', self::orgScoped([self::A, self::O], bare: AuthMatrix::UNPROCESSABLE)),
            'GET api/projects/{project}' => self::user('projects', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND)),
            // Under bypass a bare superadmin sees (and may edit) every tenant's project.
            'PUT|PATCH api/projects/{project}' => self::user('projects', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND)),
            'DELETE api/projects/{project}' => self::user('projects', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND)),

            // ─── project questions (read = view, every write = `update` on the project) ──
            'GET api/projects/{project}/questions' => self::user('project-questions', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND)),
            'POST api/projects/{project}/questions' => self::user('project-questions', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::UNRESOLVED)),
            'PUT api/projects/{project}/questions/order' => self::user('project-questions', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND)),
            'PATCH api/projects/{project}/questions/{question}' => self::user('project-questions', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND)),
            'DELETE api/projects/{project}/questions/{question}' => self::user('project-questions', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND)),

            // ─── participants (admin read API) ───────────────────────────────
            // AdminParticipantReader filters by the RESOLVED org id, so a
            // superadmin with no acting client sees an empty list and 404 on
            // every id (informational question KQ-I3).
            'GET api/participants' => self::user('participants', self::orgScoped([self::A, self::O, self::V])),
            'GET api/participants/{id}' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'GET api/participants/{id}/evaluation' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'GET api/participants/{id}/evaluation/download' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'GET api/participants/{id}/transcript' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'GET api/participants/{id}/transcript/download' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'GET api/participants/{participant}/sessions' => self::user('participants', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            // Model-less ability first: a wrong role is 403 even for a foreign id, then the org filter 404s.
            'POST api/participants/{id}/recover' => self::user('participants', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'PATCH api/participants/{id}/schedule' => self::user('participants', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            'DELETE api/participants/{id}/schedule' => self::user('participants', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),
            // Admin only (`audit` ability). NB: the kill switch answers 409 BEFORE authorization (informational KQ-I1).
            'POST api/participants/{id}/evaluation/audit' => self::user('participants', self::orgScoped([self::A], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),

            // ─── interview sessions ──────────────────────────────────────────
            'GET api/interview-sessions/{session}/review' => self::user('sessions', self::orgScoped([self::A, self::O, self::V], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::NOT_FOUND)),

            // ─── evaluations index ───────────────────────────────────────────
            'GET api/evaluations' => self::user('evaluations', self::orgScoped([self::A, self::O, self::V])),
            'GET api/evaluations/summary' => self::user('evaluations', self::orgScoped([self::A, self::O, self::V])),

            // ─── dashboard (same `viewAny` gate as the participant list) ─────
            'GET api/dashboard/activity' => self::user('dashboard', self::orgScoped([self::A, self::O, self::V])),
            'GET api/dashboard/metrics' => self::user('dashboard', self::orgScoped([self::A, self::O, self::V], bare: AuthMatrix::UNRESOLVED)),

            // ─── entry links (candidate invitation) ──────────────────────────
            'POST api/entry-links' => self::user('entry-links', self::orgScoped([self::A, self::O], cross: AuthMatrix::NOT_FOUND, bare: AuthMatrix::UNRESOLVED)),

            // ─── profile (the caller's own account: any authenticated user) ──
            'GET api/profile' => self::own('profile'),
            'PATCH api/profile' => self::own('profile'),
            'PUT api/profile/password' => self::own('profile'),
            'POST api/profile/photo' => self::own('profile'),
            'DELETE api/profile/photo' => self::own('profile'),

            // ─── m2m client management (user JWT, admin only) ────────────────
            'GET api/m2m/abilities' => self::user('m2m-admin', self::orgScoped([self::A])),
            'GET api/m2m/clients' => self::user('m2m-admin', self::orgScoped([self::A])),
            'POST api/m2m/clients' => self::user('m2m-admin', self::orgScoped([self::A], bare: AuthMatrix::CONFLICT)),
            // ApiClient is not a tenant model: a foreign key is 403 by policy (org mismatch), not 404 (informational KQ-I2).
            'DELETE api/m2m/clients/{apiClient}' => self::user('m2m-admin', self::orgScoped([self::A], cross: AuthMatrix::FORBIDDEN)),

            // ─── m2m (API key + ability) ─────────────────────────────────────
            'POST api/m2m/participants' => self::m2m('participants:create'),
            'GET api/m2m/participants' => self::m2m('participants:read'),
            'GET api/m2m/participants/{id}' => self::m2m('participants:read'),
            'PATCH api/m2m/participants/{id}/schedule' => self::m2m('participants:schedule'),
            'DELETE api/m2m/participants/{id}/schedule' => self::m2m('participants:schedule'),
            'POST api/m2m/sso-link' => self::m2m('sso_link:generate'),
            'GET api/m2m/whoami' => self::m2m(null),

            // ─── candidate (candidate JWT) ───────────────────────────────────
            'POST api/candidate/interview/end' => self::candidate(true),
            'POST api/candidate/interview/integrity' => self::candidate(true),
            'POST api/candidate/interview/snapshot' => self::candidate(true),
            'POST api/candidate/interview/start' => self::candidate(true),
            'POST api/candidate/interview/suspend' => self::candidate(true),
            'POST api/candidate/interview/utterance' => self::candidate(true),
            // No ParticipantStatusGuard: a finished candidate may still read the session.
            'GET api/candidate/session' => self::candidate(false),

            // ─── embed / sso / health (public, token- or throttle-guarded) ───
            'GET api/embed/exchange' => self::open('embed'),
            'GET api/embed/frame-policy' => self::open('embed'),
            'GET api/sso/exchange' => self::open('sso'),
            'GET api/health' => self::open('health'),
            'GET api/health/queue' => self::open('health'),
            'GET api/v1/health' => self::open('public-api-v1'),

            // ─── public API v1 (API key + scope) ─────────────────────────────
            'POST api/v1/exports' => self::publicApi('exports:write'),
            'GET api/v1/exports' => self::publicApi('exports:read'),
            'GET api/v1/exports/{id}' => self::publicApi('exports:read'),
            'POST api/v1/interviews' => self::publicApi('interviews:write'),
            'GET api/v1/interviews' => self::publicApi('interviews:read'),
            'GET api/v1/interviews/{interview}' => self::publicApi('interviews:read'),
            'GET api/v1/interviews/{interview}/answers' => self::publicApi('interviews:read'),
            'GET api/v1/interviews/{interview}/events' => self::publicApi('interviews:read'),
            'GET api/v1/interviews/{interview}/recording' => self::publicApi('recordings:read'),
            'GET api/v1/interviews/{interview}/scoring' => self::publicApi('interviews:read'),
            'POST api/v1/interviews/{interview}/session-tokens' => self::publicApi('interviews:write'),
            'GET api/v1/interviews/{interview}/transcript' => self::publicApi('interviews:read'),
            // The one authenticated route with no scope: any valid key may read its own organization.
            'GET api/v1/organization' => self::publicApi(null),
            'GET api/v1/projects' => self::publicApi('projects:read'),
            'GET api/v1/projects/{project}' => self::publicApi('projects:read'),
            'GET api/v1/usage' => self::publicApi('usage:read'),
            'GET api/v1/webhooks/deliveries' => self::publicApi('webhooks:read'),
            'POST api/v1/webhooks/deliveries/{id}/redeliver' => self::publicApi('webhooks:write'),
        ];
    }

    /**
     * Open questions and observations found while deriving the matrix.
     *
     * A question with `cells` owns those `UNRESOLVED` cells (the guard checks
     * both directions). A question with no cells is informational: the
     * behaviour is encoded as-is but is worth a product decision.
     *
     * @return array<string, array{summary: string, evidence: list<string>, cells: array<string, list<string>>}>
     */
    public static function knownQuestions(): array
    {
        $bare = [AuthMatrix::SUPERADMIN_BARE];

        return [
            'KQ-1' => [
                'summary' => 'A superadmin with no acting client that creates a tenant-scoped row gets an uncaught '
                    .'MissingTenantContextException, i.e. a 500. There is no exception renderer for it, while the '
                    .'sibling surfaces (POST /users, POST /m2m/clients) were fixed to answer a legible 409.',
                'evidence' => [
                    'app/Models/Concerns/TenantScoped.php:77',
                    'app/Http/Controllers/AvatarTemplatePortabilityController.php:158-159',
                    'app/Http/Controllers/AvatarTemplateController.php:216',
                    'app/Http/Controllers/Api/ProjectQuestionController.php:111-118',
                    'app/Http/Controllers/Api/UserController.php:288 (the fixed 409, for contrast)',
                ],
                'cells' => [
                    'POST api/avatar-templates' => $bare,
                    'POST api/avatar-templates/import' => $bare,
                    'POST api/projects/{project}/questions' => $bare,
                ],
            ],
            'KQ-2' => [
                'summary' => 'A superadmin with no acting client on org-scoped routes gets a DIFFERENT status per '
                    .'route for the same missing-context condition: 403 (PATCH /organization: find(null) is falsy so '
                    .'the FormRequest refuses), 404 (organization logo: findOrFail(null); user activate/deactivate: '
                    .'organization_id IS NULL matches nothing), 409 (user store/update, api-client create). '
                    .'Gate::before never gets to help because the route fails earlier.',
                'evidence' => [
                    'app/Http/Requests/UpdateOrganizationRequest.php:34-36',
                    'app/Http/Controllers/Api/OrganizationLogoController.php:142,243',
                    'app/Support/Users/UserAdminReader.php (baseQuery org filter)',
                    'app/Http/Requests/UpdateUserRequest.php:36-40 (the 409 that activate/deactivate lack)',
                ],
                'cells' => [
                    'PATCH api/organization' => $bare,
                    'POST api/organization/logo' => $bare,
                    'DELETE api/organization/logo' => $bare,
                    'POST api/users/{user}/activate' => $bare,
                    'POST api/users/{user}/deactivate' => $bare,
                ],
            ],
            'KQ-3' => [
                'summary' => 'Bare-superadmin writes whose outcome static reading could not settle (validation '
                    .'rules bind to the resolved org id, which is null; the target row is visible under bypass but '
                    .'the org-scoped rules are not). SETTLED by real requests in the matrix tests: '
                    .'POST /projects answers 422 (nothing to reference) and PATCH /projects/{project} answers 200 '
                    .'(bypass reaches the row); both are now encoded. Still open: the entry-link mint.',
                'evidence' => [
                    'app/Http/Requests/StoreProjectRequest.php:77-88',
                    'app/Http/Requests/UpdateProjectRequest.php:124',
                    'app/Http/Controllers/Api/EntryLinkController.php:110',
                ],
                'cells' => [
                    'POST api/entry-links' => $bare,
                ],
            ],
            'KQ-4' => [
                'summary' => 'GET /dashboard/metrics under a bare superadmin mixes scopes: participant counts use '
                    .'the explicit org filter (empty), while evaluations_by_status and the AI latency/token/cost '
                    .'totals use TenantModel queries that bypass the scope, so they aggregate EVERY tenant. The '
                    .'same response describes two different populations.',
                'evidence' => [
                    'app/Http/Controllers/Api/DashboardController.php:107-113 (participants, org IS NULL)',
                    'app/Http/Controllers/Api/DashboardController.php:119,125,136-137,165 (Evaluation/AiRequest under bypass)',
                    'app/Support/Admin/AdminParticipantReader.php:87',
                ],
                'cells' => [
                    'GET api/dashboard/metrics' => $bare,
                ],
            ],
            'KQ-I1' => [
                'summary' => 'Informational: POST /participants/{id}/evaluation/audit checks the scoring.audit.enabled '
                    .'kill switch BEFORE authorization, so while the audit is disabled ANY authenticated user '
                    .'(viewer, role-less) gets 409 audit_disabled instead of 403. Documented as deliberate '
                    .'("the platform state refuses, not the caller\'s role"); the matrix encodes the enabled state.',
                'evidence' => ['app/Http/Controllers/Api/EvaluationAuditController.php:71-80'],
                'cells' => [],
            ],
            'KQ-I2' => [
                'summary' => 'Informational: DELETE /m2m/clients/{apiClient} binds a non-tenant model, so a foreign '
                    .'organization\'s key id answers 403 (policy org mismatch) while an unknown id answers 404 — the '
                    .'existence oracle every tenant-scoped route avoids. And Gate::before lets ANY superadmin '
                    .'(bare or acting) revoke any organization\'s key, bypassing the org check in the policy.',
                'evidence' => [
                    'app/Policies/ApiClientPolicy.php:57-64',
                    'app/Http/Controllers/M2m/ApiClientController.php:203-205',
                    'app/Providers/AppServiceProvider.php:156',
                ],
                'cells' => [],
            ],
            'KQ-I3' => [
                'summary' => 'Informational: every admin participant read (list, detail, transcript, evaluation, '
                    .'downloads, sessions, session review, recover, schedule, audit) filters by the resolved org id, '
                    .'so a superadmin with no acting client sees an empty list and 404 on every id, unlike '
                    .'projects/avatar templates where bypass shows every tenant. Consistent within the participant '
                    .'surface (encoded), inconsistent across surfaces.',
                'evidence' => [
                    'app/Support/Admin/AdminParticipantReader.php:49,87',
                    'app/Http/Controllers/Api/SessionReviewController.php:61,83',
                    'app/Actions/Participant/RecoverFailedParticipant.php:79',
                ],
                'cells' => [],
            ],
            'KQ-I4' => [
                'summary' => 'Informational: GET /framework/* and GET /llm-models have no policy, so an organization '
                    .'user holding NO role can read them (documented as "readable by all three roles"). Harmless '
                    .'global data; encoded as allowed for role-less users.',
                'evidence' => [
                    'app/Http/Controllers/Api/LlmModelController.php:15-19',
                    'app/Http/Controllers/Api/FrameworkController.php:106',
                ],
                'cells' => [],
            ],
        ];
    }

    // ─── entry builders ──────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $outcomes
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function user(string $domain, array $outcomes, string $tenancy = AuthMatrix::TENANCY_ORG_SCOPED): array
    {
        return [
            'domain' => $domain,
            'auth' => AuthMatrix::AUTH_JWT_USER,
            'tenancy' => $tenancy,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function superadminOnly(string $domain): array
    {
        $none = AuthMatrix::FORBIDDEN;

        return self::user($domain, self::withForeignCredentials([
            AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
            AuthMatrix::ADMIN => $none,
            AuthMatrix::OPERATOR => $none,
            AuthMatrix::VIEWER => $none,
            AuthMatrix::NO_ROLE => $none,
            AuthMatrix::CROSS_TENANT_ADMIN => $none,
            AuthMatrix::SUPERADMIN_BARE => AuthMatrix::ALLOW,
            AuthMatrix::SUPERADMIN_ACTING => AuthMatrix::ALLOW,
        ]), AuthMatrix::TENANCY_SUPERADMIN_ONLY);
    }

    /**
     * The caller's own identity: any authenticated user may call it.
     *
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function own(string $domain): array
    {
        return self::user($domain, self::authenticatedRead(), AuthMatrix::TENANCY_SELF);
    }

    /**
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function open(string $domain, string $tenancy = AuthMatrix::TENANCY_NONE): array
    {
        return [
            'domain' => $domain,
            'auth' => AuthMatrix::AUTH_PUBLIC,
            'tenancy' => $tenancy,
            'outcomes' => [AuthMatrix::ANONYMOUS => AuthMatrix::OPEN],
        ];
    }

    /**
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function m2m(?string $ability): array
    {
        return [
            'domain' => 'm2m',
            'auth' => AuthMatrix::AUTH_M2M,
            'tenancy' => AuthMatrix::TENANCY_SELF,
            'outcomes' => [
                AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::KEY_WITH_ABILITY => AuthMatrix::ALLOW,
                // `whoami` demands no ability: every valid live key passes.
                AuthMatrix::KEY_WITHOUT_ABILITY => $ability === null ? AuthMatrix::ALLOW : AuthMatrix::FORBIDDEN,
                // The internal M2M guard never authenticates a `beai_test_` key.
                AuthMatrix::TEST_MODE_KEY => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::USER_JWT => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::CANDIDATE_JWT => AuthMatrix::UNAUTHENTICATED_401,
            ],
        ];
    }

    /**
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function candidate(bool $statusGuarded): array
    {
        return [
            'domain' => 'candidate',
            'auth' => AuthMatrix::AUTH_CANDIDATE,
            'tenancy' => AuthMatrix::TENANCY_SELF,
            'outcomes' => [
                AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::CANDIDATE_ACTIVE => AuthMatrix::ALLOW,
                // ParticipantStatusGuard: completato/errore may no longer act on the interview.
                AuthMatrix::CANDIDATE_TERMINAL => $statusGuarded ? AuthMatrix::FORBIDDEN : AuthMatrix::ALLOW,
                AuthMatrix::USER_JWT => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::API_KEY => AuthMatrix::UNAUTHENTICATED_401,
            ],
        ];
    }

    /**
     * @return array{domain: string, auth: string, tenancy: string, outcomes: array<string, string>}
     */
    private static function publicApi(?string $scope): array
    {
        return [
            'domain' => 'public-api-v1',
            'auth' => AuthMatrix::AUTH_PUBLIC_API_KEY,
            'tenancy' => AuthMatrix::TENANCY_SELF,
            'outcomes' => [
                AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::KEY_WITH_SCOPE => AuthMatrix::ALLOW,
                AuthMatrix::KEY_WITHOUT_SCOPE => $scope === null ? AuthMatrix::ALLOW : AuthMatrix::FORBIDDEN,
                // Unlike the internal M2M guard, `/v1` accepts test-mode keys.
                AuthMatrix::TEST_MODE_KEY => AuthMatrix::ALLOW,
                // A live key must never be usable from a browser context.
                AuthMatrix::LIVE_KEY_BROWSER_ORIGIN => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::USER_JWT => AuthMatrix::UNAUTHENTICATED_401,
                AuthMatrix::CANDIDATE_JWT => AuthMatrix::UNAUTHENTICATED_401,
            ],
        ];
    }

    // ─── outcome-map builders for jwt-user routes ────────────────────────────

    /**
     * Org-scoped route gated by roles.
     *
     * @param  list<string>  $roles  roles that pass the policy (admin/operator/viewer)
     * @param  string|null  $cross  what an admin of ANOTHER org gets; defaults to
     *                              what an admin of the target's own org gets
     *                              (collection routes have no foreign target)
     * @param  string  $bare  superadmin with no acting client
     * @param  string  $noRole  an org user holding no role at all
     * @return array<string, string>
     */
    private static function orgScoped(
        array $roles,
        ?string $cross = null,
        string $bare = AuthMatrix::ALLOW,
        string $noRole = AuthMatrix::FORBIDDEN,
    ): array {
        $pass = static fn (string $role): string => in_array($role, $roles, true) ? AuthMatrix::ALLOW : AuthMatrix::FORBIDDEN;

        return self::withForeignCredentials([
            AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
            AuthMatrix::ADMIN => $pass(self::A),
            AuthMatrix::OPERATOR => $pass(self::O),
            AuthMatrix::VIEWER => $pass(self::V),
            AuthMatrix::NO_ROLE => $noRole,
            AuthMatrix::CROSS_TENANT_ADMIN => $cross ?? $pass(self::A),
            AuthMatrix::SUPERADMIN_BARE => $bare,
            AuthMatrix::SUPERADMIN_ACTING => AuthMatrix::ALLOW,
        ]);
    }

    /**
     * A write that AvatarTemplatePolicy denies to every role: only a
     * superadmin (via Gate::before) may perform it.
     *
     * @return array<string, string>
     */
    private static function superadminWrite(?string $cross = null, string $bare = AuthMatrix::ALLOW): array
    {
        return self::withForeignCredentials([
            AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
            AuthMatrix::ADMIN => AuthMatrix::FORBIDDEN,
            AuthMatrix::OPERATOR => AuthMatrix::FORBIDDEN,
            AuthMatrix::VIEWER => AuthMatrix::FORBIDDEN,
            AuthMatrix::NO_ROLE => AuthMatrix::FORBIDDEN,
            AuthMatrix::CROSS_TENANT_ADMIN => $cross ?? AuthMatrix::FORBIDDEN,
            AuthMatrix::SUPERADMIN_BARE => $bare,
            AuthMatrix::SUPERADMIN_ACTING => AuthMatrix::ALLOW,
        ]);
    }

    /**
     * Any authenticated user passes, whatever their role (or lack of one).
     *
     * @return array<string, string>
     */
    private static function authenticatedRead(): array
    {
        return self::withForeignCredentials([
            AuthMatrix::UNAUTHENTICATED => AuthMatrix::UNAUTHENTICATED_401,
            AuthMatrix::ADMIN => AuthMatrix::ALLOW,
            AuthMatrix::OPERATOR => AuthMatrix::ALLOW,
            AuthMatrix::VIEWER => AuthMatrix::ALLOW,
            AuthMatrix::NO_ROLE => AuthMatrix::ALLOW,
            AuthMatrix::CROSS_TENANT_ADMIN => AuthMatrix::ALLOW,
            AuthMatrix::SUPERADMIN_BARE => AuthMatrix::ALLOW,
            AuthMatrix::SUPERADMIN_ACTING => AuthMatrix::ALLOW,
        ]);
    }

    /**
     * A candidate JWT (typ=candidate) or an M2M key presented to a user-JWT
     * route is refused at the `api` guard, whatever the route.
     *
     * @param  array<string, string>  $outcomes
     * @return array<string, string>
     */
    private static function withForeignCredentials(array $outcomes): array
    {
        return $outcomes + [
            AuthMatrix::CANDIDATE_JWT => AuthMatrix::UNAUTHENTICATED_401,
            AuthMatrix::API_KEY => AuthMatrix::UNAUTHENTICATED_401,
        ];
    }
}
