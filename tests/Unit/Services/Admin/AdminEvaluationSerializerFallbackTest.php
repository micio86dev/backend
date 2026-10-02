<?php

declare(strict_types=1);

/**
 * AdminEvaluationSerializer — the degraded-input contracts the happy-path
 * suites never reach:
 *
 *  - `auditMeta()` is null (not a throw) for a participant never evaluated.
 *  - `meta()` refuses to serialize provenance for a framework version the
 *    ambient tenant scope cannot see — a tenancy violation announces itself.
 *  - the indicator-NAME catalogue is best-effort: with no project, no role, or
 *    an unpinned framework version, the report renders the STORED indicator
 *    text rather than failing, and — the point of the pinning rule — never
 *    resolves names from a catalogue revision it was not pinned to.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function fallbackTenant(): Organization
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

/**
 * A scored SLF competency whose stored indicator text differs from the
 * published catalogue's name for the same indicator, so which source the
 * serializer used is observable.
 *
 * @return array{participant: Participant, project: Project, fv: FrameworkVersion}
 */
function fallbackScoredParticipant(Organization $org): array
{
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $role = Role::factory()->create(['code' => 'FBROLE_'.uniqid()]);
    $competency = Competency::query()->where('code', 'SLF')->first() ?? Competency::factory()->create(['code' => 'SLF']);
    BarsIndicator::factory()->create([
        'role_id' => $role->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'CATALOGUE indicator name'],
        'position' => 0,
    ]);

    $project = Project::factory()->create(['framework_version_id' => $fv->id, 'role_code' => $role->code]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
    ]);
    $result = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'SLF']);
    IndicatorScore::factory()->create([
        'competency_result_id' => $result->id,
        'position' => 0,
        'indicator_text' => 'STORED indicator text',
    ]);

    return ['participant' => $participant->fresh(), 'project' => $project, 'fv' => $fv];
}

test('auditMeta() is null for a participant that has no evaluation at all', function (): void {
    $org = fallbackTenant();
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    expect((new AdminEvaluationSerializer)->auditMeta($participant))->toBeNull();
});

test('meta() throws instead of inventing provenance when the framework version belongs to another tenant', function (): void {
    $org = fallbackTenant();
    $foreignOrg = Organization::factory()->create();
    $foreignFv = FrameworkVersion::factory()->create();
    // The `creating` listener re-stamps organization_id from the ambient
    // tenant, so re-home the row with a raw update to make it truly foreign.
    DB::table('framework_versions')->where('id', $foreignFv->id)->update(['organization_id' => $foreignOrg->id]);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
    ]);
    // Point the evaluation at the foreign tenant's framework version, bypassing
    // the model layer so the FK (which only checks existence) accepts it.
    DB::table('evaluations')->where('id', $evaluation->id)->update(['framework_version_id' => $foreignFv->id]);

    expect(fn () => (new AdminEvaluationSerializer)->meta($participant))
        ->toThrow(RuntimeException::class, 'did not resolve under the ambient tenant scope');
});

test('the catalogue name is used when the project is pinned to a catalogue revision (control)', function (): void {
    $org = fallbackTenant();
    $setup = fallbackScoredParticipant($org);
    $revisionId = BarsIndicator::query()->latest('id')->value('revision_id');
    DB::table('framework_versions')->where('id', $setup['fv']->id)->update(['revision_id' => $revisionId]);

    $serialized = (new AdminEvaluationSerializer)->serialize($setup['participant']->fresh());

    expect($serialized['SLF']['behaviors'][0]['indicator'])->toBe('CATALOGUE indicator name');
});

test('an unpinned framework version (null revision) renders the stored text, never a catalogue revision it was not pinned to', function (): void {
    $org = fallbackTenant();
    $setup = fallbackScoredParticipant($org);
    DB::table('framework_versions')->where('id', $setup['fv']->id)->update(['revision_id' => null]);

    $serialized = (new AdminEvaluationSerializer)->serialize($setup['participant']->fresh());

    expect($serialized['SLF']['behaviors'][0]['indicator'])->toBe('STORED indicator text');
});

test('a project and participant without a role code render the stored text', function (): void {
    $org = fallbackTenant();
    $setup = fallbackScoredParticipant($org);
    $revisionId = BarsIndicator::query()->latest('id')->value('revision_id');
    DB::table('framework_versions')->where('id', $setup['fv']->id)->update(['revision_id' => $revisionId]);
    DB::table('projects')->where('id', $setup['project']->id)->update(['role_code' => null]);
    DB::table('participants')->where('id', $setup['participant']->id)->update(['role_code' => null]);

    $serialized = (new AdminEvaluationSerializer)->serialize($setup['participant']->fresh());

    expect($serialized['SLF']['behaviors'][0]['indicator'])->toBe('STORED indicator text');
});

test('a participant whose project does not resolve still serializes its results, in stored text', function (): void {
    $org = fallbackTenant();
    $setup = fallbackScoredParticipant($org);
    $participant = $setup['participant'];
    $participant->setRelation('project', null);

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);

    expect(array_keys($serialized))->toBe(['SLF'])
        ->and($serialized['SLF']['behaviors'][0]['indicator'])->toBe('STORED indicator text');
});
