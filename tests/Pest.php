<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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

// ─── C13 nfr-hardening ────────────────────────────────────────────────────────

// Feature/C13 — RefreshDatabase: project-configuration and audit assertions
// create real rows through factories.
pest()->use(RefreshDatabase::class)
    ->in('Feature/C13');
