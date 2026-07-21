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
    ->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Models');

// Feature/Models uses ->use() (not ->extend()) to avoid re-declaring TestCase
// (Feature/ already extends TestCase above).
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/Models');

// Feature/Seeders — RefreshDatabase for seeder idempotency tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/Seeders');

// Unit tests for C3 services/adapters.
pest()->extend(TestCase::class)
    ->in('Unit/Services');

// Feature/Api — RefreshDatabase for API + tenant isolation tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/Api');

// ─── C4 Project Configuration ─────────────────────────────────────────────────

// Feature/C4 — RefreshDatabase for schema, seeder-guard, CRUD, RBAC, and invariant tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/C4');

// Unit/C4 — needs TestCase + RefreshDatabase for model guard tests (model events require DB).
pest()->extend(TestCase::class)
    ->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/C4');

// ─── C5 M2M API Authentication ───────────────────────────────────────────────

// Unit/C5 — needs TestCase so service providers boot and app() helpers work.
// Note: model-creation tests are in Feature/C5 to avoid RefreshDatabase conflicts.
pest()->extend(TestCase::class)
    ->in('Unit/C5');

// Feature/C5 — RefreshDatabase for guard, isolation, and revocation tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/C5');

// ─── C6 Participant + SSO Ingress ─────────────────────────────────────────────

// Unit/C6 — needs TestCase + RefreshDatabase (model guard tests require DB).
pest()->extend(TestCase::class)
    ->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/C6');

// Feature/C6 — RefreshDatabase for exchange flow, guard matrix, and isolation tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/C6');

// Arch/C6 — covered by the global Arch extension above (TestCase already in Arch/).

// ─── C7a Interview Session Mechanics ──────────────────────────────────────────

// Unit/C7a — needs TestCase + RefreshDatabase (model guard tests require DB).
pest()->extend(TestCase::class)
    ->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/C7a');

// Feature/C7a — RefreshDatabase for schema, model, lifecycle, and isolation tests.
pest()->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature/C7a');

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
