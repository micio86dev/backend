<?php

declare(strict_types=1);

/**
 * The interview flow on `hr_avatar` (hr-avatar-adapter A2b): /start -> /utterance -> /end, resume,
 * suspend, failure matrix, secret confinement and accounting. Every request goes through the real
 * controller against a faked HR-Avatar; HeyGen and Tavus are bound to throw if constructed.
 */

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\User;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\TavusProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

const HRA_FLOW_SECRET_KEY = 'hra_sk_live_SECRETVALUE';

beforeEach(function (): void {
    config(['hr_avatar' => [
        'base_url' => 'https://hra.example.test',
        'secret_key' => HRA_FLOW_SECRET_KEY,
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ]]);
    config(['logging.default' => 'null']);
    Queue::fake();
    app()->bind(HeygenProvider::class, fn () => throw new RuntimeException('HeygenProvider must not be constructed'));
    app()->bind(TavusProvider::class, fn () => throw new RuntimeException('TavusProvider must not be constructed'));
});

/** Each create answers a fresh `conv_FLOW<n>` / `CS_FLOW<n>`; an end answers `$endStatus`. */
function hraFlowFake(int $createStatus = 201, int $endStatus = 200, mixed $createBody = null): void
{
    $count = new ArrayObject(['n' => 0]);

    Http::fake([
        'hra.example.test/v1/conversations' => function () use ($count, $createStatus, $createBody) {
            $count['n']++;

            return Http::response(
                $createBody ?? ['id' => 'conv_FLOW'.$count['n'], 'client_secret' => 'CS_FLOW'.$count['n']],
                $createStatus,
            );
        },
        'hra.example.test/v1/conversations/*/end' => Http::response(['status' => 'ended'], $endStatus),
    ]);
    Http::preventStrayRequests();
}

/** @return array{0: Organization, 1: Model, 2: Participant, 3: string} */
function hraFlowScenario(int $competencies = 3): array
{
    return hraStartScenario('hr_avatar', ['hr_avatar_config_id' => 'avc_01HXYZ'], $competencies);
}

function hraFlowStart(string $token, array $body = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/start', $body);
}

function hraFlowEnd(string $token, int $sessionId, string $reason = 'completed'): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/end', ['session_id' => $sessionId, 'ended_reason' => $reason]);
}

/** @return list<string> request URLs whose path ends with the suffix */
function hraSentTo(string $suffix): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), $suffix))
        ->map(fn (array $pair): string => $pair[0]->url())->values()->all();
}

function hraSessionRow(int $participantId, ?string $competency = null): object
{
    $query = DB::table('interview_sessions')->where('participant_id', $participantId)->orderBy('id');

    return ($competency === null ? $query : $query->where('competency_code', $competency))->firstOrFail();
}

// ─── The start response ──────────────────────────────────────────────────────

test('the 201 body carries exactly the join inputs and none of the continuation members', function (): void {
    hraFlowFake();
    [, , $participant, $token] = hraFlowScenario();

    $response = hraFlowStart($token)->assertStatus(201);

    $response->assertJsonPath('provider', 'hr_avatar')
        ->assertJsonPath('provider_token', 'CS_FLOW1')
        ->assertJsonPath('conversation_url', null)
        ->assertJsonPath('hr_avatar', ['base_url' => 'https://hra.example.test', 'publishable_key' => 'hra_pk_live_PUBLISHABLE']);
    expect($response->json())->not->toHaveKeys(['conversation_id', 'conversation_ttl_seconds', 'continuation']);
    expect($response->json('question_context'))->toHaveKeys(['end_phrase', 'final_phrase', 'prompt_version', 'total_competencies']);
    expect(hraSessionRow($participant->id)->provider_session_ref)->toBe('conv_FLOW1');
});

test('a mock session carries no hr_avatar member', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    [, , $participant, $token] = hraFlowScenario();
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    $response = hraFlowStart($token)->assertStatus(201)->assertJsonPath('provider', 'mock');

    expect($response->json())->not->toHaveKey('hr_avatar');
});

test('an inbound live_conversation_id is ignored: a fresh conversation, no continuation', function (): void {
    hraFlowFake();
    [, , , $token] = hraFlowScenario();

    $response = hraFlowStart($token, ['live_conversation_id' => 'conv_FOREIGN'])->assertStatus(201);

    expect($response->json())->not->toHaveKey('continuation');
    expect(hraSentTo('/v1/conversations'))->toHaveCount(1);
    expect(hraSentTo('/conversations/conv_FOREIGN/end'))->toBe([]);
});

test('neither the secret key nor the composed prompt reaches the response, and the client secret is returned once', function (): void {
    hraFlowFake();
    [, , , $token] = hraFlowScenario();

    $response = hraFlowStart($token)->assertStatus(201);

    $prompt = (string) Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/conversations'))
        ->first()[0]->data()['system_prompt'];
    $everything = $response->getContent().json_encode($response->headers->all());

    expect($prompt)->not->toBe('');
    expect($everything)->not->toContain(HRA_FLOW_SECRET_KEY)->not->toContain(mb_substr($prompt, 0, 60));
    expect(substr_count((string) $response->getContent(), 'CS_FLOW1'))->toBe(1);
});

test('the client secret is stored in no row, log, cache entry or queued job', function (): void {
    hraFlowFake();
    $logs = new ArrayObject;
    Log::listen(function ($event) use ($logs): void {
        $logs->append((string) json_encode([$event->message, $event->context]));
    });
    [, , $participant, $token] = hraFlowScenario();

    hraFlowStart($token)->assertStatus(201);
    $store = new ReflectionProperty(Cache::getStore(), 'storage');

    expect(json_encode(hraSessionRow($participant->id)))->not->toContain('CS_FLOW1');
    expect(json_encode(DB::table('interview_session_live_periods')->get()))->not->toContain('CS_FLOW1');
    expect(implode("\n", $logs->getArrayCopy()))->not->toContain('CS_FLOW1');
    expect(serialize($store->getValue(Cache::getStore())))->not->toContain('CS_FLOW1');
    expect(serialize(Queue::pushedJobs()))->not->toContain('CS_FLOW1');
});

// ─── Conversations per competency ────────────────────────────────────────────

test('three competencies run on three distinct conversations and contact only HR-Avatar', function (): void {
    hraFlowFake();
    [, , $participant, $token] = hraFlowScenario(3);

    foreach ([1, 2, 3] as $n) {
        $session = hraFlowStart($token)->assertStatus(201)->json('session_id');
        hraFlowEnd($token, $session)->assertOk();
    }

    $refs = DB::table('interview_sessions')->where('participant_id', $participant->id)->orderBy('id')->pluck('provider_session_ref')->all();
    expect($refs)->toBe(['conv_FLOW1', 'conv_FLOW2', 'conv_FLOW3']);
    expect(hraSentTo('/v1/conversations'))->toHaveCount(3);
    expect(DB::table('interview_sessions')->where('participant_id', $participant->id)->whereNotNull('conversation_plan')->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'hra.example.test'));
});

test('a competency that completes with more to come releases its conversation at once', function (): void {
    hraFlowFake();
    [, , , $token] = hraFlowScenario(2);
    $session = hraFlowStart($token)->assertStatus(201)->json('session_id');

    hraFlowEnd($token, $session)->assertOk()->assertJsonPath('next_action', 'continue');

    expect(hraSentTo('/conversations/conv_FLOW1/end'))->toHaveCount(1);
});

// ─── Resume ──────────────────────────────────────────────────────────────────

test('resuming a live session issues a new conversation and ends the old one, even when that end fails', function (int $endStatus): void {
    hraFlowFake(endStatus: $endStatus);
    [, , $participant, $token] = hraFlowScenario(1);
    hraFlowStart($token)->assertStatus(201);

    $second = hraFlowStart($token)->assertStatus(201);

    $second->assertJsonPath('provider_token', 'CS_FLOW2');
    expect(hraSentTo('/conversations/conv_FLOW1/end'))->toHaveCount(1);
    expect(DB::table('interview_sessions')->where('participant_id', $participant->id)->count())->toBe(1);
    expect(hraSessionRow($participant->id)->provider_session_ref)->toBe('conv_FLOW2');
})->with(['end succeeds' => [200], 'end fails with 503' => [503]]);

test('a start after a browser connect failure leaves the participant live on a fresh conversation', function (): void {
    hraFlowFake();
    [, , $participant, $token] = hraFlowScenario(1);
    hraFlowStart($token)->assertStatus(201);

    hraFlowStart($token)->assertStatus(201);

    expect(hraSentTo('/v1/conversations'))->toHaveCount(2);
    expect($participant->fresh()->status)->toBe('in_corso');
    expect(hraSessionRow($participant->id)->status)->toBe('in_corso');
});

// ─── Transcript of record ────────────────────────────────────────────────────

test('ending a session keeps the live utterances and asks HR-Avatar for no transcript', function (): void {
    hraFlowFake();
    [, , $participant, $token] = hraFlowScenario(1);
    $sessionId = hraFlowStart($token)->assertStatus(201)->json('session_id');

    foreach (range(1, 6) as $i) {
        test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/utterance', [
            'session_id' => $sessionId,
            'speaker' => $i % 2 === 1 ? 'avatar' : 'candidate',
            'text' => "Utterance {$i}",
            'ts' => now()->addSeconds($i)->toIso8601String(),
        ])->assertSuccessful();
    }
    $before = DB::table('utterances')->where('interview_session_id', $sessionId)->orderBy('id')->pluck('id')->all();

    hraFlowEnd($token, $sessionId)->assertOk();

    expect($before)->toHaveCount(6);
    expect(DB::table('utterances')->where('interview_session_id', $sessionId)->orderBy('id')->pluck('id')->all())->toBe($before);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET' || str_contains($request->url(), 'transcript'));
});

// ─── Suspend ─────────────────────────────────────────────────────────────────

test('suspending a live session ends exactly its own conversation and fetches no transcript', function (): void {
    hraFlowFake();
    [, , , $token] = hraFlowScenario(2);
    $sessionId = hraFlowStart($token)->assertStatus(201)->json('session_id');

    test()->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/candidate/interview/suspend', ['session_id' => $sessionId])->assertOk();

    expect(hraSentTo('/conversations/conv_FLOW1/end'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET' || str_contains($request->url(), 'transcript'));
});

// ─── Accounting ──────────────────────────────────────────────────────────────

test('the snapshot is unbound and one live period spans in_corso to the end', function (): void {
    hraFlowFake();
    [, $project, $participant, $token] = hraFlowScenario(1);
    $sessionId = hraFlowStart($token)->assertStatus(201)->json('session_id');

    $row = hraSessionRow($participant->id);
    expect($row->llm_binding_status)->toBe('unbound');
    expect($row->llm_model_key)->toBeNull();
    expect($row->avatar_template_id)->toBe($project->avatar_template_id);
    expect(DB::table('interview_session_live_periods')->where('interview_session_id', $sessionId)->whereNull('ended_at')->count())->toBe(1);

    hraFlowEnd($token, $sessionId)->assertOk();

    $periods = DB::table('interview_session_live_periods')->where('interview_session_id', $sessionId)->get();
    expect($periods)->toHaveCount(1);
    expect($periods[0]->ended_at)->not->toBeNull();
});

test('a review of a finished hr_avatar session has no cost estimate and still responds', function (): void {
    hraFlowFake();
    [$org, , , $token] = hraFlowScenario(1);
    $sessionId = hraFlowStart($token)->assertStatus(201)->json('session_id');
    hraFlowEnd($token, $sessionId)->assertOk();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $admin = User::factory()->create(['organization_id' => $org->id]);
    $admin->assignRole(SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]));

    $review = test()->withToken((string) auth('api')->login($admin))->getJson("/api/interview-sessions/{$sessionId}/review");

    $review->assertOk();
    expect($review->json('data.cost.avatar'))->toBeNull();
});

// ─── Failure matrix ──────────────────────────────────────────────────────────

test('provider failures map onto the existing matrix', function (int $status, array $body, int $http, string $participantStatus, string $sessionStatus): void {
    hraFlowFake(createStatus: $status, createBody: $body);
    [, , $participant, $token] = hraFlowScenario(1);

    hraFlowStart($token)->assertStatus($http);

    expect($participant->fresh()->status)->toBe($participantStatus);
    expect(hraSessionRow($participant->id)->status)->toBe($sessionStatus);
})->with([
    'rate limited' => [429, ['code' => 'rate_limited'], 429, 'in_attesa', 'pending'],
    'contract error' => [422, ['code' => 'validation_failed'], 500, 'in_attesa', 'error'],
    'config not synced' => [409, ['code' => 'config_not_synced'], 500, 'in_attesa', 'error'],
    'upstream failure' => [503, ['code' => 'unavailable'], 502, 'errore', 'error'],
]);
