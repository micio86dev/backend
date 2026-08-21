<?php

declare(strict_types=1);

/**
 * `GET /api/participants/{id}/sessions` orders by a populated `started_at`,
 * deterministically through the `id` tiebreaker when the value is absent
 * (interview-session-started-at, D2/D10, tasks 8.1-8.2).
 *
 * REQ: interview-session "Ordering by recorded start remains deterministic
 *      when the value is absent"
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function orderingAdminToken(Organization $org): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return (string) auth('api')->login($user);
}

test('a resume does not move a session up the list — started_at is write-once', function (): void {
    Queue::fake();
    Http::fake(heygenOkFake());

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project, 'in_attesa');
    $candidateToken = casBearer($participant);
    $adminToken = orderingAdminToken($org);

    // Competency 1 starts and finishes first — the older session.
    $start1 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start1->assertStatus(201);
    $session1Id = $start1->json('session_id');
    $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/end', ['session_id' => $session1Id, 'ended_reason' => 'completed'])
        ->assertStatus(200);

    // Competency 2 starts second (the newer session) and is resumed —
    // resuming must not move IT past a session that started even later,
    // and must not push competency 1 out of order either.
    $start2 = $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start');
    $start2->assertStatus(201);
    $session2Id = $start2->json('session_id');

    $before = casInTenant($org, fn () => InterviewSession::find($session2Id)->started_at);

    $this->withHeaders(['Authorization' => 'Bearer '.$candidateToken])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201); // RESUME of competency 2

    $after = casInTenant($org, fn () => InterviewSession::find($session2Id)->started_at);
    expect($after->equalTo($before))->toBeTrue();

    $response = $this->withToken($adminToken)->getJson("/api/participants/{$participant->id}/sessions");
    $response->assertOk();
    $ids = array_column($response->json('data'), 'id');

    // Newest-first: session2 (started later) before session1.
    expect($ids)->toBe([$session2Id, $session1Id]);
});

test('repeated reads of a NULL-start mix return an identical order — the id tiebreaker', function (): void {
    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project, 'in_attesa');
    $adminToken = orderingAdminToken($org);

    // Two sessions that never became live — both absent starts.
    $s1 = casInTenant($org, fn () => InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
    ]));
    $s2 = casInTenant($org, fn () => InterviewSession::factory()->create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'competency_code' => $comps[1]->code,
        'framework_version_id' => $project->framework_version_id,
    ]));

    $first = $this->withToken($adminToken)->getJson("/api/participants/{$participant->id}/sessions");
    $second = $this->withToken($adminToken)->getJson("/api/participants/{$participant->id}/sessions");

    $firstIds = array_column($first->json('data'), 'id');
    $secondIds = array_column($second->json('data'), 'id');

    expect($firstIds)->toBe($secondIds);
    // The id tiebreaker: DESC on both keys means the higher id (s2) leads.
    expect($firstIds)->toBe([$s2->id, $s1->id]);
});
