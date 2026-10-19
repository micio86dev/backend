<?php

declare(strict_types=1);

use App\Enums\ProviderFailureClass;
use App\Exceptions\ProviderException;
use App\Models\Participant;
use App\Services\Provider\HrAvatarProvider;
use App\Services\Provider\ProviderToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const HRA_SECRET = 'hra_sk_live_SECRETVALUE';

beforeEach(function (): void {
    config(['hr_avatar' => [
        'base_url' => 'https://hra.example.test',
        'secret_key' => HRA_SECRET,
        'publishable_key' => 'hra_pk_live_PUBLISHABLE',
    ]]);
    config(['logging.default' => 'null']);
});

/** @return list<string> every log record, JSON-encoded, captured from now on */
function hraLogs(): ArrayObject
{
    $records = new ArrayObject;
    Log::listen(function ($event) use ($records): void {
        $records->append((string) json_encode(['message' => $event->message, 'context' => $event->context]));
    });

    return $records;
}

function hraFakeConversation(int $status = 201, mixed $body = null): void
{
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(
            $body ?? ['id' => 'conv_01ABC', 'client_secret' => 'CS_VALUE'],
            $status,
        ),
    ]);
    Http::preventStrayRequests();
}

function hraIssue(?object $session = null, ?object $ctx = null): ProviderToken
{
    return app(HrAvatarProvider::class)->issue($session ?? hraIssueSession(), $ctx ?? hraQuestionContext());
}

function hraThrown(callable $call): ProviderException
{
    try {
        $call();
    } catch (ProviderException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a ProviderException, none was thrown.');
}

// ─── issue(): request ────────────────────────────────────────────────────────

test('issue sends exactly one conversation create with a bearer secret key and the seven contract members', function (): void {
    hraFakeConversation();

    hraIssue();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $request->method() === 'POST'
            && $request->url() === 'https://hra.example.test/v1/conversations'
            && $request->hasHeader('Authorization', 'Bearer '.HRA_SECRET)
            && array_keys($body) === ['avatar_config_id', 'system_prompt', 'opening_text', 'language', 'max_duration_seconds', 'external_ref', 'metadata']
            && $body['avatar_config_id'] === 'avc_01HXYZ'
            && $body['system_prompt'] === 'You are the interviewer. Ask about teamwork.'
            && $body['opening_text'] === 'Hello, shall we begin?'
            && $body['language'] === 'it'
            && $body['max_duration_seconds'] === 1200
            && $body['external_ref'] === 'session_42';
    });
});

test('metadata is serialized as an empty object, never an empty array', function (): void {
    hraFakeConversation();

    hraIssue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"metadata":{}'));
});

test('the derived ceiling is clamped into 30..3600 seconds', function (int $derived, int $sent): void {
    hraFakeConversation();

    hraIssue(hraIssueSession(['config' => ['maxSessionDurationSec' => $derived]]));

    Http::assertSent(fn (Request $request): bool => $request->data()['max_duration_seconds'] === $sent);
})->with([
    'below the floor' => [10, 30],
    'at the floor' => [30, 30],
    'inside the range' => [900, 900],
    'at the ceiling' => [3600, 3600],
    'above the ceiling' => [99999, 3600],
]);

test('external_ref and every other member carry no candidate data and no persona display name', function (): void {
    hraFakeConversation();
    $session = hraIssueSession(['persona' => ['display_name' => 'Giulia']]);
    $candidate = Participant::query()->where('organization_id', $session->organization_id)->findOrFail($session->participant_id);

    hraIssue($session);

    Http::assertSent(function (Request $request) use ($candidate): bool {
        $everything = $request->body().$request->url().json_encode($request->headers());

        return $request->data()['external_ref'] === 'session_42'
            && ! str_contains($everything, 'Giulia')
            && ! str_contains($everything, $candidate->display_name)
            && ! str_contains($everything, $candidate->email)
            && ! str_contains($everything, $candidate->candidate_ref);
    });
});

test('a prompt and an opening text exactly at the limits are sent unchanged', function (): void {
    hraFakeConversation();
    $prompt = str_repeat('p', 32000);
    $opening = str_repeat('o', 4000);

    hraIssue(null, hraQuestionContext(['systemPrompt' => $prompt, 'openingText' => $opening]));

    Http::assertSent(fn (Request $request): bool => $request->data()['system_prompt'] === $prompt
        && $request->data()['opening_text'] === $opening);
});

// ─── issue(): response ───────────────────────────────────────────────────────

test('issue maps the conversation id and client secret onto the token', function (): void {
    hraFakeConversation();

    $token = hraIssue();

    expect($token->provider)->toBe('hr_avatar');
    expect($token->token)->toBe('CS_VALUE');
    expect($token->provider_session_ref)->toBe('conv_01ABC');
    expect($token->conversation_url)->toBeNull();
    expect($token->provider_context_ref)->toBeNull();
});

test('a malformed success is an upstream failure and returns no token', function (int $status, mixed $body): void {
    hraFakeConversation($status, $body);

    $e = hraThrown(fn () => hraIssue());

    expect($e->failureClass())->toBe(ProviderFailureClass::Upstream);
})->with([
    'client_secret missing' => [201, ['id' => 'conv_01ABC']],
    'client_secret empty' => [201, ['id' => 'conv_01ABC', 'client_secret' => '']],
    'id without the conv_ prefix' => [201, ['id' => 'xyz_01ABC', 'client_secret' => 'CS']],
    'id with a path character' => [201, ['id' => 'conv_01/../x', 'client_secret' => 'CS']],
    'status 200 instead of 201' => [200, ['id' => 'conv_01ABC', 'client_secret' => 'CS']],
    'not JSON' => [201, '<html>proxy error</html>'],
]);

test('a created conversation whose secret is unusable is ended before the failure is thrown', function (mixed $secret): void {
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(['id' => 'conv_01ABC'] + ($secret === false ? [] : ['client_secret' => $secret]), 201),
        'hra.example.test/v1/conversations/conv_01ABC/end' => Http::response('', 204),
    ]);
    Http::preventStrayRequests();

    $e = hraThrown(fn () => hraIssue());

    expect($e->failureClass())->toBe(ProviderFailureClass::Upstream);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://hra.example.test/v1/conversations/conv_01ABC/end'
        && ($request->header('Authorization')[0] ?? null) === 'Bearer '.HRA_SECRET);
})->with(['missing' => [false], 'empty' => ['']]);

test('a failing end call during that cleanup is swallowed, logged by status class only, and the original failure is thrown', function (): void {
    $logs = hraLogs();
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(['id' => 'conv_01ABC'], 201),
        'hra.example.test/v1/conversations/conv_01ABC/end' => Http::response(['detail' => 'upstream-detail'], 503),
    ]);
    Http::preventStrayRequests();

    $e = hraThrown(fn () => hraIssue());

    expect($e->getMessage())->toBe('hr_avatar: issue failed (malformed response)');
    expect(implode('', $logs->getArrayCopy()))->toContain('5xx')->not->toContain('upstream-detail');
});

test('a malformed response with no usable conversation id ends nothing', function (): void {
    hraFakeConversation(201, ['id' => 'xyz_1', 'client_secret' => 'CS']);

    hraThrown(fn () => hraIssue());

    Http::assertSentCount(1);
});

test('failures are classified onto the existing provider failure classes', function (int $status, ProviderFailureClass $class): void {
    hraFakeConversation($status, ['code' => 'whatever', 'title' => 'x']);

    $e = hraThrown(fn () => hraIssue());

    expect($e->failureClass())->toBe($class);
    Http::assertSentCount(1);
})->with([
    '429' => [429, ProviderFailureClass::Throttle],
    '400' => [400, ProviderFailureClass::ClientError],
    '401' => [401, ProviderFailureClass::ClientError],
    '403' => [403, ProviderFailureClass::ClientError],
    '404' => [404, ProviderFailureClass::ClientError],
    '409' => [409, ProviderFailureClass::ClientError],
    '422' => [422, ProviderFailureClass::ClientError],
    '500' => [500, ProviderFailureClass::Upstream],
    '503' => [503, ProviderFailureClass::Upstream],
]);

test('a timeout or connection failure is upstream', function (): void {
    Http::fake(['hra.example.test/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    Http::preventStrayRequests();

    expect(hraThrown(fn () => hraIssue())->failureClass())->toBe(ProviderFailureClass::Upstream);
});

test('operator faults are client errors that name their code and are never retried', function (int $status, string $code): void {
    hraFakeConversation($status, ['type' => 'about:blank', 'title' => 'x', 'code' => $code]);
    $logs = hraLogs();

    $e = hraThrown(fn () => hraIssue());

    expect($e->failureClass())->toBe(ProviderFailureClass::ClientError);
    expect($e->getMessage())->toContain($code)->toContain('4xx')->not->toContain(HRA_SECRET);
    expect(implode("\n", $logs->getArrayCopy()))->toContain($code)->not->toContain(HRA_SECRET);
    Http::assertSentCount(1);
})->with([
    'key without conversations:write' => [403, 'insufficient_scope'],
    'pinned config still pending' => [409, 'config_not_synced'],
]);

// ─── issue(): no request ─────────────────────────────────────────────────────

test('a request that cannot be well formed fails as a client error before anything is sent', function (callable $arrange): void {
    Http::fake();
    Http::preventStrayRequests();
    [$session, $ctx] = $arrange();

    $e = hraThrown(fn () => hraIssue($session, $ctx));

    expect($e->failureClass())->toBe(ProviderFailureClass::ClientError);
    Http::assertNothingSent();
})->with([
    'integration unavailable' => [function (): array {
        $pair = [hraIssueSession(), hraQuestionContext()];
        config(['hr_avatar.secret_key' => null]);

        return $pair;
    }],
    'no template resolves' => [function (): array {
        $session = hraIssueSession();
        $session->project_id = null;

        return [$session, hraQuestionContext()];
    }],
    'template without a config id' => [fn (): array => [hraIssueSession(['hr_avatar_config_id' => null]), hraQuestionContext()]],
    'no composed system prompt' => [fn (): array => [hraIssueSession(), hraQuestionContext(['systemPrompt' => null])]],
    'no composed opening text' => [fn (): array => [hraIssueSession(), hraQuestionContext(['openingText' => null])]],
    'system prompt over 32000 characters' => [fn (): array => [hraIssueSession(), hraQuestionContext(['systemPrompt' => str_repeat('p', 32001)])]],
    'opening text over 4000 characters' => [fn (): array => [hraIssueSession(), hraQuestionContext(['openingText' => str_repeat('o', 4001)])]],
    'no language' => [fn (): array => [hraIssueSession(), hraQuestionContext(['language' => null])]],
    'language not two letters' => [fn (): array => [hraIssueSession(), hraQuestionContext(['language' => 'ita'])]],
    'language not lowercase' => [fn (): array => [hraIssueSession(), hraQuestionContext(['language' => 'IT'])]],
]);

// ─── issue(): diagnostics ────────────────────────────────────────────────────

test('diagnostics carry the status class and a safe code but never the body, the prompt or the secret', function (): void {
    $prompt = 'CONFIDENTIAL PROMPT BODY '.HRA_SECRET;
    hraFakeConversation(409, [
        'type' => 'about:blank', 'title' => 'Conflict', 'code' => 'config_not_synced',
        'detail' => $prompt.' echoed by the server',
    ]);
    $logs = hraLogs();

    $e = hraThrown(fn () => hraIssue(null, hraQuestionContext(['systemPrompt' => $prompt])));

    $everything = $e->getMessage().implode("\n", $logs->getArrayCopy());
    expect($e->getMessage())->toBe('hr_avatar: issue failed (status 4xx, code config_not_synced)');
    expect($everything)->toContain('config_not_synced')
        ->not->toContain('CONFIDENTIAL')
        ->not->toContain(HRA_SECRET)
        ->not->toContain('echoed by the server');
});

test('a hostile code is dropped from the message and the log', function (string $code): void {
    hraFakeConversation(409, ['code' => $code]);
    $logs = hraLogs();

    $e = hraThrown(fn () => hraIssue());

    expect($e->getMessage())->toBe('hr_avatar: issue failed (status 4xx)');
    expect(implode("\n", $logs->getArrayCopy()))->not->toContain($code);
})->with([
    'spaces' => ['bad code here'],
    'uppercase' => ['BAD_CODE'],
    'eighty characters' => [str_repeat('a', 80)],
    'digits' => ['code_42'],
    'a path' => ['../etc/passwd'],
]);

// ─── teardown() ──────────────────────────────────────────────────────────────

test('teardown posts one end request for the conversation and reports success', function (): void {
    Http::fake(['hra.example.test/v1/conversations/conv_01ABC/end' => Http::response(['status' => 'ended'], 200)]);
    Http::preventStrayRequests();

    $released = app(HrAvatarProvider::class)->teardown(ProviderToken::fromRef('hr_avatar', 'conv_01ABC'));

    expect($released)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://hra.example.test/v1/conversations/conv_01ABC/end'
        && $request->hasHeader('Authorization', 'Bearer '.HRA_SECRET));
});

test('teardown never throws, reports false and logs without the secret on any failure', function (callable $fake): void {
    Http::fake(['hra.example.test/*' => $fake]);
    Http::preventStrayRequests();
    $logs = hraLogs();

    $released = app(HrAvatarProvider::class)->teardown(ProviderToken::fromRef('hr_avatar', 'conv_01ABC'));

    expect($released)->toBeFalse();
    expect($logs)->toHaveCount(1);
    expect(implode("\n", $logs->getArrayCopy()))->not->toContain(HRA_SECRET)->toContain('teardown');
})->with([
    '404' => [fn () => Http::response(['code' => 'conversation_not_found', 'detail' => HRA_SECRET], 404)],
    '503' => [fn () => Http::response(['detail' => HRA_SECRET], 503)],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: timed out '.HRA_SECRET)],
]);

test('teardown sends nothing for a reference that is not a conversation id', function (?string $ref): void {
    Http::fake();
    Http::preventStrayRequests();

    $released = app(HrAvatarProvider::class)->teardown(new ProviderToken(provider: 'hr_avatar', provider_session_ref: $ref));

    expect($released)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
    'no prefix' => ['abc'],
    'path traversal' => ['conv_x/../y'],
    'query injection' => ['conv_x?force=1'],
]);

test('teardown with the integration unavailable sends nothing, reports false and logs', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    config(['hr_avatar.base_url' => null]);
    $logs = hraLogs();

    $released = app(HrAvatarProvider::class)->teardown(ProviderToken::fromRef('hr_avatar', 'conv_01ABC'));

    expect($released)->toBeFalse();
    expect($logs)->toHaveCount(1);
    Http::assertNothingSent();
});

// ─── reconcileTranscript() ───────────────────────────────────────────────────

test('reconcileTranscript returns an empty list and never calls HR-Avatar', function (): void {
    Http::fake();
    Http::preventStrayRequests();

    expect(app(HrAvatarProvider::class)->reconcileTranscript(hraIssueSession()))->toBe([]);

    Http::assertNothingSent();
});

test('issue and teardown never list conversations or end another one', function (): void {
    Http::fake([
        'hra.example.test/v1/conversations' => Http::response(['id' => 'conv_01ABC', 'client_secret' => 'CS'], 201),
        'hra.example.test/v1/conversations/*/end' => Http::response([], 200),
    ]);
    Http::preventStrayRequests();

    $token = hraIssue();
    app(HrAvatarProvider::class)->teardown($token);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/conversations/conv_01ABC/end'));
});
