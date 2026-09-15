<?php

declare(strict_types=1);

/**
 * RED — 1.1 (framework-catalogue-authoring PR 1): the correctness proof that
 * an already-scored Evaluation still means exactly what it meant before the
 * revision migration lands. See design.md D1 — "the baseline migration moves
 * no rows".
 *
 * Written BEFORE Phase 2's migrations/models exist, so it starts RED against
 * schema that does not exist yet: `FrameworkVersion::revision()`,
 * `BarsIndicator.revision_id`, `FrameworkCatalogRevision`. Phase 2 creates
 * them; Phase 3 turns this GREEN.
 *
 * Technique, mirroring `tests/Feature/C2/Schema/UsersOrganizationMigrationTest.php`'s
 * "migrate:rollback then migrate" pattern: roll back exactly the 5 new PR1
 * migrations, seed data against that pre-revision schema, snapshot the four
 * translatable BARS fields, re-apply the 5 migrations, and assert the SAME
 * physical `BarsIndicator` row (same id — no row is ever copied) resolves to
 * byte-identical text via `Evaluation -> FrameworkVersion -> revision`.
 *
 * PR1_NEW_MIGRATION_COUNT MUST equal the number of migration files this PR
 * adds — if that count changes, this constant changes with it.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

const PR1_NEW_MIGRATION_COUNT = 5;

test('an evaluation scored before the revision migration resolves byte-identical anchor text after it', function (): void {
    // Roll back to the pre-revision schema — exactly the 5 migrations this PR adds.
    Artisan::call('migrate:rollback', ['--step' => PR1_NEW_MIGRATION_COUNT, '--force' => true]);

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $role = Role::factory()->create(['code' => 'PR1TEST']);
    $competency = Competency::factory()->create(['code' => 'PR1COMP']);

    $indicator = BarsIndicator::create([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => [
            'en' => 'Describe a time you led a project.',
            'it' => 'Descrivi un momento in cui hai guidato un progetto.',
        ],
        'anchor_5' => ['en' => 'Excellent leadership shown.', 'it' => 'Leadership eccellente dimostrata.'],
        'anchor_3' => ['en' => 'Adequate leadership shown.', 'it' => 'Leadership adeguata dimostrata.'],
        'anchor_1' => ['en' => 'Insufficient leadership shown.', 'it' => 'Leadership insufficiente dimostrata.'],
        'position' => 0,
    ]);

    $fv = FrameworkVersion::create([
        'organization_id' => $org->id,
        'version' => 'pr1-pre-revision',
        'label' => 'PR1 pre-revision snapshot',
    ]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'role_code' => 'PR1TEST',
    ]);
    $participant = Participant::factory()->create([
        'project_id' => $project->id,
        'organization_id' => $org->id,
    ]);
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
    ]);

    $snapshot = [
        'text_en' => $indicator->getTranslation('text', 'en'),
        'text_it' => $indicator->getTranslation('text', 'it'),
        'anchor_5_en' => $indicator->getTranslation('anchor_5', 'en'),
        'anchor_5_it' => $indicator->getTranslation('anchor_5', 'it'),
        'anchor_3_en' => $indicator->getTranslation('anchor_3', 'en'),
        'anchor_3_it' => $indicator->getTranslation('anchor_3', 'it'),
        'anchor_1_en' => $indicator->getTranslation('anchor_1', 'en'),
        'anchor_1_it' => $indicator->getTranslation('anchor_1', 'it'),
    ];
    $indicatorId = $indicator->id;
    $evaluationId = $evaluation->id;

    // Migrate forward: this PR's 5 migrations run, including the baseline
    // backfill. NO row is copied — the SAME physical BarsIndicator row gains
    // a revision_id.
    Artisan::call('migrate', ['--force' => true]);

    // The migrator's own PDO connection is separate from the test's; force a
    // fresh resolve rather than trusting anything cached in-process.
    $resolver->setOrgId($org->id);

    $resolvedEvaluation = Evaluation::findOrFail($evaluationId);
    $resolvedFv = $resolvedEvaluation->frameworkVersion;
    $resolvedRevision = $resolvedFv->revision;

    expect($resolvedRevision)->not->toBeNull();
    expect($resolvedRevision->is_baseline)->toBeTrue();

    $resolvedIndicator = BarsIndicator::findOrFail($indicatorId);

    // Same physical row — the whole correctness argument for "an
    // already-scored evaluation still means what it meant" rests on this.
    expect($resolvedIndicator->id)->toBe($indicatorId);
    expect($resolvedIndicator->revision_id)->toBe($resolvedRevision->id);

    expect($resolvedIndicator->getTranslation('text', 'en'))->toBe($snapshot['text_en']);
    expect($resolvedIndicator->getTranslation('text', 'it'))->toBe($snapshot['text_it']);
    expect($resolvedIndicator->getTranslation('anchor_5', 'en'))->toBe($snapshot['anchor_5_en']);
    expect($resolvedIndicator->getTranslation('anchor_5', 'it'))->toBe($snapshot['anchor_5_it']);
    expect($resolvedIndicator->getTranslation('anchor_3', 'en'))->toBe($snapshot['anchor_3_en']);
    expect($resolvedIndicator->getTranslation('anchor_3', 'it'))->toBe($snapshot['anchor_3_it']);
    expect($resolvedIndicator->getTranslation('anchor_1', 'en'))->toBe($snapshot['anchor_1_en']);
    expect($resolvedIndicator->getTranslation('anchor_1', 'it'))->toBe($snapshot['anchor_1_it']);
});
