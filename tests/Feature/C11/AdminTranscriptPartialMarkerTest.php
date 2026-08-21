<?php

declare(strict_types=1);

/**
 * RED — Partial Transcript Disclosure Marker (C11 operator-participant-visibility
 * D2, task 1.10).
 *
 * The marker must be PRESENT on both the JSON read and the .txt download for
 * the same participant, and the two must AGREE — not merely "the happy path
 * is labelled". Presence is asserted via `assertJsonStructure` (a missing key
 * fails structurally) rather than a truthiness check on the value, since an
 * omitted key would be indistinguishable from `false` under a naive check —
 * exactly the concealment failure mode D2 exists to remove.
 *
 * REQ: Partial Transcript Disclosure Marker
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function transcriptMarkerToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function transcriptMarkerParticipant(Organization $org, string $status): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus($status)->create();

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'COL',
        'framework_version_id' => $fv->id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    return $participant;
}

test('is_partial is present on the JSON read and matches the expected disclosure value', function (string $status, bool $expectedPartial): void {
    $org = Organization::factory()->create();
    $token = transcriptMarkerToken($org);
    $participant = transcriptMarkerParticipant($org, $status);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript");

    $response->assertOk();
    $response->assertJsonStructure(['data' => ['is_partial', 'sessions']]);
    $response->assertJsonPath('data.is_partial', $expectedPartial);
})->with([
    'in_corso is partial' => ['in_corso', true],
    'errore is partial' => ['errore', true],
    'in_valutazione is complete' => ['in_valutazione', false],
    'completato is complete' => ['completato', false],
]);

test('the .txt download carries an unconditional partial header that agrees with the JSON read', function (string $status): void {
    $org = Organization::factory()->create();
    $token = transcriptMarkerToken($org);
    $participant = transcriptMarkerParticipant($org, $status);

    $read = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript");
    $download = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript/download");

    $read->assertOk();
    $download->assertOk();

    $jsonIsPartial = $read->json('data.is_partial');
    expect($jsonIsPartial)->toBeBool();

    $body = $download->getContent();
    $expectedHeader = 'partial: '.($jsonIsPartial ? 'true' : 'false');

    expect($body)->toContain($expectedHeader);
    expect($body)->toContain("status: {$status}");
})->with(['in_corso', 'errore', 'in_valutazione', 'completato']);
