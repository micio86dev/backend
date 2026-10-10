<?php

declare(strict_types=1);

/**
 * RED/GREEN — tavus-single-session-interview API-03b.3: the create path with a plan.
 *
 * With the single-session gate open (Tavus, flag or canary, and more than one fresh competency
 * left) `/start` composes ONE multi-competency context, calls `issue()` once, persists the plan
 * on the creating row in its short transaction (no anchor in it), stamps the row with the plan's
 * prompt set and returns the provider conversation id. With the gate closed (the default) nothing
 * changes: same response keys, no column write, no `participant_left_timeout` in the create body.
 */

use App\Actions\Interview\BuildInterviewSessionResponse;
use App\Actions\Interview\ComposeConversationPlan;
use App\Enums\ApiKeyMode;
use App\Enums\AssessmentType;
use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Catalogue\CatalogueRevisionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => false, 'interview.tavus.single_session_projects' => []]);
});

/**
 * @return array{0: Organization, 1: Project, 2: Participant, 3: list<string>} The last is the competency codes in order.
 */
function cpcFixture(int $competencies, string $provider = 'tavus'): array
{
    $org = Organization::factory()->create();
    [$project, $comps] = casProject($org, $competencies);
    $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
        'name' => 'cpc '.$provider.uniqid(), 'provider' => $provider, 'config' => [],
    ])->id])->save();

    return [$org, $project, casParticipant($org, $project, 'in_attesa'), array_map(fn ($c): string => $c->code, $comps)];
}

function cpcFake(): void
{
    Http::fake([
        // A closure so every conversation gets its own id: one open live period per ref.
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
        ...heygenOkFake(),
    ]);
}

function cpcStart(Participant $participant): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])->postJson('/api/candidate/interview/start');
}

/** @return array<string, mixed> The body of the one `POST /v2/conversations`. */
function cpcCreateBody(): array
{
    $creations = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/conversations'))
        ->values();

    expect($creations)->toHaveCount(1);

    return $creations->first()->data();
}

function cpcRow(Organization $org, Participant $participant): InterviewSession
{
    return casInTenant($org, fn () => InterviewSession::query()->where('participant_id', $participant->id)->sole());
}

test('gate open: the plan is persisted on the creating row, conversation_id is returned and the row is stamped', function (): void {
    config(['interview.tavus.single_session' => true, 'conversation.prompt_source' => 'db']);
    cpcFake();
    [$org, $project, $participant, $codes] = cpcFixture(3);

    $response = cpcStart($participant)->assertStatus(201);

    $row = cpcRow($org, $participant);
    $body = cpcCreateBody();

    $response->assertJsonPath('conversation_id', $row->provider_session_ref);
    expect($row->conversation_plan['competencies'])->toHaveCount(3)
        ->and(array_column($row->conversation_plan['competencies'], 'code'))->toBe($codes)
        ->and($row->conversation_plan['competencies'][0])->toHaveKeys(['code', 'primary_questions', 'follow_up_budget'])
        ->and($row->conversation_plan['chars'])->toBe(mb_strlen($body['conversational_context']))
        ->and($row->conversation_prompt_version)->toMatch('/^'.preg_quote((string) config('conversation.prompt_version'), '/').'\+s\d+\.[0-9a-f]{12}$/')
        ->and(substr_count($body['conversational_context'], '=== TOPIC CODE:'))->toBe(3)
        ->and($body['properties']['participant_left_timeout'])->toBe(60)
        ->and($response->json('question_context.prompt_version'))->toBe(config('conversation.prompt_version'));
});

test('gate open on the baseline source stamps the bare prompt version', function (): void {
    config(['interview.tavus.single_session' => true, 'conversation.prompt_source' => 'baseline']);
    cpcFake();
    [$org, , $participant] = cpcFixture(2);

    cpcStart($participant)->assertStatus(201);

    expect(cpcRow($org, $participant)->conversation_prompt_version)->toBe(config('conversation.prompt_version'));
});

test('a canary project opens the gate with the global flag off', function (): void {
    cpcFake();
    [$org, $project, $participant] = cpcFixture(2);
    config(['interview.tavus.single_session_projects' => [$project->id]]);

    cpcStart($participant)->assertStatus(201)->assertJsonStructure(['conversation_id']);

    expect(cpcRow($org, $participant)->conversation_plan)->not->toBeNull();
});

test('gate closed: the response keys are the old ones, no column is written and no participant_left_timeout is sent', function (): void {
    cpcFake();
    [$org, , $participant] = cpcFixture(3);

    $response = cpcStart($participant)->assertStatus(201);

    $body = cpcCreateBody();
    expect(array_keys($response->json()))->toBe(['session_id', 'provider', 'audio_only', 'provider_token', 'conversation_url', 'question_context'])
        ->and(cpcRow($org, $participant)->conversation_plan)->toBeNull()
        ->and($body['properties'] ?? [])->not->toHaveKey('participant_left_timeout')
        ->and($body['conversational_context'])->not->toContain('=== TOPIC CODE:');
});

test('a single remaining competency writes no plan and returns no conversation_id', function (): void {
    config(['interview.tavus.single_session' => true]);
    cpcFake();
    [$org, , $participant] = cpcFixture(1);

    $response = cpcStart($participant)->assertStatus(201);

    expect($response->json())->not->toHaveKey('conversation_id')
        ->and(cpcRow($org, $participant)->conversation_plan)->toBeNull()
        ->and(cpcCreateBody()['conversational_context'])->not->toContain('=== TOPIC CODE:');
});

test('HeyGen is invariant with the flag on', function (): void {
    config(['interview.tavus.single_session' => true]);
    cpcFake();
    [$org, , $participant] = cpcFixture(3, 'heygen');

    $response = cpcStart($participant)->assertStatus(201);

    expect($response->json())->not->toHaveKey('conversation_id')
        ->and(cpcRow($org, $participant)->conversation_plan)->toBeNull();
});

test('the mock provider is invariant with the flag on', function (): void {
    config(['interview.tavus.single_session' => true]);
    cpcFake();
    [$org, , $participant] = cpcFixture(3);
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    $response = cpcStart($participant)->assertStatus(201);

    expect($response->json())->not->toHaveKey('conversation_id')
        ->and($response->json('provider'))->toBe('mock')
        ->and(cpcRow($org, $participant)->conversation_plan)->toBeNull();
});

test('a plan that fits only the first competency falls back to the ordinary single-competency create', function (): void {
    config(['interview.tavus.single_session' => true, 'conversation.prompt_source' => 'baseline']);
    cpcFake();
    [$org, $project, $participant] = cpcFixture(2);

    // The first competency alone fits exactly; adding the second does not.
    $single = app(ComposeConversationPlan::class);
    [$endPhrase, $finalPhrase] = app(BuildInterviewSessionResponse::class)->resolveCompletionPhrases($project->language);
    $first = casInTenant($org, fn () => $single->handle(
        $project,
        AssessmentType::Standard,
        app(CatalogueRevisionResolver::class)->tryForProject($project),
        [['competency_code' => Competency::query()->orderBy('id')->firstOrFail()->code, 'competency_ordinal' => 1, 'total_competencies' => 2]],
        4,
        $endPhrase,
        $finalPhrase,
    ));
    config(['conversation.max_context_chars' => $first->chars + 1]);

    $response = cpcStart($participant)->assertStatus(201);

    expect($response->json())->not->toHaveKey('conversation_id')
        ->and(cpcRow($org, $participant)->conversation_plan)->toBeNull()
        ->and(cpcCreateBody()['conversational_context'])->not->toContain('=== TOPIC CODE:');
});

test('a composition failure for a covered competency answers 422 before any provider call', function (): void {
    config(['interview.tavus.single_session' => true, 'conversation.prompt_source' => 'db']);
    cpcFake();
    [$org, , $participant] = cpcFixture(2);
    PromptTables::empty();

    cpcStart($participant)->assertStatus(422)->assertJsonPath('error', 'composition_error');

    Http::assertNothingSent();
    expect(casInTenant($org, fn () => InterviewSession::query()->count()))->toBe(0);
});
