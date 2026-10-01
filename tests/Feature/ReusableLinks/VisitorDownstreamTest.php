<?php

declare(strict_types=1);

/**
 * A visitor is an ordinary participant downstream (reusable-interview-links, B3b.7).
 *
 * A reusable link produces a participant exactly like a backoffice-created one,
 * apart from three markers (the origin column, the `rlv_` reference prefix and a
 * placeholder address). Everything that happens to a participant afterwards must
 * therefore happen to a visitor, and these tests observe it from the outside:
 *
 *   - the creation `progress` webhook fires once per visitor, never for a refusal;
 *   - the interview runs on the REAL avatar provider, never the mock one, and
 *     scoring is dispatched when it ends;
 *   - the organization's LIVE API key lists it on `/v1/interviews` (told apart by
 *     `rlv_`), its TEST key does not;
 *   - the admin participant list and the dashboard count it like any other row;
 *   - nothing resolves a visitor from a person's name or email, because it holds
 *     neither (the documented anonymity limit, see the GDPR sign-off in the spec).
 *
 * The retention purge guard lives with the purge tests (`DataRetentionPurgeTest`).
 *
 * REQ: Visitors Are Ordinary Participants Downstream,
 *      Anonymous Visitors Cannot Be Matched To A Person
 *      (sdd/reusable-interview-links/spec/reusable-interview-links-redemption)
 */

use App\Actions\Interview\SettleParticipantCompletion;
use App\Enums\ApiKeyMode;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\PublicApi\RunMockInterviewJob;
use App\Jobs\ScoreEvaluationJob;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\Role;
use App\Models\Utterance;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    // `Queue::fake()` is what keeps a webhook delivery from making a real HTTP
    // call from inside the listener, and scoring from running a real model. Any
    // request that is not explicitly faked fails loudly instead of leaving.
    Queue::fake();
    Http::preventStrayRequests();
    config([
        'reusable_links.redeem.per_ip_per_minute' => 100000,
        'reusable_links.redeem.per_link_per_hour' => 100000,
    ]);
});

/**
 * A redeemable link on a project whose progress webhook is subscribed.
 *
 * @param  array<string, mixed>  $projectAttributes
 * @return array{org: Organization, project: Project, link: ReusableInterviewLink, token: string}
 */
function visitorDownstreamWorld(array $projectAttributes = []): array
{
    return Fx::redeemable(projectAttributes: array_merge([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_visitor_downstream_test',
        'webhook_events' => ['progress', 'evaluation'],
    ], $projectAttributes));
}

/**
 * Give a world's project what `/start` composes an interview from: an avatar
 * template on the real provider, a role and a rubric for the selected
 * competency. (Interviewability, which a redemption needs, is only the question.)
 *
 * @param  array{project: Project}  $world
 */
function visitorDownstreamMakeStartable(array $world): void
{
    $project = $world['project'];

    TenantContextScope::runFor($project->organization_id, function () use ($project): void {
        $template = AvatarTemplate::create(['name' => 'Visitor fixture', 'provider' => 'heygen', 'config' => []]);
        $project->forceFill(['avatar_template_id' => $template->id])->save();

        $role = Role::factory()->create(['code' => $project->role_code]);

        foreach ($project->competencies as $competency) {
            $indicator = new BarsIndicator;
            $indicator->forceFill([
                'role_id' => $role->id,
                'competency_id' => $competency->id,
                'text' => ['en' => 'visitor fixture indicator'],
                'anchor_5' => ['en' => 'Excellent'],
                'anchor_3' => ['en' => 'Adequate'],
                'anchor_1' => ['en' => 'Insufficient'],
                'position' => 0,
            ]);
            $indicator->save();
        }
    });
}

/**
 * The real avatar provider's happy-path responses.
 *
 * @return array<string, mixed>
 */
function visitorDownstreamHeygenResponses(): array
{
    return [
        '*liveavatar*/contexts*' => Http::response(['data' => ['context_id' => 'ctx-visitor-001']], 200),
        '*liveavatar*/sessions/token*' => Http::response([
            'data' => [
                'session_id' => 'heygen-session-'.uniqid(),
                'session_token' => 'heygen-token-'.uniqid(),
                'url' => 'https://webrtc.heygen.com/test',
            ],
        ], 200),
        '*liveavatar*/sessions/*' => Http::response([], 200),
    ];
}

/**
 * Redeem a world's link and return the visitor and its candidate token.
 *
 * @param  array{link: ReusableInterviewLink, token: string}  $world
 * @return array{visitor: Participant, jwt: string}
 */
function visitorDownstreamRedeem(array $world): array
{
    $response = test()->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']])->assertOk();
    $visitors = Fx::visitorsOf($world['link']);

    return ['visitor' => end($visitors), 'jwt' => (string) $response->json('access_token')];
}

// ─── The creation webhook ────────────────────────────────────────────────────

test('a redemption fires exactly one creation progress webhook, echoing the rlv_ reference; two visitors, two; a refusal, none', function (): void {
    $world = visitorDownstreamWorld();

    $first = visitorDownstreamRedeem($world)['visitor'];
    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('project_id', $world['project']->id)->get();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->participant_id)->toBe($first->id)
        ->and($first->candidate_ref)->toStartWith('rlv_')
        ->and(json_encode($deliveries[0]->payload))->toContain($first->candidate_ref);
    Queue::assertPushed(DeliverWebhookJob::class, 1);

    $second = visitorDownstreamRedeem($world)['visitor'];
    expect(WebhookDelivery::withoutGlobalScopes()->where('project_id', $world['project']->id)->pluck('participant_id')->all())
        ->toBe([$first->id, $second->id]);

    // Refusals: an unknown token, a disabled link, a closed project.
    $before = WebhookDelivery::withoutGlobalScopes()->count();
    $this->postJson(Fx::REDEEM_URL, ['link_token' => ReusableLinkTokenGenerator::generate()])->assertNotFound();
    DB::table('projects')->where('id', $world['project']->id)->update(['status' => 'inactive']);
    $this->postJson(Fx::REDEEM_URL, ['link_token' => $world['token']])->assertForbidden();

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe($before);
});

test('the creation webhook payload carries no link marker', function (): void {
    $world = visitorDownstreamWorld();

    visitorDownstreamRedeem($world);
    $payload = (string) json_encode(WebhookDelivery::withoutGlobalScopes()->firstOrFail()->payload);

    expect($payload)->not->toContain('reusable')
        ->and($payload)->not->toContain('rlk_')
        ->and($payload)->not->toContain((string) $world['link']->token_prefix);
});

// ─── The interview and scoring ───────────────────────────────────────────────

test('a visitor interview uses the real avatar provider, runs no mock job, and scoring is dispatched when it ends', function (): void {
    $world = visitorDownstreamWorld();
    visitorDownstreamMakeStartable($world);
    ['visitor' => $visitor, 'jwt' => $jwt] = visitorDownstreamRedeem($world);
    Queue::fake([RunMockInterviewJob::class, ScoreEvaluationJob::class, DeliverWebhookJob::class]);
    Http::fake(visitorDownstreamHeygenResponses());

    resetAuthGuardState();
    $started = $this->flushHeaders()->withToken($jwt)->postJson('/api/candidate/interview/start');

    $started->assertCreated();
    $started->assertJsonPath('provider', 'heygen');
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'liveavatar'));
    Queue::assertNotPushed(RunMockInterviewJob::class);

    $session = InterviewSession::withoutGlobalScopes()->where('participant_id', $visitor->id)->firstOrFail();
    expect($session->provider)->toBe('heygen')
        ->and($visitor->fresh()->mode)->toBe(ApiKeyMode::Live)
        ->and($visitor->fresh()->status)->toBe('in_corso');

    // The visitor spoke, then the session ends without every competency being
    // answered: the abandoned-interview path settles it for scoring, exactly as
    // for any participant.
    Utterance::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id' => $visitor->organization_id,
        'speaker' => 'candidate',
        'text' => 'I led the migration',
        'ts' => now(),
    ]);

    app(SettleParticipantCompletion::class)->settleAbandoned($visitor->id, $world['project']->id);

    expect($visitor->fresh()->status)->toBe('in_valutazione');
    Queue::assertPushed(ScoreEvaluationJob::class, function (ScoreEvaluationJob $job) use ($visitor): bool {
        // The id is a private constructor property: read it the way the queue
        // worker would, from the job itself.
        return (new ReflectionProperty($job, 'participantId'))->getValue($job) === $visitor->id;
    });
});

// ─── The public API ──────────────────────────────────────────────────────────

test('the organization live key lists a visitor on /v1/interviews, told apart by rlv_, and its test key does not', function (): void {
    $world = visitorDownstreamWorld();
    ['visitor' => $visitor] = visitorDownstreamRedeem($world);

    $keys = [];
    foreach ([ApiKeyMode::Live, ApiKeyMode::Test] as $mode) {
        $keys[$mode->value] = ApiKeyGenerator::generate($mode);
        ApiClient::factory()->withRawKey($keys[$mode->value])->create([
            'organization_id' => $world['org']->id,
            'is_active' => true,
            'abilities' => ['interviews:read'],
        ]);
    }

    $live = $this->withHeaders(['Authorization' => 'Bearer '.$keys[ApiKeyMode::Live->value]])->getJson('/api/v1/interviews')->assertOk();
    $test = $this->withHeaders(['Authorization' => 'Bearer '.$keys[ApiKeyMode::Test->value]])->getJson('/api/v1/interviews')->assertOk();

    $liveRefs = collect($live->json('data'))->pluck('candidate_ref')->all();
    $liveRow = collect($live->json('data'))->firstWhere('candidate_ref', $visitor->candidate_ref);

    expect($liveRefs)->toBe([$visitor->candidate_ref])
        ->and($visitor->candidate_ref)->toStartWith('rlv_')
        ->and($liveRow['livemode'])->toBeTrue()
        ->and($test->json('data'))->toBe([]);
});

// ─── The admin reads ─────────────────────────────────────────────────────────

test('the admin participant list and the dashboard count visitors like any other row', function (): void {
    $world = visitorDownstreamWorld();
    $admin = authTokenForRole($world['org'], 'admin');

    foreach (range(1, 3) as $n) {
        visitorDownstreamRedeem($world);
    }
    TenantContextScope::runFor($world['org']->id, function () use ($world): void {
        Participant::factory()->forProject($world['project'])->count(2)->create();
    });

    resetAuthGuardState();
    $list = $this->flushHeaders()->withToken($admin)->getJson('/api/participants')->assertOk();
    $refs = collect($list->json('data'))->pluck('candidate_ref');

    expect($list->json('data'))->toHaveCount(5)
        ->and($refs->filter(fn (string $ref): bool => str_starts_with($ref, 'rlv_')))->toHaveCount(3);

    resetAuthGuardState();
    $metrics = $this->flushHeaders()->withToken($admin)->getJson('/api/dashboard/metrics')->assertOk();

    expect(array_sum($metrics->json('data.participants_by_status')))->toBe(5)
        ->and($metrics->json('data.participants_by_status.in_attesa'))->toBe(5);
});

test('searching the admin list by a person name or email returns no visitor', function (): void {
    $world = visitorDownstreamWorld();
    $admin = authTokenForRole($world['org'], 'admin');
    visitorDownstreamRedeem($world);
    visitorDownstreamRedeem($world);

    foreach (['Ada Lovelace', 'ada.lovelace@example.com', 'ada@acme.test'] as $term) {
        resetAuthGuardState();
        $found = $this->flushHeaders()->withToken($admin)->getJson('/api/participants?q='.urlencode($term))->assertOk();

        expect($found->json('data'))->toBe([], $term);
    }

    // The control: the same list does find a visitor by what it IS (its label
    // and number), so an empty result above means "no person match", not "the
    // search finds nothing".
    resetAuthGuardState();
    $byName = $this->flushHeaders()->withToken($admin)->getJson('/api/participants?q='.urlencode('Reusable link #1'))->assertOk();
    expect($byName->json('data'))->toHaveCount(1);
});

test('a visitor holds no personal identity: a placeholder address and a numbered display name', function (): void {
    $world = visitorDownstreamWorld();

    ['visitor' => $visitor] = visitorDownstreamRedeem($world);

    expect($visitor->email)->toBe($visitor->candidate_ref.'@invalid.beai.local')
        ->and($visitor->display_name)->toBe('Reusable link #1');
});
