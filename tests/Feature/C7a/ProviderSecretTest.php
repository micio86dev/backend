<?php

declare(strict_types=1);

/**
 * Provider secret non-exposure tests (C7a — task 14.3 RED).
 *
 * Asserts:
 * - /start response body does NOT contain HEYGEN_API_KEY or TAVUS_API_KEY value.
 * - A provider 5xx containing the key string does NOT propagate the key to the
 *   re-thrown ProviderException message OR any Laravel log channel.
 *
 * Also covers cross-org /end probe (task 14.4):
 * - cross-org /end probe → 404; no terminal-status disclosure.
 *
 * Tasks: 14.3 (RED), 14.4 (RED)
 * REQ: API key secret non-exposure (C7a task 14.3)
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function secretOrg(): Organization
{
    return Organization::factory()->create();
}

function secretProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    return Project::factory()->create(['status' => 'active']);
}

function secretProjectWithComp(Organization $org): array
{
    $project = secretProject($org);
    $comp    = Competency::factory()->create();
    \Illuminate\Support\Facades\DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $comp->id,
        'position'      => 1,
    ]);

    // C8 (M-3): seed Role + BarsIndicator so composition succeeds at /start.
    // firstOrCreate avoids unique-constraint violations when called multiple times for the same role_code.
    $role = Role::firstOrCreate(['code' => $project->role_code], [
        'name'             => ['en' => 'Secret test role'],
        'responsibilities' => ['en' => 'Secret test responsibilities'],
    ]);
    $ind  = new BarsIndicator;
    $ind->forceFill([
        'role_id'       => $role->id,
        'competency_id' => $comp->id,
        'text'          => ['en' => 'Secret test indicator'],
        'anchor_5'      => ['en' => 'Excellent'],
        'anchor_3'      => ['en' => 'Adequate'],
        'anchor_1'      => ['en' => 'Insufficient'],
        'position'      => 0,
    ]);
    $ind->save();

    return [$project, $comp];
}

function secretParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'sec-' . uniqid(),
        'display_name'    => 'Secret Test',
        'status'          => $status,
    ]);
    $p->save();
    return $p->fresh();
}

function secretBearer(Participant $participant): string
{
    return CandidateTokenFactory::mintCandidateToken($participant);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('14.3: /start response body does NOT contain HEYGEN_API_KEY value', function (): void {
    $apiKey = 'HEYGEN_API_KEY_MUST_NOT_LEAK_12345';
    config(['interview.heygen.api_key' => $apiKey]);

    Http::fake([
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-001']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id'   => 'heygen-session-001',
                'access_token' => 'ephemeral-token-001',
            ],
        ], 200),
    ]);
    Queue::fake();

    $org  = secretOrg();
    [$project] = secretProjectWithComp($org);
    $participant = secretParticipant($org, $project, 'in_attesa');
    $token       = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    // API key MUST NOT appear in the response body
    $body = $response->getContent();
    expect($body)->not->toContain($apiKey);
});

test('14.3: provider 5xx carrying the API key does NOT propagate key to log channel', function (): void {
    $apiKey = 'HEYGEN_SECRET_THAT_MUST_NOT_LOG_XYZ';
    config(['interview.heygen.api_key' => $apiKey]);

    // Provider response ECHOES the key in its error body (worst case)
    Http::fake([
        '*liveavatar*/contexts*' => Http::response(
            ['error' => "Unauthorized: {$apiKey}"],
            503
        ),
    ]);
    Queue::fake();

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        // Capture both message string and context JSON
        $msg     = $message->message ?? '';
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $logMessages[] = $msg . ' ' . $context;
    });

    $org  = secretOrg();
    [$project] = secretProjectWithComp($org);
    $participant = secretParticipant($org, $project, 'in_attesa');
    $token       = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->postJson('/api/candidate/interview/start');

    // Provider hard-failure → 502
    $response->assertStatus(502);

    // API key must NOT appear in response body
    expect($response->getContent())->not->toContain($apiKey);

    // API key must NOT appear in any log message
    foreach ($logMessages as $logMsg) {
        expect($logMsg)->not->toContain($apiKey);
    }
});

test('14.3: /start response does NOT contain TAVUS_API_KEY value', function (): void {
    $apiKey = 'TAVUS_API_KEY_MUST_NOT_LEAK_99999';
    config(['interview.tavus.api_key' => $apiKey]);

    Http::fake([
        '*tavusapi*/v2/conversations*' => Http::response([
            'conversation_id'  => 'conv-001',
            'conversation_url' => 'https://tavus.io/conv-001',
        ], 200),
    ]);
    Queue::fake();

    $org     = secretOrg();
    $project = secretProject($org);
    // Force Tavus on the project
    $project->forceFill(['provider_override' => 'tavus'])->save();
    $comp = Competency::factory()->create();
    \Illuminate\Support\Facades\DB::table('project_competencies')->insert([
        'project_id'    => $project->id,
        'competency_id' => $comp->id,
        'position'      => 1,
    ]);

    // C8 (M-3): seed Role + BarsIndicator so composition succeeds at /start.
    $role = Role::factory()->create(['code' => $project->role_code]);
    $ind  = new BarsIndicator;
    $ind->forceFill([
        'role_id'       => $role->id,
        'competency_id' => $comp->id,
        'text'          => ['en' => 'Tavus secret test indicator'],
        'anchor_5'      => ['en' => 'Excellent'],
        'anchor_3'      => ['en' => 'Adequate'],
        'anchor_1'      => ['en' => 'Insufficient'],
        'position'      => 0,
    ]);
    $ind->save();

    $participant = secretParticipant($org, $project, 'in_attesa');
    $token       = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);

    expect($response->getContent())->not->toContain($apiKey);
});

test('14.4: cross-org /end probe → 404; no terminal-status disclosure', function (): void {
    Http::fake();
    Queue::fake();

    $orgA = secretOrg();
    $orgB = secretOrg();

    [$projectA] = secretProjectWithComp($orgA);
    [$projectB] = secretProjectWithComp($orgB);

    $participantA = secretParticipant($orgA, $projectA, 'in_corso');
    $participantB = secretParticipant($orgB, $projectB, 'in_corso');

    // Session belongs to participantB (orgB)
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $sessionB = InterviewSession::create([
        'participant_id'       => $participantB->id,
        'project_id'           => $projectB->id,
        'question_index'       => 0,
        'competency_code'      => 'PRS',
        'framework_version_id' => $projectB->framework_version_id,
        'provider'             => 'heygen',
        'provider_session_ref' => 'ref-b-123',
        'status'               => 'completed', // terminal
    ]);

    // Authenticated as participantA (orgA) — cross-org probe
    $tokenA = secretBearer($participantA);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
        ->postJson('/api/candidate/interview/end', [
            'session_id'   => $sessionB->id,
            'ended_reason' => 'completed',
        ]);

    // MUST return 404 — not 409 (which would disclose 'completed' status) or 403
    $response->assertStatus(404);

    // Session unchanged
    $sessionB->refresh();
    expect($sessionB->status)->toBe('completed');
});
