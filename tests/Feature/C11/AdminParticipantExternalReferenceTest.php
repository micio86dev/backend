<?php

declare(strict_types=1);

/**
 * The admin participant list and detail carry the candidate's external
 * reference (candidate-external-reference, slice A3a-ii).
 *
 * `external_id` (integer|null) and `source` (string|null) are ALWAYS present on
 * both resources: a consumer tells "no reference" from "a server that predates
 * the field" by the key, never by its value. `external_id` is a JSON number, not
 * a string, which is exact because it is capped at 2^53-1.
 *
 * The keys are read straight off the row — no extra query per row is allowed —
 * and a participant of another organization is still invisible, list and detail
 * alike: the fields add no read surface that the org filter of
 * `AdminParticipantReader` does not already guard.
 *
 * REQ: Participants List Carries The External Reference,
 *      Participant Detail Carries The External Reference
 *      (sdd/candidate-external-reference/spec/admin-read-api)
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

function extRefAdminToken(Organization $org, string $role = 'admin'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

function extRefProjectIn(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id]);
}

/**
 * The four combinations the reference can take, keyed by a readable name.
 *
 * @return array<string, array{0: int|null, 1: string|null}>
 */
function extRefCombinations(): array
{
    return [
        'both' => [4471, 'acme-ats'],
        'only external_id' => [4471, null],
        'only source' => [null, 'acme-ats'],
        'neither' => [null, null],
    ];
}

function extRefParticipant(Project $project, ?int $externalId, ?string $source, array $overrides = []): Participant
{
    $participant = Participant::factory()->forProject($project)->create($overrides);
    $participant->forceFill(['external_id' => $externalId, 'source' => $source])->save();

    return $participant->refresh();
}

test('the list row carries external_id and source, always present, null when absent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $token = extRefAdminToken($org);
    $participant = extRefParticipant(extRefProjectIn($org), $externalId, $source);

    $response = $this->withToken($token)->getJson('/api/participants');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $participant->id);

    expect($row)->toHaveKeys(['external_id', 'source']);
    expect($row['external_id'])->toBe($externalId);
    expect($row['source'])->toBe($source);
})->with(fn () => extRefCombinations());

test('the detail carries external_id and source, always present, null when absent', function (?int $externalId, ?string $source): void {
    $org = Organization::factory()->create();
    $token = extRefAdminToken($org);
    $participant = extRefParticipant(extRefProjectIn($org), $externalId, $source);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertOk();
    expect($response->json('data'))->toHaveKeys(['external_id', 'source']);
    expect($response->json('data.external_id'))->toBe($externalId);
    expect($response->json('data.source'))->toBe($source);
})->with(fn () => extRefCombinations());

test('external_id is a JSON number, never a string, at the largest safe integer', function (): void {
    $org = Organization::factory()->create();
    $token = extRefAdminToken($org);
    $participant = extRefParticipant(extRefProjectIn($org), 9007199254740991, 'acme-ats');

    $list = $this->withToken($token)->getJson('/api/participants');
    $detail = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    // Read the raw body: a decoded integer would hide a quoted "9007199254740991".
    expect($list->getContent())->toContain('"external_id":9007199254740991');
    expect($detail->getContent())->toContain('"external_id":9007199254740991');
});

test('every role that may read participants sees the reference on list and detail', function (string $role): void {
    $org = Organization::factory()->create();
    $token = extRefAdminToken($org, $role);
    $participant = extRefParticipant(extRefProjectIn($org), 4471, 'acme-ats');

    $list = $this->withToken($token)->getJson('/api/participants');
    $detail = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $list->assertOk();
    $detail->assertOk();
    expect(collect($list->json('data'))->firstWhere('id', $participant->id)['external_id'])->toBe(4471);
    expect($detail->json('data.source'))->toBe('acme-ats');
})->with(['admin', 'operator', 'viewer']);

test('exposing the reference adds no query per row to the list', function (): void {
    $org = Organization::factory()->create();
    $token = extRefAdminToken($org);
    $project = extRefProjectIn($org);

    extRefParticipant($project, 4471, 'acme-ats');

    // Warm-up: Spatie's role lookups are cached per process after the first
    // authenticated request, which would otherwise read as fewer queries on the
    // second call for reasons unrelated to the number of rows.
    $this->withToken($token)->getJson('/api/participants')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withToken($token)->getJson('/api/participants')->assertOk();
    $oneRow = count(DB::getQueryLog());
    DB::disableQueryLog();

    extRefParticipant($project, 4472, 'acme-ats');
    extRefParticipant($project, null, null);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withToken($token)->getJson('/api/participants')->assertOk();
    $threeRows = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($threeRows)->toBe($oneRow, 'Query count must not grow with row count — a per-row lookup of the reference would be an N+1.');
});

test('a participant of another organization is neither listed nor readable, reference included', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = extRefAdminToken($orgA);

    $ownProject = extRefProjectIn($orgA);
    $own = extRefParticipant($ownProject, 4471, 'acme-ats');
    $foreign = extRefParticipant(extRefProjectIn($orgB), 4471, 'acme-ats');

    $list = $this->withToken($token)->getJson('/api/participants');

    $list->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$own->id]);

    $this->withToken($token)->getJson("/api/participants/{$foreign->id}")->assertNotFound();
});
