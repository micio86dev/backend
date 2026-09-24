<?php

declare(strict_types=1);

/**
 * RED — 22.2 (framework-catalogue-authoring PR6, D6): mint-time and use-time
 * are DISTINCT evaluations of the same predicate. A link/token minted while
 * the project was interviewable is refused at USE if the project stops
 * being interviewable before the candidate arrives — the mint-time check is
 * not a standing exemption.
 */

use App\Models\ApiClient;
use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{project: Project, competency: Competency, question: ProjectQuestion}
 */
function seInterviewableProject(Organization $org): array
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
        ['code' => 'SECOMP'],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );

    $project->competencies()->attach([$competency->id => ['position' => 0]]);

    $question = ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'x'],
        'position' => 0,
    ]);

    return ['project' => $project, 'competency' => $competency, 'question' => $question];
}

function seOperatorToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function seM2mKey(Organization $org, array $abilities): string
{
    $rawKey = ApiKeyGenerator::generate();
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'abilities' => $abilities,
    ]);

    return $rawKey;
}

function seTokenFromEntryUrl(string $entryUrl): string
{
    $segments = explode('/', rtrim($entryUrl, '/'));

    return end($segments);
}

test('an entry link minted while interviewable is still refused at exchange once the project stops being interviewable', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.test']);

    $org = Organization::factory()->create();
    ['project' => $project, 'question' => $question] = seInterviewableProject($org);
    $operatorToken = seOperatorToken($org);

    // Mint WHILE interviewable — succeeds.
    $mint = $this->withToken($operatorToken)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'se-entry-001',
        'email' => 'se-entry@example.test',
        'display_name' => 'SE Candidate',
    ]);
    $mint->assertCreated();
    $token = seTokenFromEntryUrl($mint->json('entry_url'));

    // The project stops being interviewable BEFORE the candidate arrives.
    $question->delete();

    $exchange = $this->getJson('/api/sso/exchange?token='.$token);

    // Refused exactly as if it had never been interviewable — GENERIC_403,
    // never a standing exemption from the mint-time check.
    $exchange->assertStatus(403);
    $exchange->assertJsonPath('message', 'Access denied.');
});

test('an M2M sso-link token minted while interviewable is still refused at exchange once the project stops being interviewable', function (): void {
    $org = Organization::factory()->create();
    ['project' => $project, 'question' => $question] = seInterviewableProject($org);
    $m2mKey = seM2mKey($org, ['sso_link:generate']);

    $mint = $this->withHeaders(['Authorization' => 'Bearer '.$m2mKey])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'se-sso-001',
        'email' => 'se-sso@example.test',
        'display_name' => 'SE SSO Candidate',
    ]);
    $mint->assertCreated();
    $token = $mint->json('token');

    $question->delete();

    $exchange = $this->getJson('/api/sso/exchange?token='.$token);

    $exchange->assertStatus(403);
    $exchange->assertJsonPath('message', 'Access denied.');
});

test('SSO exchange exempts a candidate who already has an InterviewSession from an UNRELATED, not-yet-reached competency losing its question', function (): void {
    // gga review finding: the exemption read originally used a plain
    // `TenantModel` query on THIS exact no-tenant-context path, so it
    // silently matched zero rows forever and never actually fired.
    // Proven here with the `TenantResolver` EXPLICITLY cleared before the
    // assertion-bearing call — a resolver left set from fixture setup would
    // mask exactly that bug (the same trap the review flagged).
    Queue::fake();

    $org = Organization::factory()->create();
    ['project' => $project, 'competency' => $compA] = seInterviewableProject($org);

    // A SECOND, currently-fine competency the candidate has not reached yet.
    $compB = Competency::firstOrCreate(
        ['code' => 'SECOMPB'],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );
    $project->competencies()->attach([$compB->id => ['position' => 1]]);
    $questionB = ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $compB->id,
        'text' => ['en' => 'x'],
        'position' => 0,
    ]);

    $candidateRef = 'se-returning-001';
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => $candidateRef,
        'display_name' => 'SE Returning Candidate',
        'email' => 'se-returning@example.test',
        // 'in_attesa' is required for Step 8's blocked-status check to pass
        // at all — a status this fixture must set deliberately, not the one
        // Step 6b is under test for.
        'status' => 'in_attesa',
        'started_at' => now()->subMinutes(10),
    ]);
    $participant->save();

    InterviewSession::factory()->live()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'participant_id' => $participant->id,
        'competency_code' => $compA->code,
    ]);

    // The operator empties competency B's only question — the project as a
    // WHOLE is now non-interviewable.
    $questionB->delete();

    $ssoLink = CandidateTokenFactory::mintSsoLink([
        'candidate_ref' => $candidateRef,
        'display_name' => 'SE Returning Candidate',
        'email' => 'se-returning@example.test',
        'project_id' => $project->id,
        'org_id' => $org->id,
        'role_code' => $project->role_code,
        'lang' => 'en',
    ]);

    app(TenantResolver::class)->setOrgId(null);
    app(TenantResolver::class)->setBypass(false);

    $exchange = $this->getJson('/api/sso/exchange?token='.$ssoLink);

    $exchange->assertOk();
    $exchange->assertJsonStructure(['access_token']);
});
