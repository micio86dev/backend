<?php

declare(strict_types=1);

/**
 * RED — split-interview-controller design.md D1: BuildInterviewSessionResponse.
 *
 * A verbatim move of InterviewController::buildSuccessResponse() +
 * resolveCompletionPhrases() + resolveAudioOnly(). See those methods' original
 * docblocks (preserved on the new class) for the field-by-field rationale;
 * covers the same audio_only true/false behavior
 * tests/Feature/C7a/InterviewStartTest.php's "audio_only (voice-only templates)"
 * section proves end-to-end, at the Action layer instead of over HTTP.
 */

use App\Actions\Interview\BuildInterviewSessionResponse;
use App\Models\AvatarTemplate;
use App\Models\InterviewSession;
use App\Services\Provider\ProviderToken;
use App\Support\Tenancy\TenantResolver;

function bisrFixture(bool $audioOnly = false): array
{
    $org = casOrg();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    [$project] = casProject($org, 1);

    AvatarTemplate::whereKey($project->avatar_template_id)
        ->update(['config' => json_encode(['audioOnly' => $audioOnly])]);

    $participant = casParticipant($org, $project);

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'COL',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'ref-1',
        'status' => 'in_corso',
        'started_at' => now(),
    ]);

    return [$session, $project];
}

test('builds the 201 response shape with every question_context field', function (): void {
    [$session] = bisrFixture();
    $token = new ProviderToken('heygen', token: 'tok-123', conversation_url: null);

    $response = (new BuildInterviewSessionResponse)->handle(
        $session,
        $token,
        language: 'en',
        promptVersion: 'v3',
        competencyOrdinal: 2,
        totalCompetencies: 5,
    );

    $data = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($data['session_id'])->toBe($session->id)
        ->and($data['provider'])->toBe('heygen')
        ->and($data['provider_token'])->toBe('tok-123')
        ->and($data['conversation_url'])->toBeNull()
        ->and($data['question_context'])->toBe([
            'competency_code' => 'COL',
            'question_index' => 0,
            'end_phrase' => "Let's move on to the next question.",
            'final_phrase' => 'Thank you for your time.',
            'prompt_version' => 'v3',
            'competency_ordinal' => 2,
            'total_competencies' => 5,
        ]);
});

test('audio_only is true when the pinned avatar template config sets it', function (): void {
    [$session] = bisrFixture(audioOnly: true);
    $token = new ProviderToken('tavus', conversation_url: 'https://tavus.example/room');

    $response = (new BuildInterviewSessionResponse)->handle($session, $token, language: null);

    expect($response->getData(true)['audio_only'])->toBeTrue();
});

test('audio_only is false when the pinned avatar template config does not set it', function (): void {
    [$session] = bisrFixture(audioOnly: false);
    $token = new ProviderToken('tavus', conversation_url: 'https://tavus.example/room');

    $response = (new BuildInterviewSessionResponse)->handle($session, $token, language: null);

    expect($response->getData(true)['audio_only'])->toBeFalse();
});

test('a null/unknown language falls back to the platform default phrases', function (): void {
    [$session] = bisrFixture();
    $token = new ProviderToken('heygen', token: 'tok');

    $response = (new BuildInterviewSessionResponse)->handle($session, $token, language: 'zz-not-a-real-locale');

    $ctx = $response->getData(true)['question_context'];
    expect($ctx['end_phrase'])->toBe("Let's move on to the next question.")
        ->and($ctx['final_phrase'])->toBe('Thank you for your time.');
});
