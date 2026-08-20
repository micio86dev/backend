<?php

declare(strict_types=1);

/**
 * RED — POST /api/entry-links mint behaviour (operator-interview-link).
 *
 * Gates 403, role_code 422, terminal 409, missing CANDIDATE_APP_URL 500 — the
 * same refusal reasons the M2M mint produces, mapped onto THIS endpoint's own
 * response shape (`{ entry_url, expires_at }` on success, never a bare
 * token — design D1/D3).
 *
 * REQ: Operator-Facing Entry Link Mint Endpoint,
 *      Entry Link Response Composes the Absolute URL,
 *      A project that cannot accept a candidate refuses the mint,
 *      CANDIDATE_APP_URL Fails Loud When Unset
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function mintTestOperator(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function mintTestProject(Organization $org, array $attrs = []): Project
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

test('a successful mint returns entry_url and expires_at, no bare token', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org);
    $token = mintTestOperator($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand',
        'display_name' => 'Mint Candidate',
        'lang' => 'en',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['entry_url', 'expires_at']);
    expect($response->json('entry_url'))->toBeString();
    expect($response->json('entry_url'))->toStartWith('https://interview.example.com/en/interview/');
    expect($response->json())->not->toHaveKey('token');
});

test('a project failing an entry gate refuses the mint with 403', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org, ['deadline_at' => now()->subHour()]);
    $token = mintTestOperator($org);

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-gate',
        'display_name' => 'Mint Candidate',
    ])->assertStatus(403);
});

test('a role_code mismatch refuses the mint with 422', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org, ['role_code' => 'ICO']);
    $token = mintTestOperator($org);

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-role',
        'display_name' => 'Mint Candidate',
        'role_code' => 'FLL',
    ])->assertStatus(422);
});

test('a completed participant refuses the mint with 409 and reason completed', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org);
    $token = mintTestOperator($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-terminal',
        'display_name' => 'Done',
        'status' => 'in_valutazione',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'completato']);

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-terminal',
        'display_name' => 'Done',
    ])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Conflict: participant has already completed this assessment.',
            'reason' => 'completed',
        ]);
});

test('a failed (errore) participant refuses the mint with 409, reason failed, and never says completed', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org);
    $token = mintTestOperator($org);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-failed',
        'display_name' => 'Errored',
        'status' => 'in_attesa',
    ]);
    $p->save();
    DB::table('participants')->where('id', $p->id)->update(['status' => 'errore']);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-failed',
        'display_name' => 'Errored',
    ]);

    $response->assertStatus(409)->assertJson(['reason' => 'failed']);
    expect($response->json('message'))->not->toContain('completed');
});

test('lang omitted resolves end-to-end through the composer: project.language=en produces an /en/-prefixed entry_url', function (): void {
    // Unit-level coverage of this fallback chain already exists
    // (EntryLinkMinterTest: "lang omitted falls back to the project
    // language" asserts $minted->lang === 'en') — this proves the SAME
    // fallback actually reaches the composed URL end-to-end, through the
    // real HTTP mint, not just the minter's own return value.
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org, ['language' => 'en']);
    $token = mintTestOperator($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-lang-fallback',
        'display_name' => 'Mint Candidate',
        // lang omitted — must fall back to project.language.
    ]);

    $response->assertStatus(201);
    expect($response->json('entry_url'))->toStartWith('https://interview.example.com/en/interview/');
});

test('lang omitted with project.language=it produces an unprefixed entry_url (the default locale)', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    $org = Organization::factory()->create();
    $project = mintTestProject($org, ['language' => 'it']);
    $token = mintTestOperator($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-lang-fallback-it',
        'display_name' => 'Mint Candidate',
    ]);

    $response->assertStatus(201);
    $entryUrl = $response->json('entry_url');
    expect($entryUrl)->toStartWith('https://interview.example.com/interview/');
    expect($entryUrl)->not->toContain('/it/interview/');
});

test('a missing CANDIDATE_APP_URL fails loud with 500, not a malformed 201', function (): void {
    config(['interview.candidate_app_url' => null]);
    $org = Organization::factory()->create();
    $project = mintTestProject($org);
    $token = mintTestOperator($org);

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'mint-cand-unconfigured',
        'display_name' => 'Mint Candidate',
    ]);

    $response->assertStatus(500);
    $response->assertJson(['message' => 'Entry link URL is not configured.']);
});
