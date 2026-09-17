<?php

declare(strict_types=1);

/**
 * Z22 (R3-interviewability-rollout-existing-projects, framework-catalogue-
 * authoring, REQUIRED BEFORE ARCHIVE): `beai:backfill-project-questions`
 * closes the gap `ProjectInterviewability` opened for any EXISTING project
 * whose selected competency never went through `ApplyCompetencySelection`
 * (it predates that write path, or an operator individually deleted every
 * live question for a competency that stayed selected — both leave a
 * selected competency with zero live `project_questions` rows).
 */

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\Project\ProjectInterviewability;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

/**
 * Builds a project with a SELECTED competency that has ZERO live
 * `project_questions` rows — the exact gap `ProjectInterviewability` opened,
 * never going through `ApplyCompetencySelection::apply()` at all.
 *
 * The competency and its default question land on the BASELINE revision —
 * deliberately, not a custom `published` revision: `2026_09_15_201434_
 * enforce_catalogue_published_content_immutability` refuses any write to
 * `framework_competencies`/`framework_default_questions` once their
 * revision is `published`, UNLESS that revision `is_baseline` (the seeder's
 * own gate governs the baseline instead — see that migration's own
 * docblock). `Competency::booted()`'s own `creating` listener already
 * defaults `revision_id` to the baseline when left unset, which is exactly
 * the one revision this fixture is allowed to write onto directly.
 *
 * @return array{0: Project, 1: Competency, 2: FrameworkCatalogRevision}
 */
function backfillFixtureProjectWithUnsatisfiedCompetency(Organization $org): array
{
    $competency = Competency::factory()->create();
    $revision = FrameworkCatalogRevision::findOrFail($competency->revision_id);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    $frameworkVersion = FrameworkVersion::factory()->create(['revision_id' => $revision->id]);
    $project = Project::factory()->create(['framework_version_id' => $frameworkVersion->id]);

    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'position' => 0,
    ]);

    return [$project, $competency, $revision];
}

test('backfills a selected competency with zero live questions by copying the catalogue defaults', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency, $revision] = backfillFixtureProjectWithUnsatisfiedCompetency($org);

    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'revision_id' => $revision->id,
        'position' => 0,
        'text' => ['en' => 'Default question EN', 'it' => 'Domanda predefinita IT'],
    ]);

    expect(ProjectQuestion::where('project_id', $project->id)->count())->toBe(0);
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);

    $questions = ProjectQuestion::where('project_id', $project->id)->get();
    expect($questions)->toHaveCount(1);
    expect($questions->first()->text['en'])->toBe('Default question EN');
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeTrue();
});

test('reports a competency with no catalogue defaults as still incomplete, without erroring', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency] = backfillFixtureProjectWithUnsatisfiedCompetency($org);
    // No FrameworkDefaultQuestion created — the catalogue itself has nothing to copy.

    $this->artisan('beai:backfill-project-questions')
        ->assertExitCode(0)
        ->expectsOutputToContain((string) $competency->code);

    expect(ProjectQuestion::where('project_id', $project->id)->count())->toBe(0);
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();
});

test('--dry-run reports what would be backfilled without writing anything', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency, $revision] = backfillFixtureProjectWithUnsatisfiedCompetency($org);

    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'revision_id' => $revision->id,
        'position' => 0,
        'text' => ['en' => 'Default question EN', 'it' => 'Domanda predefinita IT'],
    ]);

    $this->artisan('beai:backfill-project-questions', ['--dry-run' => true])->assertExitCode(0);

    expect(ProjectQuestion::where('project_id', $project->id)->count())->toBe(
        0,
        'a dry run must never write a project_questions row'
    );
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();
});

test('is idempotent — a second run makes no further change once a competency is satisfied', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency, $revision] = backfillFixtureProjectWithUnsatisfiedCompetency($org);

    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'revision_id' => $revision->id,
        'position' => 0,
        'text' => ['en' => 'Default question EN', 'it' => 'Domanda predefinita IT'],
    ]);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);
    expect(ProjectQuestion::where('project_id', $project->id)->count())->toBe(1);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);
    expect(ProjectQuestion::where('project_id', $project->id)->count())->toBe(
        1,
        'a second run must not duplicate the already-copied question'
    );
});

/**
 * Respects `ApplyCompetencySelection`'s own deselection-trashed exemption
 * (Z8) — a row this project's own operator soft-deleted by DESELECTING the
 * competency (never one they deleted individually) is RESTORED, not
 * replaced by a fresh catalogue-default copy, preserving whatever text the
 * operator had authored on it.
 */
test('restores a deselection-trashed row instead of copying a fresh default, preserving operator content', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency, $revision] = backfillFixtureProjectWithUnsatisfiedCompetency($org);

    $trashed = ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Operator-authored question EN', 'it' => 'Domanda IT'],
        'position' => 0,
    ]);
    // `operator_modified`/`deleted_by_deselection` are deliberately NOT
    // fillable (see ProjectQuestion's own docblock) — forceFill(), matching
    // ApplyCompetencySelection::softDeleteLive()'s own write shape.
    $trashed->forceFill(['operator_modified' => true, 'deleted_by_deselection' => true, 'deleted_at' => now()])->save();

    // A catalogue default also exists — proving restore wins over copy.
    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'revision_id' => $revision->id,
        'position' => 0,
        'text' => ['en' => 'Default question EN', 'it' => 'Domanda predefinita IT'],
    ]);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);

    $questions = ProjectQuestion::where('project_id', $project->id)->get();
    expect($questions)->toHaveCount(1);
    expect($questions->first()->id)->toBe($trashed->id);
    expect($questions->first()->text['en'])->toBe('Operator-authored question EN');
});

test('--org filters the backfill to a single organization', function (): void {
    $orgA = Organization::factory()->create(['slug' => 'backfill-org-a-'.uniqid()]);
    $orgB = Organization::factory()->create(['slug' => 'backfill-org-b-'.uniqid()]);

    [$projectA, $competencyA, $revisionA] = backfillFixtureProjectWithUnsatisfiedCompetency($orgA);
    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competencyA->id, 'revision_id' => $revisionA->id, 'position' => 0,
        'text' => ['en' => 'A', 'it' => 'A'],
    ]);

    [$projectB, $competencyB, $revisionB] = backfillFixtureProjectWithUnsatisfiedCompetency($orgB);
    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competencyB->id, 'revision_id' => $revisionB->id, 'position' => 0,
        'text' => ['en' => 'B', 'it' => 'B'],
    ]);

    $this->artisan('beai:backfill-project-questions', ['--org' => $orgA->slug])->assertExitCode(0);

    // withoutGlobalScopes(): this assertion runs after the command's own
    // TenantContextScope::runFor() calls have already restored the ambient
    // tenant context back to whichever org's fixture ran last (org B) — a
    // plain tenant-scoped query here would silently filter project A's own
    // rows out and look like a false failure, not prove a true one.
    expect(ProjectQuestion::withoutGlobalScopes()->where('project_id', $projectA->id)->count())->toBe(1);
    expect(ProjectQuestion::withoutGlobalScopes()->where('project_id', $projectB->id)->count())->toBe(
        0,
        'the unrelated organization must be untouched'
    );
});

/**
 * Publishes a SECOND revision — never the fixture's pinned baseline — whose
 * competency matches `$competency`'s own CODE, carrying one default question
 * (R4-interviewability-rollout-unrecoverable: the shape
 * `ApplyCompetencySelection::latestPublishedDefaults()` looks for). Authored
 * while still a DRAFT, then flipped to `published`: the published-content-
 * immutability trigger exempts only the baseline, so a non-baseline
 * revision's content can only be written before it publishes.
 */
function backfillFixturePublishLaterDefault(Competency $competency, array $text): void
{
    $laterRevision = FrameworkCatalogRevision::factory()->draft()->create();
    $laterCompetency = Competency::factory()->create(['revision_id' => $laterRevision->id, 'code' => $competency->code]);
    FrameworkDefaultQuestion::factory()->create(['competency_id' => $laterCompetency->id, 'position' => 0, 'text' => $text]);
    $laterRevision->update(['state' => 'published', 'published_at' => now()]);
}

test('falls back to the latest published revision defaults when the pinned revision has none', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency] = backfillFixtureProjectWithUnsatisfiedCompetency($org);
    // The pinned (baseline) revision has NO default for this competency.
    backfillFixturePublishLaterDefault($competency, ['en' => 'Later default EN', 'it' => 'Domanda successiva IT']);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);

    $questions = ProjectQuestion::where('project_id', $project->id)->get();
    expect($questions)->toHaveCount(1);
    expect($questions->first()->competency_id)->toBe($competency->id, 'copied under the project\'s OWN pinned competency id, never the later revision\'s');
    expect($questions->first()->text['en'])->toBe('Later default EN');
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeTrue();
});

test('the latest-published fallback never overrides an existing live question', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency] = backfillFixtureProjectWithUnsatisfiedCompetency($org);
    $live = ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Operator-authored EN', 'it' => 'Operatore IT'],
        'position' => 0,
    ]);
    backfillFixturePublishLaterDefault($competency, ['en' => 'Later default EN', 'it' => 'Domanda successiva IT']);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);

    $questions = ProjectQuestion::where('project_id', $project->id)->get();
    expect($questions)->toHaveCount(1);
    expect($questions->first()->id)->toBe($live->id);
    expect($questions->first()->text['en'])->toBe('Operator-authored EN');
});

test('pinned-revision defaults still win over the latest-published fallback when present', function (): void {
    $org = Organization::factory()->create();
    [$project, $competency, $revision] = backfillFixtureProjectWithUnsatisfiedCompetency($org);
    FrameworkDefaultQuestion::factory()->create([
        'competency_id' => $competency->id,
        'revision_id' => $revision->id,
        'position' => 0,
        'text' => ['en' => 'Pinned default EN', 'it' => 'Predefinita IT'],
    ]);
    backfillFixturePublishLaterDefault($competency, ['en' => 'Later default EN', 'it' => 'Domanda successiva IT']);

    $this->artisan('beai:backfill-project-questions')->assertExitCode(0);

    $questions = ProjectQuestion::where('project_id', $project->id)->get();
    expect($questions)->toHaveCount(1);
    expect($questions->first()->text['en'])->toBe('Pinned default EN');
});
