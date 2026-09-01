<?php

declare(strict_types=1);

/**
 * SsoLinkController wire-shape golden test (operator-interview-link, design D1
 * step 2 of the byte-identity proof).
 *
 * `assertExactJson` + status, for every distinct response shape
 * `POST /api/m2m/sso-link` produces: 201, 403 (gates), 409 (terminal), 422
 * (role_code), 404 (cross-org). Written and run GREEN against the CURRENT,
 * unmodified `SsoLinkController` — BEFORE the `EntryLinkMinter` extraction
 * starts. Re-run with ZERO edits after the extraction (task 2.15): any diff
 * in this file's assertions between the two runs is a wire-shape regression.
 *
 * REQ: M2M mint response is unchanged after the extraction
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function goldenM2mClient(Organization $org, array $abilities = ['sso_link:generate']): array
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

function goldenActiveProject(Organization $org, array $attrs = []): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(array_merge([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'goes_live_at' => null,
        'deadline_at' => null,
    ], $attrs));
}

test('golden: 201 response carries exactly a token field, nothing else', function (): void {
    $org = Organization::factory()->create();
    $project = goldenActiveProject($org);
    $m2m = goldenM2mClient($org);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'golden-cand',
        'display_name' => 'Golden Candidate',
        'email' => uniqid('cand-').'@example.test',
        'role_code' => 'ICO',
        'lang' => 'en',
    ]);

    $response->assertStatus(201);
    $response->assertExactJson(['token' => $response->json('token')]);
    expect($response->json('token'))->toBeString();
});

test('golden: 403 gate refusal body is exactly the literal Access denied message', function (): void {
    $org = Organization::factory()->create();
    $project = goldenActiveProject($org, ['deadline_at' => now()->subHour()]);
    $m2m = goldenM2mClient($org);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'golden-cand-403',
        'display_name' => 'Golden Candidate',
        'email' => uniqid('cand-').'@example.test',
    ]);

    $response->assertStatus(403);
    $response->assertExactJson(['message' => 'Access denied.']);
});

test('golden: 409 mint-gate refusal body is exactly the literal Conflict message plus reason (participant-error-recovery D3)', function (): void {
    $org = Organization::factory()->create();
    $project = goldenActiveProject($org);
    $m2m = goldenM2mClient($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'golden-done',
        'display_name' => 'Done',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'completato']);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'golden-done',
        'display_name' => 'Done',
        'email' => uniqid('cand-').'@example.test',
    ]);

    $response->assertStatus(409);
    // (participant-error-recovery D3) The Terminal reason was split into
    // Completed/Failed — the machine-facing `reason` field is a deliberate,
    // additive wire-shape change: an `errore` participant is now recoverable,
    // so the calling system needs to tell the two 409s apart.
    $response->assertExactJson([
        'message' => 'Conflict: participant has already completed this assessment.',
        'reason' => 'completed',
    ]);
});

test('golden: 422 role_code mismatch body is exactly the literal validation error shape', function (): void {
    $org = Organization::factory()->create();
    $project = goldenActiveProject($org, ['role_code' => 'ICO']);
    $m2m = goldenM2mClient($org);

    $response = $this->withToken($m2m['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $project->id,
        'candidate_ref' => 'golden-422',
        'display_name' => 'Golden Candidate',
        'email' => uniqid('cand-').'@example.test',
        'role_code' => 'FLL',
    ]);

    $response->assertStatus(422);
    $response->assertExactJson([
        'message' => 'The given data was invalid.',
        'errors' => ['role_code' => ['role_code does not match the project role.']],
    ]);
});

test('golden: 404 cross-org body matches the default model-not-found shape', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $m2mA = goldenM2mClient($orgA);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $projectB = Project::factory()->create(['status' => 'active']);

    $response = $this->withToken($m2mA['key'])->postJson('/api/m2m/sso-link', [
        'project_id' => $projectB->id,
        'candidate_ref' => 'golden-404',
        'display_name' => 'Golden Candidate',
        'email' => uniqid('cand-').'@example.test',
    ]);

    $response->assertStatus(404);
    $response->assertExactJson([
        'message' => 'No query results for model [App\\Models\\Project] '.$projectB->id,
    ]);
});
