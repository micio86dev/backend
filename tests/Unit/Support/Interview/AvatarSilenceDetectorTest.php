<?php

declare(strict_types=1);

/**
 * `AvatarSilenceDetector` warns when a provider session stretch holds at
 * least four candidate turns and no avatar turn after the opening — the
 * shape of a provider whose LLM stopped answering while the candidate kept
 * talking.
 */

use App\Models\InterviewSession;
use App\Models\Utterance;
use App\Support\Interview\AvatarSilenceDetector;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;

function silenceSession(): InterviewSession
{
    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_corso');

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    return InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'participant_id' => $participant->id,
        'competency_code' => $comps[0]->code,
        'status' => 'in_corso',
        'provider' => 'heygen',
        'llm_binding_status' => 'applied',
    ]);
}

/**
 * @param  list<string>  $speakers
 */
function silenceTurns(InterviewSession $session, ?string $ref, array $speakers): void
{
    foreach ($speakers as $offset => $speaker) {
        $utterance = new Utterance;
        $utterance->forceFill([
            'interview_session_id' => $session->id,
            'provider_session_ref' => $ref,
            'speaker' => $speaker,
            'text' => "{$speaker} turn {$offset}",
            'ts' => now()->addSeconds($offset),
        ]);
        $utterance->save();
    }
}

test('four candidate turns after the opening and no avatar reply logs provider_avatar_silent', function (): void {
    Log::spy();
    $session = silenceSession();
    silenceTurns($session, 'ref-1', ['avatar', 'candidate', 'candidate', 'candidate', 'candidate']);

    (new AvatarSilenceDetector)->inspect($session, 'ref-1');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'provider_avatar_silent'
            && $context['session_id'] === $session->id
            && $context['provider'] === 'heygen'
            && $context['llm_binding_status'] === 'applied'
            && $context['provider_session_ref'] === 'ref-1'
            && $context['candidate_turns'] === 4,
    );
});

test('a stretch with no transcribed opening at all is still silent', function (): void {
    Log::spy();
    $session = silenceSession();
    silenceTurns($session, 'ref-1', ['candidate', 'candidate', 'candidate', 'candidate']);

    (new AvatarSilenceDetector)->inspect($session, 'ref-1');

    Log::shouldHaveReceived('warning')->once();
});

test('an avatar reply after the opening, or fewer than four candidate turns, logs nothing', function (array $speakers): void {
    Log::spy();
    $session = silenceSession();
    silenceTurns($session, 'ref-1', $speakers);

    (new AvatarSilenceDetector)->inspect($session, 'ref-1');

    Log::shouldNotHaveReceived('warning');
})->with([
    'avatar answered' => [['avatar', 'candidate', 'candidate', 'avatar', 'candidate', 'candidate']],
    'three candidate turns' => [['avatar', 'candidate', 'candidate', 'candidate']],
]);

test('only the named stretch is inspected', function (): void {
    Log::spy();
    $session = silenceSession();
    silenceTurns($session, 'ref-old', ['candidate', 'candidate', 'candidate', 'candidate']);
    silenceTurns($session, 'ref-new', ['avatar', 'candidate', 'avatar']);

    (new AvatarSilenceDetector)->inspect($session, 'ref-new');

    Log::shouldNotHaveReceived('warning');
});

test('a null ref is not a stretch and logs nothing', function (): void {
    Log::spy();
    $session = silenceSession();
    silenceTurns($session, null, ['candidate', 'candidate', 'candidate', 'candidate']);

    (new AvatarSilenceDetector)->inspect($session, null);

    Log::shouldNotHaveReceived('warning');
});
