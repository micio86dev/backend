<?php

declare(strict_types=1);

/**
 * A resumed competency re-asks the pending primary question, verbatim.
 *
 * Suspend tears the provider session down after harvesting its transcript;
 * the next /start resumes the same in_corso session. The opening it speaks is
 * the primary after the last one already asked — or the last primary, when
 * every one was asked — and the composed prompt says so. The re-ask is a
 * `primary` turn that never counts a primary twice. Harvest and /end also
 * report a stretch where the avatar went silent after its opening.
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`).
 */

use App\Models\BarsIndicator;
use App\Models\InterviewSession;
use App\Models\ProjectQuestion;
use App\Models\Utterance;
use App\Support\Interview\TurnClassifier;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * A HeyGen fake whose transcript is `$transcript` and which records every
 * `/contexts` body and every token issue.
 *
 * @param  list<array{role: string, transcript: string, time_ms: int}>  $transcript
 * @return object{contexts: list<array<string, mixed>>, tokens: int}
 */
function resumeReAskFake(array $transcript): object
{
    $calls = new class
    {
        /** @var list<array<string, mixed>> */
        public array $contexts = [];

        public int $tokens = 0;
    };

    Http::fake(function ($request) use ($calls, $transcript) {
        $url = $request->url();

        if (str_contains($url, '/contexts')) {
            $calls->contexts[] = $request->data();

            return Http::response(['data' => ['id' => 'ctx-'.uniqid()]], 200);
        }

        if (str_contains($url, '/sessions/token')) {
            $calls->tokens++;

            return Http::response(['data' => [
                'session_id' => 'heygen-resume-'.$calls->tokens,
                'session_token' => 'tok-'.$calls->tokens,
            ]], 200);
        }

        if (str_contains($url, '/transcript')) {
            return Http::response(['data' => ['transcript_data' => $transcript]], 200);
        }

        return Http::response([], 200);
    });

    return $calls;
}

/**
 * A one-competency project with two primaries, started once.
 *
 * @return array{session_id: int, bearer: string, competency_id: int}
 */
function resumeReAskStart(): array
{
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 1);

    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->update(['text' => ['en' => 'Ciao! Come ti chiami?']]);

    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $comps[0]->id,
        'text' => ['en' => 'Tell me about a deadline you nearly missed.'],
        'position' => 1,
    ]);

    $participant = casParticipant($org, $project, 'in_attesa');
    $bearer = casBearer($participant);

    $start = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    return [
        'session_id' => (int) $start->json('session_id'),
        'bearer' => $bearer,
        'competency_id' => $comps[0]->id,
    ];
}

function resumeReAskSuspendAndResume(array $started): void
{
    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/suspend', ['session_id' => $started['session_id']])
        ->assertOk();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(201)
        ->assertJsonPath('session_id', $started['session_id']);
}

test('suspend then /start re-asks the pending primary verbatim and tags the turns correctly', function (): void {
    $calls = resumeReAskFake([
        ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 4000],
        ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 3000],
    ]);

    $started = resumeReAskStart();
    resumeReAskSuspendAndResume($started);

    $resumed = $calls->contexts[1];
    expect($resumed['opening_text'])->toBe('Tell me about a deadline you nearly missed.');

    $prompt = (string) preg_replace('/\s+/', ' ', $resumed['prompt']);
    expect($prompt)->toContain('re-asked primary question 2, word for word: "Tell me about a deadline you nearly missed."')
        ->and($prompt)->toContain('Primary question 1 was asked before the interruption')
        ->and($prompt)->not->toContain('primary question 3');

    // The re-ask arrives as a live avatar turn: it is primary 2, and it advances.
    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $started['session_id'],
            'speaker' => 'avatar',
            'text' => 'Tell me about a deadline you nearly missed.',
            'ts' => now()->toIso8601String(),
        ])
        ->assertStatus(202);

    $session = InterviewSession::findOrFail($started['session_id']);

    expect(Utterance::where('interview_session_id', $session->id)
        ->where('speaker', 'avatar')
        ->orderBy('ts')
        ->pluck('turn_kind')
        ->all())->toBe(['primary', 'primary'])
        ->and((new TurnClassifier)->matchedCount($session))->toBe(2);
});

test('when every primary was already asked, /start re-asks the last one and it is not counted twice', function (): void {
    $calls = resumeReAskFake([
        ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 6000],
        ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 5000],
        ['role' => 'assistant', 'transcript' => 'Tell me about a deadline you nearly missed.', 'time_ms' => 4000],
        ['role' => 'user', 'transcript' => 'Last spring, a release slipped.', 'time_ms' => 3000],
    ]);

    $started = resumeReAskStart();
    resumeReAskSuspendAndResume($started);

    $resumed = $calls->contexts[1];
    expect($resumed['opening_text'])->toBe('Tell me about a deadline you nearly missed.');

    $prompt = (string) preg_replace('/\s+/', ' ', $resumed['prompt']);
    expect($prompt)->toContain('Every primary question was already asked before the interruption')
        ->and($prompt)->toContain('everything you ask from here on is a follow-up');

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $started['session_id'],
            'speaker' => 'avatar',
            'text' => 'Tell me about a deadline you nearly missed.',
            'ts' => now()->toIso8601String(),
        ])
        ->assertStatus(202);

    $session = InterviewSession::findOrFail($started['session_id']);

    expect(Utterance::where('interview_session_id', $session->id)
        ->where('speaker', 'avatar')
        ->where('turn_kind', 'primary')
        ->count())->toBe(3)
        ->and((new TurnClassifier)->matchedCount($session))->toBe(2);
});

test('a composition failure on resume fails the start with 422 and issues no provider session', function (): void {
    $calls = resumeReAskFake([]);

    $started = resumeReAskStart();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/suspend', ['session_id' => $started['session_id']])
        ->assertOk();

    BarsIndicator::where('competency_id', $started['competency_id'])->delete();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/start')
        ->assertStatus(422)
        ->assertJsonPath('error', 'composition_error');

    $session = InterviewSession::findOrFail($started['session_id']);

    expect($calls->tokens)->toBe(1)
        ->and($session->status)->toBe('in_corso')
        ->and($session->provider_session_ref)->toBeNull();
});

test('a harvested stretch where the avatar never answered after the opening logs provider_avatar_silent', function (): void {
    resumeReAskFake([
        ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 9000],
        ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 8000],
        ['role' => 'user', 'transcript' => 'Hello?', 'time_ms' => 7000],
        ['role' => 'user', 'transcript' => 'Are you there?', 'time_ms' => 6000],
        ['role' => 'user', 'transcript' => 'Can you hear me?', 'time_ms' => 5000],
    ]);

    $started = resumeReAskStart();
    Log::spy();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/suspend', ['session_id' => $started['session_id']])
        ->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'provider_avatar_silent'
            && $context['session_id'] === $started['session_id']
            && $context['candidate_turns'] === 4,
    );
});

test('/end checks the surviving stretch for a silent avatar', function (): void {
    resumeReAskFake([
        ['role' => 'assistant', 'transcript' => 'Ciao! Come ti chiami?', 'time_ms' => 9000],
        ['role' => 'user', 'transcript' => 'Marco.', 'time_ms' => 8000],
        ['role' => 'user', 'transcript' => 'Hello?', 'time_ms' => 7000],
        ['role' => 'user', 'transcript' => 'Are you there?', 'time_ms' => 6000],
        ['role' => 'user', 'transcript' => 'Can you hear me?', 'time_ms' => 5000],
    ]);

    $started = resumeReAskStart();
    Log::spy();

    test()->withHeaders(['Authorization' => 'Bearer '.$started['bearer']])
        ->postJson('/api/candidate/interview/end', ['session_id' => $started['session_id'], 'ended_reason' => 'completed'])
        ->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'provider_avatar_silent'
            && $context['session_id'] === $started['session_id'],
    );
});
