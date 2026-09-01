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

use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $comp = Competency::factory()->create();
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $comp->id,
        'position' => 1,
    ]);

    // C8 (M-3): seed Role + BarsIndicator so composition succeeds at /start.
    // firstOrCreate avoids unique-constraint violations when called multiple times for the same role_code.
    $role = Role::firstOrCreate(['code' => $project->role_code], [
        'name' => ['en' => 'Secret test role'],
        'responsibilities' => ['en' => 'Secret test responsibilities'],
    ]);
    $ind = new BarsIndicator;
    $ind->forceFill([
        'role_id' => $role->id,
        'competency_id' => $comp->id,
        'text' => ['en' => 'Secret test indicator'],
        'anchor_5' => ['en' => 'Excellent'],
        'anchor_3' => ['en' => 'Adequate'],
        'anchor_1' => ['en' => 'Insufficient'],
        'position' => 0,
    ]);
    $ind->save();

    return [$project, $comp];
}

function secretParticipant(Organization $org, Project $project, string $status = 'in_attesa'): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'sec-'.uniqid(),
        'display_name' => 'Secret Test',
        'status' => $status,
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
                'session_id' => 'heygen-session-001',
                'session_token' => 'ephemeral-token-001',
            ],
        ], 200),
    ]);
    Queue::fake();

    $org = secretOrg();
    [$project] = secretProjectWithComp($org);
    $participant = secretParticipant($org, $project, 'in_attesa');
    $token = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
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
        $msg = $message->message ?? '';
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $logMessages[] = $msg.' '.$context;
    });

    $org = secretOrg();
    [$project] = secretProjectWithComp($org);
    $participant = secretParticipant($org, $project, 'in_attesa');
    $token = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
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
            'conversation_id' => 'conv-001',
            'conversation_url' => 'https://tavus.io/conv-001',
        ], 200),
    ]);
    Queue::fake();

    $org = secretOrg();
    $project = secretProject($org);
    // Force Tavus by PINNING a Tavus template, not by `provider_override`.
    //
    // `projects.avatar_template_id` is required and the pinned template decides
    // the provider, so `provider_override` can no longer be reached — it sat
    // below the template in the precedence chain and nothing can pin nothing
    // any more. Leaving it here would have left this test starting a HeyGen
    // interview against a Tavus fake, which is the 500 it produced.
    $tavusTemplate = AvatarTemplate::create([
        'name' => 'Secret tavus '.uniqid(),
        'provider' => 'tavus',
        'config' => [],
    ]);
    $project->forceFill(['avatar_template_id' => $tavusTemplate->id])->save();
    $comp = Competency::factory()->create();
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $comp->id,
        'position' => 1,
    ]);

    // C8 (M-3): seed Role + BarsIndicator so composition succeeds at /start.
    $role = Role::factory()->create(['code' => $project->role_code]);
    $ind = new BarsIndicator;
    $ind->forceFill([
        'role_id' => $role->id,
        'competency_id' => $comp->id,
        'text' => ['en' => 'Tavus secret test indicator'],
        'anchor_5' => ['en' => 'Excellent'],
        'anchor_3' => ['en' => 'Adequate'],
        'anchor_1' => ['en' => 'Insufficient'],
        'position' => 0,
    ]);
    $ind->save();

    $participant = secretParticipant($org, $project, 'in_attesa');
    $token = secretBearer($participant);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$token])
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
        'participant_id' => $participantB->id,
        'project_id' => $projectB->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $projectB->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'ref-b-123',
        'status' => 'completed', // terminal
    ]);

    // Authenticated as participantA (orgA) — cross-org probe
    $tokenA = secretBearer($participantA);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer '.$tokenA])
        ->postJson('/api/candidate/interview/end', [
            'session_id' => $sessionB->id,
            'ended_reason' => 'completed',
        ]);

    // MUST return 404 — not 409 (which would disclose 'completed' status) or 403
    $response->assertStatus(404);

    // Session unchanged
    $sessionB->refresh();
    expect($sessionB->status)->toBe('completed');
});

// ─── P4: the managed-mode Gemini key across a full Tavus PAL sync cycle ──────

test('P4: the Gemini credential key appears in no response, no exception, and no log channel across a Tavus PAL sync', function (): void {
    config()->set('interview.tavus.api_key', 'platform-tavus-key');
    $geminiKey = 'GEMINI_KEY_MUST_NOT_LEAK_ANYWHERE_777';

    $org = secretOrg();
    $token = authTokenForRole($org, 'admin');

    $model = LlmModel::create([
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => 'text',
        'is_available' => true,
        'sort_order' => 0,
    ]);

    $credential = TenantContextScope::runFor($org->id, function () use ($org, $geminiKey): LlmCredential {
        $credential = new LlmCredential;
        $credential->forceFill([
            'organization_id' => $org->id,
            'name' => 'Secret test credential',
            'vendor' => 'google',
            'api_key' => $geminiKey,
            'key_last_four' => substr($geminiKey, -4),
            'key_fingerprint' => hash('sha256', $geminiKey),
        ]);
        $credential->save();

        return $credential;
    });

    $template = TenantContextScope::runFor($org->id, fn (): AvatarTemplate => AvatarTemplate::create([
        'name' => 'Secret test template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'r', 'palId' => 'p_secret'],
        'llm_model_id' => $model->id,
        'llm_credential_id' => $credential->id,
    ]));

    // Worst case (14.3's own doctrine, applied to PAL sync): the vendor
    // ECHOES something resembling the key back in its error body.
    Http::fake(['*pals*' => Http::response(['message' => "Unauthorized: {$geminiKey}"], 401)]);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $msg = $message->message ?? '';
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $logMessages[] = $msg.' '.$context;
    });

    $response = $this->withToken($token)
        ->patchJson("/api/avatar-templates/{$template->id}", ['name' => 'Secret test template renamed']);

    $response->assertSuccessful();
    expect($response->getContent())->not->toContain($geminiKey);

    foreach ($logMessages as $logMsg) {
        expect($logMsg)->not->toContain($geminiKey);
    }

    // The outbound PATCH itself carries the key (it must — the vendor has no
    // vault and does not retain a previously-submitted key, per the Phase
    // 0.3(c) live smoke-check), but that is a REQUEST we send, never a
    // response/exception/log surface we control from the caller's side.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/pals/p_secret')
        && $request->data()[0]['value']['llm']['api_key'] === $geminiKey);
});
