<?php

declare(strict_types=1);

/**
 * The platform's intro phrase is spoken by the avatar ONCE: in front of the very first question of a
 * participant's first start. It travels inside the single opening string the provider speaks
 * (`opening_text` on HeyGen, `custom_greeting` on Tavus), and the system prompt is told it was said.
 * Never on a next competency, a resume, a retry or a re-interview, and an unset or unreadable
 * setting changes nothing.
 */

use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\PlatformSetting;
use App\Models\Project;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const IIP_CLAUSE = 'Before your opening line you already said a short introduction to the candidate; do not repeat or paraphrase it.';

beforeEach(function (): void {
    Queue::fake();
    PromptSetResolver::flushCache();
    config(['interview.tavus.single_session' => false, 'interview.tavus.single_session_projects' => []]);
});

/** @param array<string, string>|null $map */
function iipSetIntro(?array $map): void
{
    app(PlatformSettings::class)->setInterviewIntroPhrase($map);
}

/** A project of `$competencies` competencies whose participant has NOT started yet. */
function iipFixture(string $language = 'en', int $competencies = 1, ?string $provider = null): Participant
{
    $org = Organization::factory()->create();
    [$project] = casProject($org, $competencies);
    $project->forceFill(['language' => $language])->save();

    if ($provider !== null) {
        $project->forceFill(['avatar_template_id' => AvatarTemplate::create([
            'name' => 'iip '.$provider.uniqid(), 'provider' => $provider, 'config' => [],
        ])->id])->save();
    }

    $participant = casParticipant($org, $project, 'in_attesa');
    $participant->forceFill(['started_at' => null])->save();

    return $participant->fresh();
}

function iipStart(Participant $participant): void
{
    test()->withHeaders(['Authorization' => 'Bearer '.casBearer($participant)])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);
}

/** @return list<array<string, mixed>> Bodies of the provider's context / conversation creations, oldest first. */
function iipBodies(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => $request->method() === 'POST'
            && (str_ends_with($request->url(), '/contexts') || str_ends_with($request->url(), '/v2/conversations')))
        ->map(fn ($request) => $request->data())
        ->values()
        ->all();
}

function iipHeygenFake(): void
{
    Http::fake(heygenOkFake());
}

function iipTavusFake(): void
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

test('HeyGen: the first start speaks the intro in front of primary 1 and the prompt says it was said', function (): void {
    iipHeygenFake();
    iipSetIntro(['en' => 'Welcome to BEAI. Take your time.', 'it' => 'Benvenuto in BEAI.']);

    iipStart(iipFixture('en'));

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe('Welcome to BEAI. Take your time. CAS fixture question 0')
        ->and($body['prompt'])->toContain(IIP_CLAUSE)
        ->and(substr_count($body['prompt'], IIP_CLAUSE))->toBe(1);
});

test('the intro follows the project language and falls back to en', function (array $intro, string $expected): void {
    iipHeygenFake();
    iipSetIntro($intro);

    iipStart(iipFixture('it'));

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe($expected)
        ->and($body['prompt'])->toContain(IIP_CLAUSE);
})->with([
    'the project language wins' => [['en' => 'Welcome to BEAI.', 'it' => 'Benvenuto in BEAI.'], 'Benvenuto in BEAI. Domanda CAS 0'],
    'en is the fallback' => [['en' => 'Welcome to BEAI.'], 'Welcome to BEAI. Domanda CAS 0'],
]);

test('an unset intro changes nothing: no text, no clause', function (): void {
    iipHeygenFake();

    iipStart(iipFixture('en'));

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe('CAS fixture question 0')
        ->and($body['prompt'])->not->toContain('introduction to the candidate');
});

test('a participant who already started is not welcomed again', function (): void {
    iipHeygenFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    $participant = iipFixture('en');
    $participant->forceFill(['started_at' => now()])->save();

    iipStart($participant);

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe('CAS fixture question 0')
        ->and($body['prompt'])->not->toContain('introduction to the candidate');
});

test('a resume of the live session does not repeat the intro', function (): void {
    iipHeygenFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    $participant = iipFixture('en');

    iipStart($participant);
    iipStart($participant);

    $bodies = iipBodies();

    expect($bodies[0]['opening_text'])->toBe('Welcome to BEAI. CAS fixture question 0')
        ->and($bodies[1]['opening_text'])->toBe('CAS fixture question 0')
        ->and($bodies[1]['prompt'])->not->toContain('introduction to the candidate');
});

test('Tavus: the intro is in the custom greeting of a single-competency start', function (): void {
    iipTavusFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);

    iipStart(iipFixture('en', 1, 'tavus'));

    [$body] = iipBodies();

    expect($body['custom_greeting'])->toBe('Welcome to BEAI. CAS fixture question 0')
        ->and($body['conversational_context'])->toContain(IIP_CLAUSE);
});

test('Tavus single session: the intro is in the greeting and only the first topic of the plan says it was said', function (): void {
    config(['interview.tavus.single_session' => true]);
    iipTavusFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);

    iipStart(iipFixture('en', 3, 'tavus'));

    [$body] = iipBodies();

    expect($body['custom_greeting'])->toBe('Welcome to BEAI. CAS fixture question 0')
        ->and(substr_count($body['conversational_context'], '=== TOPIC CODE:'))->toBe(3)
        ->and(substr_count($body['conversational_context'], IIP_CLAUSE))->toBe(1);
});

test('an unreadable setting never blocks the interview: it starts without the intro and logs a warning', function (): void {
    iipHeygenFake();
    Log::spy();
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    Event::listen('eloquent.retrieved: '.PlatformSetting::class, function (): never {
        throw new RuntimeException('settings store unavailable');
    });

    iipStart(iipFixture('en'));

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe('CAS fixture question 0')
        ->and($body['prompt'])->not->toContain('introduction to the candidate');
    Log::shouldHaveReceived('warning')->once();
});

test('a second competency is never introduced, even for a participant who has not started', function (): void {
    iipHeygenFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    $participant = iipFixture('en', 2);
    $org = Organization::query()->findOrFail($participant->organization_id);
    $project = casInTenant($org, fn () => Project::query()->findOrFail($participant->project_id));
    $firstId = DB::table('project_competencies')->where('project_id', $project->id)->orderBy('position')->value('competency_id');
    $firstCode = Competency::query()->findOrFail($firstId)->code;
    casInTenant($org, fn () => InterviewSession::create([
        'participant_id' => $participant->id, 'project_id' => $project->id, 'question_index' => 0,
        'competency_code' => $firstCode, 'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen', 'status' => 'completed', 'primary_questions' => [], 'follow_up_budget' => 4,
    ]));

    iipStart($participant);

    [$body] = iipBodies();

    expect($body['opening_text'])->toBe('CAS fixture question 1')
        ->and($body['prompt'])->not->toContain('introduction to the candidate');
});

test('a transcript that holds the intro in front of primary 1 still counts as primary 1 asked on resume', function (): void {
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/contexts') && $request->method() === 'POST') {
            return Http::response(['data' => ['id' => 'ctx-'.uniqid('', true)]], 200);
        }
        if (str_contains($request->url(), '/sessions/token')) {
            return Http::response(['data' => ['session_id' => 'heygen-'.uniqid('', true), 'session_token' => 'tok-'.uniqid('', true)]], 200);
        }
        if (str_contains($request->url(), '/transcript')) {
            return Http::response(['data' => ['transcript_data' => [
                ['role' => 'assistant', 'transcript' => 'Welcome to BEAI. CAS fixture question 0', 'time_ms' => 2000],
            ]]], 200);
        }

        return Http::response([], 200);
    });
    $participant = iipFixture('en');

    iipStart($participant);
    iipStart($participant);

    $resumed = iipBodies()[1];

    expect($resumed['opening_text'])->toBe('CAS fixture question 0')
        ->and($resumed['prompt'])->toContain('Every primary question was already asked before the interruption')
        ->and($resumed['prompt'])->not->toContain('introduction to the candidate');
});

test('a live session with a participant that never started still gets no intro, in the opening or the prompt', function (): void {
    iipHeygenFake();
    iipSetIntro(['en' => 'Welcome to BEAI.']);
    $participant = iipFixture('en');

    iipStart($participant);
    $participant->forceFill(['started_at' => null])->save();
    iipStart($participant);

    $resumed = iipBodies()[1];

    expect($resumed['opening_text'])->toBe('CAS fixture question 0')
        ->and($resumed['prompt'])->not->toContain('introduction to the candidate');
});
