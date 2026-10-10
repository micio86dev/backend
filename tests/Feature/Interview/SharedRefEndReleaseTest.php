<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-07.1 (design N12).
 *
 * A Tavus row whose stored plan covers a LATER competency shares its conversation with the next
 * row, so `/end` with `next_action = 'continue'` must not end that conversation: it defers the
 * release through the existing job. Every other end releases exactly as before.
 */

use App\Jobs\ReleaseEndedProviderSessionJob;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Conversation\PromptSetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Start a Tavus interview of `$competencies` competencies.
 *
 * @return array{0: Organization, 1: array<string, string>, 2: InterviewSession}
 */
function sreScenario(int $competencies, bool $gateOpen, ?int $pauseEvery = null): array
{
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => $gateOpen, 'interview.tavus.single_session_projects' => []]);

    $org = casOrg();
    [$project] = casProject($org, $competencies);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'sre '.uniqid(), 'provider' => 'tavus', 'config' => [],
    ])->id])->save();
    if ($pauseEvery !== null) {
        casInTenant($org, fn () => Project::whereKey($project->id)->update(['pause_every_n_competencies' => $pauseEvery]));
    }
    $participant = casParticipant($org, $project, 'in_attesa');
    $headers = ['Authorization' => 'Bearer '.casBearer($participant)];

    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
    ]);
    test()->withHeaders($headers)->postJson('/api/candidate/interview/start')->assertStatus(201);
    $session = casInTenant($org, fn () => InterviewSession::where('participant_id', $participant->id)->firstOrFail());

    return [$org, $headers, $session];
}

function sreEnd(array $headers, InterviewSession $session, string $reason = 'completed'): string
{
    return (string) test()->withHeaders($headers)
        ->postJson('/api/candidate/interview/end', ['session_id' => $session->id, 'ended_reason' => $reason])
        ->assertOk()->json('next_action');
}

/** @return list<string> every Tavus end call recorded. */
function sreTeardowns(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))
        ->filter(fn (string $call): bool => str_ends_with($call, '/end'))
        ->values()->all();
}

test('/end continue on a shared-ref row defers the release and never ends the conversation', function (): void {
    [$org, $headers, $session] = sreScenario(3, gateOpen: true);
    expect($session->conversation_plan)->not->toBeNull();

    expect(sreEnd($headers, $session))->toBe('continue');

    expect(sreTeardowns())->toBe([]);
    Queue::assertPushed(ReleaseEndedProviderSessionJob::class, 1);
    Queue::assertPushed(ReleaseEndedProviderSessionJob::class, fn (ReleaseEndedProviderSessionJob $job): bool => $job->delay === config('interview.provider_release_delay_seconds')
        && $job->sessionId === $session->id
        && $job->organizationId === $org->id
        && $job->provider === 'tavus'
        && $job->providerSessionRef === $session->provider_session_ref
        && $job->afterCommit === true);
});

test('/end releases at once when no plan shares the ref (gate closed)', function (): void {
    [, $headers, $session] = sreScenario(3, gateOpen: false);
    expect($session->conversation_plan)->toBeNull();

    expect(sreEnd($headers, $session))->toBe('continue');

    expect(sreTeardowns())->toBe(['POST /v2/conversations/'.$session->provider_session_ref.'/end']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});

test('/end releases at once when the plan covers nothing after this competency', function (): void {
    [$org, $headers, $session] = sreScenario(3, gateOpen: true);
    $plan = $session->conversation_plan;
    $plan['competencies'] = [$plan['competencies'][0]];
    casInTenant($org, fn () => $session->forceFill(['conversation_plan' => $plan])->save());

    expect(sreEnd($headers, $session))->toBe('continue');

    expect(sreTeardowns())->toBe(['POST /v2/conversations/'.$session->provider_session_ref.'/end']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});

test('/end releases at once on a pause', function (): void {
    [, $headers, $session] = sreScenario(3, gateOpen: true, pauseEvery: 1);

    expect(sreEnd($headers, $session))->toBe('pause');

    expect(sreTeardowns())->toBe(['POST /v2/conversations/'.$session->provider_session_ref.'/end']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});

test('/end releases at once on done', function (): void {
    [, $headers, $session] = sreScenario(1, gateOpen: true);

    expect(sreEnd($headers, $session))->toBe('done');

    expect(sreTeardowns())->toBe(['POST /v2/conversations/'.$session->provider_session_ref.'/end']);
    Queue::assertNotPushed(ReleaseEndedProviderSessionJob::class);
});
