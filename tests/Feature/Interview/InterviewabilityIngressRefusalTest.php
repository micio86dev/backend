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
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Project\ProjectInterviewability;
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

/**
 * Z10 (R3-mint-refuses-midinterview-candidate, REQUIRED BEFORE ARCHIVE): the
 * SAME "already has a session" exemption the SSO exchange and `/start`
 * already apply at USE time (Z9) now also applies at MINT time — a
 * mid-interview candidate whose token expired can be issued a replacement
 * link, even though an UNRELATED competency later lost its questions.
 */
test('entry-link mint is NOT refused for a candidate who already has an InterviewSession on the project', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.test']);
    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    $token = iirOperatorToken($org);

    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'iir-midinterview-001',
    ]);
    InterviewSession::factory()->create(['participant_id' => $participant->id, 'project_id' => $project->id, 'framework_version_id' => $project->framework_version_id]);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'iir-midinterview-001',
        'email' => 'iir-midinterview@example.test',
        'display_name' => 'IIR Midinterview Candidate',
    ]);

    $response->assertCreated();
});

/**
 * Z10, tenancy correctness (gga review finding, blocking): `Participant`
 * extends plain `Model`, not `TenantModel` — no global scope protects
 * `evaluateForCandidate()`'s own lookup, so `organization_id` must be an
 * explicit filter, never left to `project_id` alone.
 *
 * `project_id` alone happens to already disambiguate every row an ORDINARY
 * write path produces (organization_id is always stamped FROM the resolved
 * project), so a fixture built through the normal factory cannot actually
 * exercise the missing filter — it would pass identically with or without
 * it. This test instead builds the ROW SHAPE the rule exists to guard
 * against: `forceFill()` an inconsistent participant whose `project_id`
 * points at project A but whose `organization_id` claims org B — nothing at
 * the DB layer forbids this combination (no composite FK ties the two
 * columns together), so a raw write or a future bug could produce it. With
 * only a `project_id` filter this participant's session would still be
 * (incorrectly) found; the explicit `organization_id` filter excludes it.
 */
test('Z10: evaluateForCandidate ignores a session whose participant row disagrees on organization_id', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    [$projectA] = iirNonInterviewableProject($orgA);

    $inconsistentParticipant = Participant::factory()->create([
        'organization_id' => $orgA->id,
        'project_id' => $projectA->id,
        'candidate_ref' => 'iir-cross-tenant-001',
    ]);
    $inconsistentParticipant->forceFill(['organization_id' => $orgB->id])->save();

    InterviewSession::factory()->create([
        'participant_id' => $inconsistentParticipant->id,
        'project_id' => $projectA->id,
        'framework_version_id' => $projectA->framework_version_id,
        'organization_id' => $orgB->id,
    ]);

    $result = app(ProjectInterviewability::class)->evaluateForCandidate($projectA, 'iir-cross-tenant-001');

    expect($result['interviewable'])->toBeFalse();
});

test('M2M sso-link mint is NOT refused for a candidate who already has an InterviewSession on the project', function (): void {
    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    ['key' => $key] = iirM2mClient($org, ['sso_link:generate']);

    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Sso-Midinterview-001',
    ]);
    InterviewSession::factory()->create(['participant_id' => $participant->id, 'project_id' => $project->id, 'framework_version_id' => $project->framework_version_id]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Sso-Midinterview-001',
        'email' => 'iir-sso-midinterview@example.test',
        'display_name' => 'IIR SSO Midinterview Candidate',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure(['token']);
});

/**
 * M2M enrolment (`ParticipantController::store()`) ALWAYS creates a brand
 * new `Participant` row — it has no find-or-create path. A `candidate_ref`
 * that already has a session already has a participant row too, so the
 * exemption lets the request past the interviewability gate only to hit the
 * pre-existing `(project_id, candidate_ref)` unique constraint instead.
 *
 * ASSERTS THE ACTUAL, PRE-EXISTING OUTCOME EXPLICITLY (gga review finding,
 * blocking — a bare `not->toBe(422)` also passes on a crash, which is not
 * evidence of anything): this app registers no global `QueryException`
 * handler (same fact `StoreDefaultQuestionRequest`'s own docblock states for
 * the catalogue surface), so the unique-constraint collision surfaces as an
 * uncaught 500 — a DIFFERENT, pre-existing defect this task does not fix
 * (Z10 is the interviewability exemption; mapping this constraint to a
 * clean 409 is untouched, separate scope). What this test actually proves is
 * narrower and correct: interviewability is no longer the FIRST thing
 * refusing the request. The DB-level guarantee (still only ONE participant
 * row survives) is the unique constraint's own job, already covered by the
 * constraint itself — a post-500 query here would run inside the SAME
 * now-aborted Postgres transaction (`RefreshDatabase`'s wrapping one) and
 * fail with "current transaction is aborted", proving nothing about this
 * endpoint.
 */
test('M2M participant enrolment exemption reaches the pre-existing duplicate-participant 500, not PROJECT_NOT_INTERVIEWABLE', function (): void {
    $org = Organization::factory()->create();
    [$project] = iirNonInterviewableProject($org);
    ['key' => $key] = iirM2mClient($org, ['participants:create']);

    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Enrol-Midinterview-001',
    ]);
    InterviewSession::factory()->create(['participant_id' => $participant->id, 'project_id' => $project->id, 'framework_version_id' => $project->framework_version_id]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/m2m/participants', [
        'project_id' => $project->id,
        'candidate_ref' => 'IIR-Enrol-Midinterview-001',
        'email' => 'iir-enrol-midinterview@example.test',
        'display_name' => 'IIR Enrol Midinterview Candidate',
    ]);

    $response->assertStatus(500);
});
