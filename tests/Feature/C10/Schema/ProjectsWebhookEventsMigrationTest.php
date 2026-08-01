<?php

declare(strict_types=1);

/**
 * RED — 2.2: projects.webhook_events migration (C10, D10).
 *
 * Asserts the column is NOT NULL and defaults to both event types
 * (`["progress","evaluation"]`) for every row that doesn't specify it — covering both
 * "a project row created before this migration" and "a project created after it via
 * POST /api/projects with no webhook_events in the payload" per the spec scenario
 * "Existing and new projects default to both event types enabled"
 * (openspec/changes/webhooks-integration/specs/project-config/spec.md).
 *
 * Postgres backfills every pre-existing row with the column DEFAULT when a NOT NULL
 * column is added via ALTER TABLE ... ADD COLUMN ... DEFAULT (i.e. the "pre-migration
 * row" and "fresh insert with no explicit value" cases share the exact same DB-level
 * default mechanism) — so asserting the default on a fresh insert is sufficient
 * coverage for both spec-scenario branches at the schema layer.
 *
 * Note: StoreProjectRequest/UpdateProjectRequest validation, ProjectResource exposure,
 * and the PATCH-narrows scenario are PR 2 scope (model/config/request layer) — not
 * asserted here.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('projects table has webhook_events column', function (): void {
    expect(Schema::hasColumn('projects', 'webhook_events'))->toBeTrue();
});

test('projects.webhook_events is NOT NULL', function (): void {
    $columns = Schema::getColumns('projects');

    $webhookEvents = collect($columns)->firstWhere('name', 'webhook_events');

    expect($webhookEvents)->not->toBeNull();
    expect($webhookEvents['nullable'])->toBeFalse('projects.webhook_events must be NOT NULL.');
});

test('inserting a row without webhook_events defaults it to both event types', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    // ProjectFactory does not set webhook_events — the DB-level DEFAULT must apply.
    $project = Project::factory()->create();

    /** @var string $raw */
    $raw = DB::table('projects')->where('id', $project->id)->value('webhook_events');
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toEqualCanonicalizing(['progress', 'evaluation']);
});

test('NOT NULL constraint rejects an explicit null webhook_events', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();

    expect(fn () => DB::table('projects')
        ->where('id', $project->id)
        ->update(['webhook_events' => null]))
        ->toThrow(QueryException::class);
});
