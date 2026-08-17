<?php

declare(strict_types=1);

/**
 * "A gate refusal reason is consistent across both mints" — operator-interview-link.
 *
 * The M2M mint (`POST /api/m2m/sso-link`) and the operator mint
 * (`POST /api/entry-links`) share exactly one mint decision
 * (`EntryLinkMinter::mint()`, design D1). This is the guarantee that sharing
 * exists to provide — asserted here by calling BOTH endpoints against the
 * SAME gate-failing project and confirming both refuse for the SAME
 * underlying reason (a gates failure surfaces as 403 on both, despite each
 * endpoint building its own distinct literal response body).
 *
 * REQ: Shared Entry Link Minting Logic — "A gate refusal reason is
 *      consistent across both mints"
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Models\ApiClient;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function consistencyM2mClient(Organization $org): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'abilities' => ['sso_link:generate'],
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

function consistencyOperator(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function consistencyProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(array_merge([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

test('a gates refusal (past deadline_at) is 403 on both the M2M mint and the operator mint', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = consistencyProject($org, ['deadline_at' => now()->subHour()]);
    $m2m = consistencyM2mClient($org);
    $operatorToken = consistencyOperator($org);

    $m2mResponse = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-gates',
        'display_name' => 'Test Candidate',
    ]);

    $operatorResponse = $this->withToken($operatorToken)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-gates',
        'display_name' => 'Test Candidate',
    ]);

    $m2mResponse->assertStatus(403);
    $operatorResponse->assertStatus(403);
});

test('a role_code refusal (mismatch for a standard project) is 422 on both mints', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = consistencyProject($org, ['role_code' => 'ICO']);
    $m2m = consistencyM2mClient($org);
    $operatorToken = consistencyOperator($org);

    $m2mResponse = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-role',
        'display_name' => 'Test Candidate',
        'role_code' => 'FLL',
    ]);

    $operatorResponse = $this->withToken($operatorToken)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-role',
        'display_name' => 'Test Candidate',
        'role_code' => 'FLL',
    ]);

    $m2mResponse->assertStatus(422);
    $operatorResponse->assertStatus(422);
});

test('a terminal-status refusal (completato) is 409 on both mints', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = consistencyProject($org);
    $m2m = consistencyM2mClient($org);
    $operatorToken = consistencyOperator($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-terminal',
        'display_name' => 'Done',
        'status' => 'in_valutazione',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'completato']);

    $m2mResponse = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-terminal',
        'display_name' => 'Done',
    ]);

    $operatorResponse = $this->withToken($operatorToken)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'consistency-terminal',
        'display_name' => 'Done',
    ]);

    $m2mResponse->assertStatus(409);
    $operatorResponse->assertStatus(409);
});
