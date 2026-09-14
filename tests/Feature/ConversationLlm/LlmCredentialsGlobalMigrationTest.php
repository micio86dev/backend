<?php

declare(strict_types=1);

/**
 * The DESTRUCTIVE half of `make_llm_credentials_global` — ratified disposal.
 *
 * The schema half is pinned by `LlmCredentialsSchemaTest`. This file covers the
 * part that cannot be undone: the migration keeps the most recently created
 * Google credential and DELETES every other row (RATIFIED 2026-09-14).
 *
 * Tested by `require`-ing the real migration and calling `->up()`, the pattern
 * `QuestionIndexBackfillMigrationTest` already establishes — never a copied SQL
 * string, so a regression in the migration is a failure HERE rather than in a
 * stand-in that keeps passing.
 *
 * The old shape has to be rebuilt first, because the test database is already
 * migrated. `down()` is what rebuilds it, which makes these tests exercise the
 * documented reversal too.
 *
 * Every helper carries a `gcm` prefix. ParaTest distributes TEST FILES across
 * workers and these are plain functions in the global namespace, so a bare
 * `seedTemplate()` collided with `AvatarTemplateApiTest`'s own and killed the
 * whole parallel run with "Cannot redeclare" — the same hazard
 * `tests/Helpers/SuperadminFixtures.php` documents from the other direction.
 *
 * Rows are staged with the QUERY BUILDER, deliberately. `AvatarTemplate::saving`
 * refuses a vendor-mismatched binding (I4), and the vendor-mismatch case below is
 * precisely the row a migration may find in a database written before a guard
 * existed. Eloquent could not create the fixture this is about.
 */

use App\Models\AvatarTemplate;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function gcmMigration(): object
{
    return require database_path('migrations/2026_09_14_090000_make_llm_credentials_global.php');
}

/** Rebuild the pre-migration shape so `up()` has something to act on. */
function gcmRestoreOldShape(): void
{
    gcmMigration()->down();
}

function gcmCredentialRow(int $orgId, string $name, string $vendor = 'google'): int
{
    return (int) DB::table('llm_credentials')->insertGetId([
        'organization_id' => $orgId,
        'name' => $name,
        'vendor' => $vendor,
        'api_key' => 'ciphertext',
        'key_last_four' => 'abcd',
        'key_fingerprint' => hash('sha256', $name),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function gcmLlmModel(string $key, string $vendor): LlmModel
{
    return LlmModel::create([
        'key' => $key,
        'vendor' => $vendor,
        'display_name' => $key,
        'base_url' => 'https://example.test/v1/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);
}

function gcmTemplate(Organization $org, string $name): AvatarTemplate
{
    return TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => $name,
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p'],
    ]));
}

test('the most recently created Google credential survives and every other row is deleted', function (): void {
    gcmRestoreOldShape();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $oldest = gcmCredentialRow($orgA->id, 'Gemini prod');
    $middle = gcmCredentialRow($orgB->id, 'Gemini staging');
    // Same NAME as the first, in a different org — legal under the old
    // UNIQUE(organization_id, name) and the exact collision the new
    // platform-wide UNIQUE(name) could not hold if both rows survived.
    $newest = gcmCredentialRow($orgB->id, 'Gemini prod');

    gcmMigration()->up();

    expect(DB::table('llm_credentials')->pluck('id')->all())->toBe([$newest]);
    expect(DB::table('llm_credentials')->where('id', $oldest)->exists())->toBeFalse();
    expect(DB::table('llm_credentials')->where('id', $middle)->exists())->toBeFalse();
});

test('a bound template is REPOINTED at the survivor, not orphaned and not deleted', function (): void {
    gcmRestoreOldShape();

    $org = Organization::factory()->create();
    $model = gcmLlmModel('gemini-3-flash-preview', 'google');

    $doomed = gcmCredentialRow($org->id, 'Old key');
    $survivor = gcmCredentialRow($org->id, 'New key');

    $template = gcmTemplate($org, 'Bound template');
    DB::table('avatar_templates')->where('id', $template->id)->update([
        'llm_model_id' => $model->id,
        'llm_credential_id' => $doomed,
    ]);

    gcmMigration()->up();

    $row = DB::table('avatar_templates')->where('id', $template->id)->first();

    // The binding SURVIVES — this is what makes the delete possible at all.
    // `avatar_templates.llm_credential_id` is ON DELETE RESTRICT, so a
    // migration that deleted without repointing would abort on the constraint.
    expect((int) $row->llm_credential_id)->toBe($survivor);
    expect((int) $row->llm_model_id)->toBe($model->id);
});

/**
 * A repointed template must NOT keep claiming it is synced.
 *
 * Its `heygen_llm_configuration_id` names a configuration HeyGen built from
 * the DELETED credential's secret — which this migration deliberately leaves
 * orphaned at the vendor, because a migration must not make HTTP calls. So
 * `llm_sync_status = 'synced'` would survive the repoint and
 * `LlmBindingResolver::resolveStatus()` would keep reporting `Applied`, the
 * one state its docblock calls BILLABLE, about a key the template is no longer
 * bound to.
 *
 * Degraded is the honest answer until something actually pushes.
 */
test('a repointed template has its sync state torn down, never left claiming synced', function (): void {
    gcmRestoreOldShape();

    $org = Organization::factory()->create();
    $model = gcmLlmModel('gemini-3-flash-preview', 'google');

    $doomed = gcmCredentialRow($org->id, 'Old key');
    $survivor = gcmCredentialRow($org->id, 'New key');

    $template = gcmTemplate($org, 'Bound and synced');
    DB::table('avatar_templates')->where('id', $template->id)->update([
        'llm_model_id' => $model->id,
        'llm_credential_id' => $doomed,
        'heygen_llm_configuration_id' => 'cfg_built_from_the_deleted_secret',
        'llm_sync_status' => 'synced',
        'llm_synced_at' => now(),
    ]);

    gcmMigration()->up();

    $row = DB::table('avatar_templates')->where('id', $template->id)->first();

    expect((int) $row->llm_credential_id)->toBe($survivor);
    expect($row->llm_sync_status)->toBeNull();
    expect($row->llm_synced_at)->toBeNull();
    expect($row->heygen_llm_configuration_id)->toBeNull();
});

test('a template whose model vendor does not match the survivor is UNBOUND, never mis-repointed', function (): void {
    gcmRestoreOldShape();

    $org = Organization::factory()->create();
    $otherVendorModel = gcmLlmModel('some-other-model', 'not-google');

    $otherVendorCred = gcmCredentialRow($org->id, 'Other vendor key', vendor: 'not-google');
    $survivor = gcmCredentialRow($org->id, 'Gemini key');

    $template = gcmTemplate($org, 'Other vendor template');
    DB::table('avatar_templates')->where('id', $template->id)->update([
        'llm_model_id' => $otherVendorModel->id,
        'llm_credential_id' => $otherVendorCred,
    ]);

    gcmMigration()->up();

    $row = DB::table('avatar_templates')->where('id', $template->id)->first();

    // Repointing this one at the Google survivor would write a row that
    // `AvatarTemplate::saving`'s I4 vendor check rejects on every later save —
    // a template nobody could edit again. Unbound is the state the application
    // can still work with.
    expect($row->llm_credential_id)->toBeNull();
    // BOTH columns, because I1's CHECK refuses a half-bound row.
    expect($row->llm_model_id)->toBeNull();
    expect(DB::table('llm_credentials')->pluck('id')->all())->toBe([$survivor]);
});

test('with no Google credential at all the table is emptied and every binding is torn down', function (): void {
    gcmRestoreOldShape();

    $org = Organization::factory()->create();
    $model = gcmLlmModel('some-other-model', 'not-google');
    $cred = gcmCredentialRow($org->id, 'Only other vendor', vendor: 'not-google');

    $template = gcmTemplate($org, 'Bound to other vendor');
    DB::table('avatar_templates')->where('id', $template->id)->update([
        'llm_model_id' => $model->id,
        'llm_credential_id' => $cred,
    ]);

    gcmMigration()->up();

    expect(DB::table('llm_credentials')->count())->toBe(0);

    $row = DB::table('avatar_templates')->where('id', $template->id)->first();
    expect($row->llm_credential_id)->toBeNull();
    expect($row->llm_model_id)->toBeNull();
});

test('up() leaves the platform shape: no organization_id, name unique platform-wide', function (): void {
    gcmRestoreOldShape();

    expect(Schema::hasColumn('llm_credentials', 'organization_id'))->toBeTrue();

    gcmMigration()->up();

    expect(Schema::hasColumn('llm_credentials', 'organization_id'))->toBeFalse();

    $indexes = DB::select(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'llm_credentials' AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%(name)%'"
    );

    expect($indexes)->not->toBeEmpty();
});
