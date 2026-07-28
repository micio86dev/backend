<?php

declare(strict_types=1);

/**
 * RED — ParticipantDetailResource `files` open map (C11 PR A3, task 8.1/D9).
 *
 * Deferred from A2 because it needs the download routes this PR adds
 * (ParticipantDownloadController + the named `admin.participants.*.download`
 * routes). The map is keyed by type — `{ transcript: {...}, evaluation_raw:
 * {...} }` — and stays open for a future `audio` key (D9), never a shape
 * change. The gate on each file's URL is identical to the corresponding read
 * endpoint's gate: the URL is always present, but hitting it before the
 * lifecycle threshold returns 409, exactly like the read endpoint (D9 —
 * "same AdminParticipantReader::read() call, so a 409 body is returned
 * instead of a file").
 *
 * REQ: Downloadable Artifacts Are Limited to Transcript and Evaluation
 *      (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function filesMapAdminUser(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function filesMapParticipant(Organization $org, string $status): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

test('GET /api/participants/{id} exposes a files open map with real download URLs', function (): void {
    $org = Organization::factory()->create();
    $token = filesMapAdminUser($org);
    $participant = filesMapParticipant($org, 'completato');

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertOk();
    $response->assertJsonPath('data.files.transcript.type', 'text/plain');
    $response->assertJsonPath('data.files.evaluation_raw.type', 'application/json');

    $transcriptUrl = $response->json('data.files.transcript.url');
    $evaluationUrl = $response->json('data.files.evaluation_raw.url');

    expect($transcriptUrl)->toBe(route('admin.participants.transcript.download', $participant->id));
    expect($evaluationUrl)->toBe(route('admin.participants.evaluation.download', $participant->id));
});
