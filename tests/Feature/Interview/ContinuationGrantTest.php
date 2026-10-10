<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-04.1/API-04.2 (design N6, A9, N12).
 *
 * A boundary `/start` that names the live conversation id gets a continuation (no provider call,
 * no composition, the same ref, a copied snapshot) only when every grant rule holds. Each refusal
 * falls to the ordinary issue path and answers a normal 201 without a `continuation` key.
 */

use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => true, 'interview.tavus.single_session_projects' => []]);
    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);
});

/**
 * A Tavus interview of three competencies whose first competency has started AND ended
 * with `continue`, so the conversation is live and waiting for the boundary `/start`.
 *
 * @return array{org: Organization, project: Project, participant: Participant, codes: list<string>, owner: InterviewSession, ref: string}
 */
function cgScenario(int $competencies = 3): array
{
    $org = casOrg();
    [$project, $comps] = casProject($org, $competencies);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'cg '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    $participant = casParticipant($org, $project, 'in_attesa');

    $started = cgPost($participant, 'start')->assertStatus(201);
    $owner = casInTenant($org, fn () => InterviewSession::findOrFail($started->json('session_id')));
    cgPost($participant, 'end', ['session_id' => $owner->id, 'ended_reason' => 'completed'])->assertOk();

    return [
        'org' => $org, 'project' => $project, 'participant' => $participant,
        'codes' => array_map(fn ($c): string => $c->code, $comps),
        'owner' => $owner->fresh(), 'ref' => (string) $started->json('conversation_id'),
    ];
}

/** @param array<string, mixed> $body */
function cgPost(Participant $participant, string $action, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson("/api/candidate/interview/{$action}", $body);
}

/** Run the job inline: `dispatchSync` goes through the faked queue and would be swallowed. */
function cgRunRelease(ReleaseEndedProviderSessionJob $job): void
{
    app()->call([$job, 'handle']);
}

/** @return int The number of conversations created at the provider so far. */
function cgCreates(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/v2/conversations'))
        ->count();
}

/** @return list<string> */
function cgTeardowns(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $path): bool => str_ends_with($path, '/end'))
        ->values()->all();
}

test('a boundary /start naming the live conversation gets a continuation with no provider call', function (): void {
    $s = cgScenario();
    // A fresh client: any create or end the boundary /start made would be recorded on it.
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response([], 500)]);

    $response = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201);

    Http::assertNothingSent();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v2/conversations'));
    $response->assertJsonPath('continuation', ['conversation_id' => $s['ref'], 'competency_code' => $s['codes'][1]])
        ->assertJsonPath('provider_token', null)
        ->assertJsonPath('conversation_url', null)
        ->assertJsonPath('question_context.competency_code', $s['codes'][1])
        ->assertJsonPath('question_context.competency_ordinal', 2)
        ->assertJsonPath('question_context.total_competencies', 3)
        ->assertJsonMissingPath('conversation_ttl_seconds');
    expect(array_keys($response->json('continuation')))->toBe(['conversation_id', 'competency_code']);
});

test('the continuation row shares the ref, is in progress, opens its period and takes the plan entry', function (): void {
    $s = cgScenario();
    $plan = $s['owner']->conversation_plan['competencies'][1];

    $id = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201)->json('session_id');

    $row = casInTenant($s['org'], fn () => InterviewSession::findOrFail($id));
    expect($row->provider_session_ref)->toBe($s['ref'])
        ->and($row->status)->toBe('in_corso')
        ->and($row->competency_code)->toBe($s['codes'][1])
        ->and($row->primary_questions)->toBe($plan['primary_questions'])
        ->and($row->follow_up_budget)->toBe($plan['follow_up_budget'])
        ->and($row->conversation_plan)->toBeNull();

    $open = InterviewSessionLivePeriod::withoutGlobalScopes()->where('interview_session_id', $id)->whereNull('ended_at')->sole();
    expect($open->provider_session_ref)->toBe($s['ref']);
});

test('the continuation row copies all five snapshot columns of the owning row', function (): void {
    $s = cgScenario();
    $owner = $s['owner'];
    expect($owner->llm_binding_status)->not->toBeNull()
        ->and($owner->system_prompt_chars)->not->toBeNull()
        ->and($owner->conversation_prompt_version)->not->toBeNull();

    $id = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201)->json('session_id');

    $row = casInTenant($s['org'], fn () => InterviewSession::findOrFail($id));
    expect($row->avatar_template_id)->toBe($owner->avatar_template_id)
        ->and($row->llm_model_key)->toBe($owner->llm_model_key)
        ->and($row->llm_binding_status)->toBe($owner->llm_binding_status)
        ->and($row->system_prompt_chars)->toBe($owner->system_prompt_chars)
        ->and($row->conversation_prompt_version)->toBe($owner->conversation_prompt_version);
});

test('a continuation composes nothing: a competency that could not be composed is still granted', function (): void {
    $s = cgScenario();
    $competencyId = DB::table('framework_competencies')->where('code', $s['codes'][1])->value('id');
    BarsIndicator::query()->where('competency_id', $competencyId)->delete();

    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])
        ->assertStatus(201)
        ->assertJsonPath('continuation.competency_code', $s['codes'][1]);
});

test('every refusal falls to the ordinary issue path with the usual response shape', function (string $case): void {
    $s = cgScenario();
    $id = $s['ref'];

    switch ($case) {
        case 'absent id':
            $id = null;
            break;
        case 'another participant':
            $other = casParticipant($s['org'], $s['project'], 'in_attesa');
            $otherStart = cgPost($other, 'start')->assertStatus(201);
            // Ended and waiting for its boundary /start, so only ownership stands in the way.
            cgPost($other, 'end', ['session_id' => $otherStart->json('session_id'), 'ended_reason' => 'completed'])->assertOk();
            $id = (string) $otherStart->json('conversation_id');
            break;
        case 'another organization':
            $foreign = cgScenario();
            $id = $foreign['ref'];
            break;
        case 'nulled ref':
            casInTenant($s['org'], fn () => InterviewSession::whereKey($s['owner']->id)->update(['provider_session_ref' => null]));
            break;
        case 'code not in plan':
            $plan = $s['owner']->conversation_plan;
            $plan['competencies'] = array_slice($plan['competencies'], 0, 1);
            casInTenant($s['org'], fn () => InterviewSession::whereKey($s['owner']->id)->update(['conversation_plan' => json_encode($plan)]));
            break;
        case 'pending row':
        case 're-offer':
        case 'evaluation-retry reset':
            casInTenant($s['org'], fn () => InterviewSession::factory()->create([
                'participant_id' => $s['participant']->id,
                'project_id' => $s['project']->id,
                'framework_version_id' => $s['project']->framework_version_id,
                'organization_id' => $s['org']->id,
                'provider' => 'tavus',
                'status' => $case === 're-offer' ? 'error' : 'pending',
                'error_count' => $case === 're-offer' ? 1 : 0,
                'provider_session_ref' => null,
                'competency_code' => $s['codes'][1],
                'question_index' => 1,
            ]));
            break;
        case 'gate closed':
            config(['interview.tavus.single_session' => false]);
            break;
        case 'ref released':
            cgRunRelease(new ReleaseEndedProviderSessionJob($s['owner']->id, $s['org']->id, 'tavus', $s['ref'], null));
            break;
    }

    $creates = cgCreates();
    $response = cgPost($s['participant'], 'start', $id === null ? [] : ['live_conversation_id' => $id])->assertStatus(201);

    $response->assertJsonMissingPath('continuation');
    expect($response->json('conversation_url'))->toBe('https://tavus.io/conv')
        ->and(cgCreates())->toBe($creates + 1)
        ->and(array_keys($response->json()))->toContain('session_id', 'provider', 'audio_only', 'provider_token', 'conversation_url', 'question_context');
})->with([
    'absent id', 'another participant', 'another organization', 'nulled ref', 'code not in plan',
    'pending row', 're-offer', 'evaluation-retry reset', 'gate closed', 'ref released',
]);

test('a transaction failure answers the existing 500, writes no row and ends nothing', function (): void {
    $s = cgScenario();
    $creates = cgCreates();
    $teardowns = count(cgTeardowns());
    Event::listen('eloquent.creating: '.InterviewSessionLivePeriod::class, function (): void {
        throw new RuntimeException('boom');
    });

    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])
        ->assertStatus(500)->assertExactJson(['error' => 'db_error']);

    expect(cgCreates())->toBe($creates)
        ->and(count(cgTeardowns()))->toBe($teardowns)
        ->and(casInTenant($s['org'], fn () => InterviewSession::where('competency_code', $s['codes'][1])->exists()))->toBeFalse();
});

test('the release deferred at the previous /end finds the live continuation and sends nothing', function (): void {
    $s = cgScenario();
    $teardowns = count(cgTeardowns());
    Queue::assertPushed(ReleaseEndedProviderSessionJob::class);
    /** @var ReleaseEndedProviderSessionJob $job */
    $job = Queue::pushed(ReleaseEndedProviderSessionJob::class)->first();
    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201);

    cgRunRelease($job);

    expect(count(cgTeardowns()))->toBe($teardowns)
        ->and(InterviewSession::withoutGlobalScopes()->whereNotNull('provider_released_at')->exists())->toBeFalse();
});

test('/end of a continuation row defers the release while the owner plan covers a later competency', function (): void {
    $s = cgScenario();
    $teardowns = count(cgTeardowns());
    $id = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201)->json('session_id');

    cgPost($s['participant'], 'end', ['session_id' => $id, 'ended_reason' => 'completed'])
        ->assertOk()->assertJsonPath('next_action', 'continue');

    expect(count(cgTeardowns()))->toBe($teardowns)
        ->and(Queue::pushed(ReleaseEndedProviderSessionJob::class)->count())->toBe(2);
    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])
        ->assertStatus(201)->assertJsonPath('continuation.competency_code', $s['codes'][2]);
});

test('a malformed live_conversation_id is never a 422: the start falls to the ordinary issue path', function (mixed $id): void {
    $s = cgScenario();
    $creates = cgCreates();

    $response = cgPost($s['participant'], 'start', ['live_conversation_id' => $id])->assertStatus(201);

    $response->assertJsonMissingPath('continuation');
    expect($response->json('conversation_url'))->toBe('https://tavus.io/conv')
        ->and(cgCreates())->toBe($creates + 1);
})->with([
    'array' => [['a']],
    'integer' => [42],
    'boolean' => [true],
    'over 128 chars' => [str_repeat('x', 129)],
]);

test('a continuation 201 carries the prompt version of the conversation it joins', function (): void {
    $s = cgScenario();
    $version = $s['owner']->conversation_prompt_version;
    expect($version)->toBeString()->not->toBe('');

    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])
        ->assertStatus(201)
        ->assertJsonPath('question_context.prompt_version', $version);
});

/** Age the live conversation: move every period of its ref `$seconds` into the past. */
function cgAge(array $s, int $seconds): void
{
    casInTenant($s['org'], fn () => InterviewSessionLivePeriod::query()
        ->where('provider_session_ref', $s['ref'])
        ->update(['started_at' => now()->subSeconds($seconds)]));
}

test('a conversation near its ceiling is refused: the ordinary issue runs and the old ref is released only through a deferred job', function (): void {
    $s = cgScenario();
    cgAge($s, 3200); // default ceiling 3600, headroom 480
    $creates = cgCreates();
    $teardownsBefore = count(cgTeardowns());

    $response = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201);

    $response->assertJsonMissingPath('continuation');
    expect(cgCreates())->toBe($creates + 1)
        ->and(count(cgTeardowns()))->toBe($teardownsBefore); // nothing ended inline
    Queue::assertPushed(
        ReleaseEndedProviderSessionJob::class,
        fn (ReleaseEndedProviderSessionJob $job): bool => $job->providerSessionRef === $s['ref'] && $job->sessionId === $s['owner']->id
            && $job->delay === (int) config('interview.provider_release_delay_seconds'),
    );

    // The deferred job ends the OLD conversation only; the freshly issued one stays releasable by nobody.
    $newRef = (string) casInTenant($s['org'], fn () => InterviewSession::findOrFail($response->json('session_id'))->provider_session_ref);
    expect($newRef)->not->toBe($s['ref']);
    cgRunRelease(Queue::pushed(ReleaseEndedProviderSessionJob::class)->last());
    expect(cgTeardowns())->toContain("/v2/conversations/{$s['ref']}/end")
        ->and(cgTeardowns())->not->toContain("/v2/conversations/{$newRef}/end");
    casInTenant($s['org'], function () use ($s, $newRef): void {
        expect(InterviewSession::where('provider_session_ref', $s['ref'])->whereNull('provider_released_at')->count())->toBe(0)
            ->and(InterviewSession::where('provider_session_ref', $newRef)->whereNotNull('provider_released_at')->count())->toBe(0);
    });
});

test('a conversation inside its ceiling still gets a continuation and dispatches no release', function (): void {
    $s = cgScenario();
    cgAge($s, 2000);
    Queue::fake(); // forget the /end release deferred by the scenario

    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201)->assertJsonPath('continuation.conversation_id', $s['ref']);

    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});

test('a template cap lowers the ceiling the grant honours', function (): void {
    $s = cgScenario();
    casInTenant($s['org'], fn () => AvatarTemplate::whereKey($s['project']->avatar_template_id)->update(['config' => json_encode(['maxCallDurationSec' => 900])]));
    cgAge($s, 500); // 500 + 480 >= 900, yet far inside the platform 3600

    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201)->assertJsonMissingPath('continuation');
});

test('a mid-competency resume of an expired conversation defers the release of the old ref', function (): void {
    $s = cgScenario();
    // The second competency is live on the same conversation, then the browser reloads near the ceiling.
    $granted = cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201);
    cgAge($s, 3200);
    Queue::fake();
    $teardownsBefore = count(cgTeardowns());

    $resumed = cgPost($s['participant'], 'start')->assertStatus(201);

    expect($resumed->json('session_id'))->toBe($granted->json('session_id'))
        ->and(count(cgTeardowns()))->toBe($teardownsBefore);
    Queue::assertPushed(
        ReleaseEndedProviderSessionJob::class,
        fn (ReleaseEndedProviderSessionJob $job): bool => $job->providerSessionRef === $s['ref']
            && $job->delay === (int) config('interview.provider_release_delay_seconds'),
    );
    expect(casInTenant($s['org'], fn () => InterviewSession::findOrFail($granted->json('session_id'))->provider_session_ref))->not->toBe($s['ref']);
});

test('a resume far from the ceiling still tears the old ref down inline', function (): void {
    $s = cgScenario();
    cgPost($s['participant'], 'start', ['live_conversation_id' => $s['ref']])->assertStatus(201);
    Queue::fake();
    $teardownsBefore = count(cgTeardowns());

    cgPost($s['participant'], 'start')->assertStatus(201);

    expect(count(cgTeardowns()))->toBe($teardownsBefore + 1);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});

test('a fresh multi-competency response carries the ceiling the client may rely on', function (): void {
    $org = casOrg();
    [$project] = casProject($org, 3);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'ttl '.uniqid(), 'provider' => 'tavus', 'config' => ['maxCallDurationSec' => 900],
    ])->id])->save();
    $participant = casParticipant($org, $project, 'in_attesa');

    $fresh = cgPost($participant, 'start')->assertStatus(201)->assertJsonPath('conversation_ttl_seconds', 900);

    cgPost($participant, 'end', ['session_id' => $fresh->json('session_id'), 'ended_reason' => 'completed'])->assertOk();
    cgPost($participant, 'start', ['live_conversation_id' => $fresh->json('conversation_id')])->assertStatus(201)
        ->assertJsonMissingPath('conversation_ttl_seconds');
});
