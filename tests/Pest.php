<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

// C2 feature tests use RefreshDatabase scoped to this directory only.
// HealthTest lives under Feature/ (not Feature/C2/) and remains DB-free.
// Uses ->use() (not ->extend()) to avoid re-declaring TestCase for a sub-directory.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C2');

// Wire the base TestCase to Unit/Testing so withCassette() and other
// helpers are available in unit tests that need them (D36 cassette pattern).
pest()->extend(TestCase::class)
    ->in('Unit/Testing');

// Wire the base TestCase to Unit/C2 so service providers boot and app() helpers work.
pest()->extend(TestCase::class)
    ->in('Unit/C2');

// Wire the base TestCase to Arch tests so app() helpers and class resolution work.
pest()->extend(TestCase::class)
    ->in('Arch');

// ─── C3 Framework Catalog ─────────────────────────────────────────────────────

// Unit tests for C3 models (schema inspection, translatable logic).
// Uses ->use() to avoid re-declaring TestCase (already in Unit/C2 as ->extend()).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Models');

// Feature/Models uses ->use() (not ->extend()) to avoid re-declaring TestCase
// (Feature/ already extends TestCase above).
pest()->use(RefreshDatabase::class)
    ->in('Feature/Models');

// Feature/Seeders — RefreshDatabase for seeder idempotency tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/Seeders');

// Unit tests for C3 services/adapters.
pest()->extend(TestCase::class)
    ->in('Unit/Services');

// Feature/Api — RefreshDatabase for API + tenant isolation tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/Api');

// ─── C4 Project Configuration ─────────────────────────────────────────────────

// Feature/C4 — RefreshDatabase for schema, seeder-guard, CRUD, RBAC, and invariant tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C4');

// Unit/C4 — needs TestCase + RefreshDatabase for model guard tests (model events require DB).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/C4');

// ─── C5 M2M API Authentication ───────────────────────────────────────────────

// Unit/C5 — needs TestCase so service providers boot and app() helpers work.
// Note: model-creation tests are in Feature/C5 to avoid RefreshDatabase conflicts.
pest()->extend(TestCase::class)
    ->in('Unit/C5');

// Feature/C5 — RefreshDatabase for guard, isolation, and revocation tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C5');

// ─── C6 Participant + SSO Ingress ─────────────────────────────────────────────

// Unit/C6 — needs TestCase + RefreshDatabase (model guard tests require DB).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/C6');

// Feature/C6 — RefreshDatabase for exchange flow, guard matrix, and isolation tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C6');

// Arch/C6 — covered by the global Arch extension above (TestCase already in Arch/).

// ─── C7a Interview Session Mechanics ──────────────────────────────────────────

// Unit/C7a — needs TestCase + RefreshDatabase (model guard tests require DB).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/C7a');

// Feature/C7a — RefreshDatabase for schema, model, lifecycle, and isolation tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C7a');

// ─── C8 Interview Conversation ───────────────────────────────────────────────

// Unit/C8 — needs TestCase + RefreshDatabase (BarsIndicatorLoader, SystemPromptComposer
// unit tests hit the DB via factories for isolation scenarios).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/C8');

// Feature/C8 — RefreshDatabase for controller composition wiring + provider payload tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C8');

// ─── C9 Scoring Engine ────────────────────────────────────────────────────────

// Unit/Models (C9 schema assertions) — already covered by the Unit/Models block above.

// Feature/Jobs — RefreshDatabase for job guard, failed(), lifecycle, and isolation tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/Jobs');

// ─── queued-job-tenancy (C9 retrofit) ─────────────────────────────────────────

// Unit/Support/Tenancy — needs TestCase so app() helpers (scoped TenantResolver
// binding, PermissionRegistrar container resolution) work for TenantContextScope tests.
pest()->extend(TestCase::class)
    ->in('Unit/Support/Tenancy');

// ─── C11 Admin Dashboards ─────────────────────────────────────────────────────

// Unit/Support/Admin — needs TestCase + RefreshDatabase: AdminParticipantReaderTest
// creates Organization/User/Participant via factories and authenticates via Gate.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Support/Admin');

// Unit/Services/Admin — needs RefreshDatabase: AdminEvaluationSerializer/
// AdminTranscriptSerializer tests build Participant/Project/Evaluation/CompetencyResult/
// IndicatorScore/InterviewSession/Utterance fixtures via factories (PR A2).
// TestCase is already bound by the broader Unit/Services block above (->use()
// only here, not ->extend() — re-declaring the same TestCase binding for a
// subdirectory already covered by a parent ->in() errors at suite build time).
pest()->use(RefreshDatabase::class)
    ->in('Unit/Services/Admin');

// Feature/C11 — RefreshDatabase for the admin controllers/routes feature-test
// matrix (PR A3): cross-org, lifecycle gate matrix, RBAC, download headers,
// route surface, dashboard metrics.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C11');

// Feature/Models (C9 versioning + cross-tenant) — RefreshDatabase.
// Note: Feature/Models already has RefreshDatabase from the C3 block above.

// ─── C10 Webhooks Integration ─────────────────────────────────────────────────

// Unit/C10 — needs TestCase + RefreshDatabase: signer/redactor/assembler unit tests are
// DB-free, but model and config-invariant unit tests across this PR chain hit factories
// and app() bindings, so the directory is wired once up front (S18).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/C10');

// Feature/C10 — RefreshDatabase for schema, delivery-gate, dedupe, and job feature tests.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C10');

// ─── queue-worker-scheduler (infrastructure) ──────────────────────────────────

// Unit/QueueRuntimeConfigTest.php sits directly in Unit/ (config-invariant
// reflection test, no DB needed) — needs TestCase so config() resolves
// against the booted app.
pest()->extend(TestCase::class)
    ->in('Unit/QueueRuntimeConfigTest.php');

// Unit/Support/Queue (PR4) — needs TestCase + RefreshDatabase: the reserved-
// job-age probe's database-driver path inserts fake rows into the `jobs`
// table via DB::table(), needs a real schema.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Support/Queue');

// Feature/Health (PR4) — RefreshDatabase for the queue health endpoint
// feature tests (failed_jobs count assertions).
pest()->use(RefreshDatabase::class)
    ->in('Feature/Health');

// ─── notifications-reminders (C12) ────────────────────────────────────────────

// Unit/NotificationsConfigTest.php sits directly in Unit/ (config-invariant
// test, no DB needed) — needs TestCase so config() resolves against the booted
// app. Same shape as Unit/QueueRuntimeConfigTest.php above.
pest()->extend(TestCase::class)
    ->in('Unit/NotificationsConfigTest.php');

// Feature/C12 — RefreshDatabase: the schema tests assert real Postgres unique
// and CHECK violations, which need a real schema to violate.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C12');

// Unit/Notifications — TestCase + RefreshDatabase: the resolver's whole job is
// a database query, and its riskiest assertions are about rows that do NOT
// exist (a missing role row, a null-org superadmin).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Notifications');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

// ─── backoffice-missing-pages shared auth helper ──────────────────────────────
//
// Shared by Feature/OrganizationSettings and Feature/UserManagement: mints an
// org-scoped user with exactly one Spatie authorization role (admin/operator/
// viewer, teams mode, team_id = organization_id) and returns a login JWT.
// Mirrors ProjectCrudTest.php's crudAdminUser() / AdminCrossTenantIsolationTest.php's
// crossOrgReaderToken() pattern, generalised to any of the three roles and
// centralised here because three different test files in this change need it.
function authTokenForRole(Organization $org, string $role): string
{
    return authUserAndTokenForRole($org, $role)['token'];
}

/**
 * Same as authTokenForRole(), but also returns the created User — needed by
 * self-action tests (last-admin self-demotion/self-deactivation,
 * privilege-escalation payload checks against the acting user's own row).
 *
 * @return array{user: User, token: string}
 */
function authUserAndTokenForRole(Organization $org, string $role): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    // Mirrors production provisioning (ProvisionOrganizationCommand.php /
    // RolesAndPermissionsSeeder.php): every organization gets all THREE
    // authorization roles up front, team_id explicit — never left to the
    // ambient registrar context, which is the NULL-team trap both of those
    // files document. UserController::assignRole() resolves roles via
    // firstOrFail() (never firstOrCreate()), so a test that only ever seeds
    // the one role it's about to assign would silently diverge from how a
    // real, provisioned organization looks.
    foreach (['admin', 'operator', 'viewer'] as $roleName) {
        SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
    }

    $spatieRole = SpatieRole::where(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id])->firstOrFail();
    $user->assignRole($spatieRole);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

/**
 * Reset jwt-auth's cached identity state between two authenticated HTTP
 * calls made with DIFFERENT tokens inside the SAME test method.
 *
 * Gotcha discovered in this batch: `Tymon\JWTAuth\JWT::getToken()` (bound
 * as the container singleton 'tymon.jwt', injected into JWTGuard) caches
 * the FIRST successfully parsed token on `$this->token` and never
 * re-parses it — `setRequest()` only updates the request the parser reads
 * FROM, not this cache. `JWTGuard::user()` also caches `$this->user`
 * directly (set by `login()` at MINT time, not just at request time). A
 * second `withToken($differentToken)->getJson(...)` call within the same
 * test therefore silently resolves to the FIRST call's user, regardless of
 * the new Authorization header — confirmed empirically: neither cache
 * alone explains it, and clearing only one of the two still reproduces the
 * bug. Both must be cleared together.
 */
function resetAuthGuardState(): void
{
    app('tymon.jwt')->unsetToken();
    app('auth')->forgetGuards();
}

// ─── C13 nfr-hardening ────────────────────────────────────────────────────────

// Feature/C13 — RefreshDatabase: project-configuration and audit assertions
// create real rows through factories.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C13');

// ─── org-provisioning ─────────────────────────────────────────────────────────

// Feature/Provisioning — RefreshDatabase: the command's riskiest assertions are
// about rows that must NOT survive a failed run, which needs a real schema and
// a real transaction rather than a mocked repository.
pest()->use(RefreshDatabase::class)
    ->in('Feature/Provisioning');

// ─── C14 avatar-provider-templates ────────────────────────────────────────────

// Feature/C14 — RefreshDatabase: the one-active-template invariant is asserted
// against the REAL partial unique index, so these tests need a migrated schema
// rather than a mocked repository. That is the point of them.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C14');

// ─── backoffice-missing-pages (organization-settings / user-management / admin-read-api delta) ──

// Feature/OrganizationSettings — RefreshDatabase: the singular self-resolving
// /api/organization route and webhook-defaults copy-on-create are asserted
// against real Organization/Project rows.
pest()->use(RefreshDatabase::class)
    ->in('Feature/OrganizationSettings');

// Unit/Support/Users — needs TestCase + RefreshDatabase: UserAdminReader and
// UserGuards create Organization/User/Role fixtures via factories and Spatie.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Support/Users');

// Feature/UserManagement — RefreshDatabase: the privilege-escalation and
// last-admin-guard matrix is asserted against real users/roles rows, not mocks.
pest()->use(RefreshDatabase::class)
    ->in('Feature/UserManagement');

// Feature/AdminReadApi — RefreshDatabase: the evaluations index/summary
// lifecycle gate is asserted against real Participant/Evaluation/CompetencyResult rows.
pest()->use(RefreshDatabase::class)
    ->in('Feature/AdminReadApi');

// ─── object-storage-fix ────────────────────────────────────────────────────────

// Unit/Storage — needs TestCase so config()->set() and Storage::disk() resolve
// against a booted app. No DB needed: this tier asserts driver installability
// only (offline, no network/credentials). Same shape as Unit/NotificationsConfigTest.php.
pest()->extend(TestCase::class)
    ->in('Unit/Storage');

// Integration/Storage — needs TestCase for $this->artisan(). NOT wired into any
// phpunit.xml <testsuite>, so this directory never runs under `php artisan test
// --parallel`; it is invoked explicitly (e.g. `railway run ... ./vendor/bin/pest
// tests/Integration`) wherever live storage credentials actually live (D4.2).
pest()->extend(TestCase::class)
    ->in('Integration/Storage');

// ─── demo-data-provisioning ────────────────────────────────────────────────

// Unit/Support/Demo — needs TestCase + RefreshDatabase: DemoDatasetValidator
// checks the fixture against real BarsIndicator/Role rows seeded by
// FrameworkCatalogSeeder. DemoMarker/PlaceholderJpeg are pure but share the
// directory.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Support/Demo');

// Feature/Demo — RefreshDatabase: beai:demo-seed / beai:demo-teardown are
// asserted against a real, migrated schema (tenancy scope, cascade deletes,
// partial unique indexes) — none of this is mockable without losing the
// point of the test.
pest()->use(RefreshDatabase::class)
    ->in('Feature/Demo');
