<?php

declare(strict_types=1);

/**
 * Transcript download's question numbering for a project's first competency
 * (interview-question-index-offset, D1).
 *
 * `ParticipantDownloadController::renderTranscriptText()` already renders
 * `question_index + 1` — that arithmetic was never wrong. The defect visible here
 * comes entirely from what `resolveNextCompetency()` PERSISTED. Driven through the
 * real `/start` endpoint (not a hand-written `question_index` literal — e.g.
 * `AdminDownloadTest.php:65` writes `question_index => 0` directly on a
 * manually-constructed row and therefore cannot fail on this defect) so the value
 * under test is the one the real writer produced.
 *
 * REQ: interview-session "Downstream question numbering derived from question_index
 *      starts at 1".
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function qioAdminToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

test('transcript download renders the first competency as "question 1", not "question 0"', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $competencies] = casDenseProject($org, 1);
    $participant = casParticipant($org, $project, 'in_attesa');

    $this->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    // The transcript read gate is a participant-lifecycle check
    // (ParticipantReadScope::Transcript >= in_valutazione), independent of the
    // session's own status — flipped directly rather than driving the whole
    // interview to completion, which is not the behaviour under test here.
    // /start already advanced the participant to in_corso; one more legal hop
    // (in_corso -> in_valutazione, per the allowed-transitions map) clears the gate.
    $participant->fresh()->forceFill(['status' => 'in_valutazione'])->save();

    $adminToken = qioAdminToken($org);

    $response = $this->withToken($adminToken)
        ->get("/api/participants/{$participant->id}/transcript/download")
        ->assertOk();

    expect($response->getContent())
        ->toContain(sprintf('%s (question 1)', $competencies[0]->code));
});
