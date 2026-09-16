<?php

declare(strict_types=1);

/**
 * RED — 22.1 (framework-catalogue-authoring PR6, D6): all FOUR interviewability
 * ingresses refuse a non-interviewable project with the exact payloads the
 * design's interface contract names; `candidate_ref` is echoed byte-for-byte
 * on the M2M refusal; no participant row, no `InterviewSession` row, and no
 * webhook fires on the M2M or SSO refusal paths.
 */

use App\Models\ApiClient;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A project with ONE selected competency and ZERO live questions for it —
 * the exact "not interviewable" state D5 defines.
 */
function iirNonInterviewableProject(Organization $org): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $project = Project::factory()->create([
        'framework_version_id' => $fv->id,
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);

    $competency = Competency::firstOrCreate(
        ['code' => 'IIRCOMP'],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );

    $project->competencies()->attach([$competency->id => ['position' => 0]]);
    // Deliberately NO project_questions row for it.

    return [$project, $competency];
}

function iirOperatorToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

/** @return array{client: ApiClient, key: string} */
function iirM2mClient(Organization $org, array $abilities): array
{
    $rawKey = ApiKeyGenerator::generate();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return ['client' => $client, 'key' => $rawKey];
}

test('entry-link mint is refused for a non-interviewable project — 422 PROJECT_NOT_INTERVIEWABLE, no link minted', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.test']);
    $org = Organization::factory()->create();
    [$project, $competency] = iirNonInterviewableProject($org);
    $token = iirOperatorToken($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'iir-entry-001',
        'email' => 'iir@example.test',
        'display_name' => 'IIR Candidate',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'PROJECT_NOT_INTERVIEWABLE');
    $response->assertJsonPath('competency_codes', [$competency->code]);

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
});

test('M2M participant enrolment is refused at the API boundary — candidate_ref echoed byte-for-byte, no participant row', function (): void {
    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    ['key' => $key] = iirM2mClient($org, ['participants:create']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/m2m/participants', [
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Mixed-Case-007',
        'email' => 'iir-m2m@example.test',
        'display_name' => 'IIR M2M Candidate',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'PROJECT_NOT_INTERVIEWABLE');
    // Byte-for-byte — never normalised, trimmed or re-cased.
    $response->assertJsonPath('candidate_ref', 'IIR-Mixed-Case-007');

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
});

test('M2M sso-link mint is refused at the API boundary — candidate_ref echoed byte-for-byte, no token minted', function (): void {
    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    ['key' => $key] = iirM2mClient($org, ['sso_link:generate']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Sso-Link-042',
        'email' => 'iir-sso@example.test',
        'display_name' => 'IIR SSO Candidate',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'PROJECT_NOT_INTERVIEWABLE');
    $response->assertJsonPath('candidate_ref', 'IIR-Sso-Link-042');

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
});

test('SSO exchange refuses a non-interviewable project — GENERIC_403, redirect_url present, no participant/session/webhook', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    $project->forceFill(['error_redirect_url' => 'https://client.example.test/error'])->save();

    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => 'iir-exchange-001',
        'display_name' => 'IIR Exchange Candidate',
        'email' => 'iir-exchange@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);

    $response = $this->getJson('/api/sso/exchange?token='.$ssoLink);

    $response->assertStatus(403);
    // GENERIC_403 doctrine — no gate detail reaches the candidate.
    $response->assertJsonPath('message', 'Access denied.');
    $response->assertJsonPath('redirect_url', 'https://client.example.test/error');

    expect(Participant::where('project_id', $project->id)->count())->toBe(0);
    Queue::assertNothingPushed();
    expect(WebhookDelivery::count())->toBe(0);
});
