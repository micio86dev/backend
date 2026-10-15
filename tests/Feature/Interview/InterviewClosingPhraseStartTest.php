<?php

declare(strict_types=1);

/**
 * The platform's closing phrase is spoken ONCE, on the LAST competency, in front of the final phrase:
 * the advance phrase becomes "<closing> <final_phrase>". Every other competency keeps its end phrase,
 * `question_context.final_phrase` stays the bare final phrase (the client contains-matches it), and an
 * unset or unreadable setting changes nothing.
 */

use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\PlatformSetting;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => false, 'interview.tavus.single_session_projects' => []]);
    // Start from "unset": the migration seeds a default.
    PlatformSetting::query()->whereKey(PlatformSettings::INTERVIEW_CLOSING_KEY)->delete();
});

/** @param array<string, string>|null $map */
function icpSetClosing(?array $map): void
{
    app(PlatformSettings::class)->setInterviewClosingPhrase($map);
}

function icpFixture(string $language = 'en', int $competencies = 1, ?string $provider = null): Participant
{
    $org = Organization::factory()->create();
    [$project] = casProject($org, $competencies);
    $project->forceFill(['language' => $language])->save();

    if ($provider !== null) {
        $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
            'name' => 'icp '.$provider.uniqid(), 'provider' => $provider, 'config' => [],
        ])->id])->save();
    }

    $participant = casParticipant($org, $project, 'in_attesa');
    $participant->forceFill(['started_at' => null])->save();

    return $participant->fresh();
}

/** @return array<string, mixed> The /start response body. */
function icpStart(Participant $participant): array
{
    return test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201)
        ->json();
}

/** @return list<array<string, mixed>> */
function icpBodies(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST'
            && (str_ends_with($request->url(), '/contexts') || str_ends_with($request->url(), '/v2/conversations')))
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

function icpFakes(): void
{
    Http::fake([
        '*tavusapi*/v2/conversations' => fn () => Http::response([
            'conversation_id' => 'conv-'.uniqid('', true),
            'conversation_url' => 'https://tavus.io/conv',
        ], 200),
        '*tavusapi*/v2/conversations/*' => Http::response([], 200),
        ...heygenOkFake(),
    ]);
}

const ICP_CLOSING_EN = 'Thank you for sharing your experiences, we wish you well.';
const ICP_CLOSING_IT = 'Grazie per aver condiviso le tue esperienze, ti auguriamo il meglio.';

test('the last competency says the closing phrase then the final phrase', function (): void {
    icpFakes();
    icpSetClosing(['en' => ICP_CLOSING_EN, 'it' => ICP_CLOSING_IT]);

    $response = icpStart(icpFixture('en'));
    [$body] = icpBodies();

    expect($body['prompt'])->toContain(ICP_CLOSING_EN.' Thank you for your time.')
        // The wire contract stays the bare final phrase: the client contains-matches it.
        ->and($response['question_context']['final_phrase'])->toBe('Thank you for your time.');
});

test('a non-last competency is unchanged', function (): void {
    icpFakes();
    icpSetClosing(['en' => ICP_CLOSING_EN]);

    icpStart(icpFixture('en', 2));
    [$body] = icpBodies();

    expect($body['prompt'])->not->toContain(ICP_CLOSING_EN)
        ->and($body['prompt'])->toContain("Let's move on to the next question.");
});

test('no row means the final phrase alone', function (): void {
    icpFakes();

    icpStart(icpFixture('en'));
    [$body] = icpBodies();

    expect($body['prompt'])->toContain('Thank you for your time.')
        ->and($body['prompt'])->not->toContain(ICP_CLOSING_EN);
});

test('the closing follows the project language and falls back to en', function (array $closing, string $expected): void {
    icpFakes();
    icpSetClosing($closing);

    icpStart(icpFixture('it'));
    [$body] = icpBodies();

    expect($body['prompt'])->toContain($expected.' Grazie per il tuo tempo.');
})->with([
    'the project language wins' => [['en' => ICP_CLOSING_EN, 'it' => ICP_CLOSING_IT], ICP_CLOSING_IT],
    'en is the fallback' => [['en' => ICP_CLOSING_EN], ICP_CLOSING_EN],
]);

test('Tavus single session: only the last topic of the plan carries the closing phrase', function (): void {
    config(['interview.tavus.single_session' => true]);
    icpFakes();
    icpSetClosing(['en' => ICP_CLOSING_EN]);

    icpStart(icpFixture('en', 3, 'tavus'));
    [$body] = icpBodies();

    expect(substr_count($body['conversational_context'], '=== TOPIC CODE:'))->toBe(3)
        ->and(substr_count($body['conversational_context'], ICP_CLOSING_EN.' Thank you for your time.'))->toBe(1)
        ->and(substr_count($body['conversational_context'], ICP_CLOSING_EN))->toBe(1);
});

test('an unreadable setting never blocks the interview: it falls back to the final phrase and logs a warning', function (): void {
    icpFakes();
    Log::spy();
    icpSetClosing(['en' => ICP_CLOSING_EN]);
    Event::listen('eloquent.retrieved: '.PlatformSetting::class, function (): never {
        throw new RuntimeException('settings store unavailable');
    });

    icpStart(icpFixture('en'));
    [$body] = icpBodies();

    expect($body['prompt'])->toContain('Thank you for your time.')
        ->and($body['prompt'])->not->toContain(ICP_CLOSING_EN);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});
