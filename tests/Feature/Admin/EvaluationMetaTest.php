<?php

declare(strict_types=1);

/**
 * Evaluation Read Surface Exposes Its Scoring Regime (D7, PR 2a).
 *
 * `GET /api/participants/{id}/evaluation` MUST carry a `meta.scoring` sibling
 * of `data` with the Evaluation's `prompt_version`, `model_version`, and
 * `framework_version` (the resolved FrameworkVersion.version STRING, never
 * the raw framework_version_id FK). This is additive and domain-independent:
 * it does not depend on the widened {1,2,3,4,5} indicator score domain
 * shipped in PR 3.
 *
 * One organization/token per test (house style, see AdminLifecycleGateMatrixTest):
 * `auth('api')->login($user)` caches the guard's authenticated user for the
 * process, so authenticating a second identity in the SAME test would make
 * every subsequent `withToken()` call resolve to that second identity
 * regardless of which token is attached to the request.
 *
 * REQ: Evaluation Read Surface Exposes Its Scoring Regime
 *      (openspec/changes/bars-full-scale-1-5/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Builds one org, one completed Evaluation stamped with the given scoring
 * regime, and fetches it through the real HTTP surface.
 */
function evalMetaFetch(string $promptVersion, string $modelVersion, string $frameworkVersion): TestResponse
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id, 'version' => $frameworkVersion]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();

    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    $evaluation = Evaluation::factory()->completed()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
        'model_version' => $modelVersion,
        'prompt_version' => $promptVersion,
    ]);
    $result = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 4.0,
        'reliability' => 0.9,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0]);

    return test()->withToken($token)->getJson("/api/participants/{$participant->id}/evaluation");
}

test('evaluation response carries meta.scoring as a sibling of data, with the resolved framework_version string', function (): void {
    $response = evalMetaFetch(promptVersion: '2.0.0', modelVersion: 'claude-haiku-4-5-20251001', frameworkVersion: '1.4.0');

    $response->assertStatus(200);
    $response->assertJsonPath('meta.scoring.prompt_version', '2.0.0');
    $response->assertJsonPath('meta.scoring.model_version', 'claude-haiku-4-5-20251001');
    // Resolved version STRING, never the raw framework_version_id FK.
    $response->assertJsonPath('meta.scoring.framework_version', '1.4.0');
    // meta is a sibling of data, never nested inside it.
    $response->assertJsonPath('data.COL.score', 4.0);
});

test('an evaluation scored under prompt_version 1.0.0 is distinguishable from one scored under 2.0.0', function (): void {
    $legacy = evalMetaFetch(promptVersion: '1.0.0', modelVersion: 'fake-llm-provider-v1', frameworkVersion: '1.0.0');
    $widened = evalMetaFetch(promptVersion: '2.0.0', modelVersion: 'fake-llm-provider-v1', frameworkVersion: '1.4.0');

    $legacy->assertJsonPath('meta.scoring.prompt_version', '1.0.0');
    $widened->assertJsonPath('meta.scoring.prompt_version', '2.0.0');
});
