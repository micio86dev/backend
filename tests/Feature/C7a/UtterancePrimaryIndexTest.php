<?php

declare(strict_types=1);

/**
 * `primary_index` on the POST /utterance 202 (live-interview-call-chrome, T-A3.1).
 *
 * The number of DISTINCT scripted primaries of THIS competency session asked so far, as
 * `TurnClassifier::matchedCount()` replays it once the request is committed. Driven through the
 * real endpoint with real avatar text, so the classifier decides what counts.
 *
 * REQ: interview-session "POST /utterance" - Primary index
 */

use App\Models\InterviewEvent;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const PIX_PRIMARIES = ['Tell me about a project you led.', 'How do you handle conflict?', 'What would you change next time?'];

/**
 * @param  list<string>|null  $primaries
 * @return array{0: InterviewSession, 1: string, 2: Participant}
 */
function pixSession(?array $primaries = PIX_PRIMARIES, string $status = 'in_corso', ?Participant $participant = null, string $code = 'PRS'): array
{
    if ($participant === null) {
        $org = Organization::factory()->create();
        $resolver = app(TenantResolver::class);
        $resolver->setOrgId($org->id);
        $resolver->setBypass(false);

        $project = Project::factory()->create(['status' => 'active']);
        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'pix-'.uniqid(),
            'display_name' => 'Primary Index',
            'email' => uniqid('pix-').'@example.test',
            'status' => 'in_corso',
        ])->save();
        $participant = $participant->fresh();
    }

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $participant->project_id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => Project::findOrFail($participant->project_id)->framework_version_id,
        'provider' => 'tavus',
        'status' => $status,
        'primary_questions' => $primaries,
    ]);

    return [$session, CandidateTokenFactory::mintCandidateToken($participant), $participant];
}

function pixPost(mixed $test, InterviewSession $session, string $token, string $speaker, string $text): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now()->toIso8601String(),
        ]);
}

test('the first scripted primary yields 1 and the next distinct one yields 2', function (): void {
    [$session, $token] = pixSession();

    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0])->assertStatus(202)->assertJsonPath('primary_index', 1);
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[1])->assertStatus(202)->assertJsonPath('primary_index', 2);
});

test('a follow-up and a candidate turn do not advance primary_index', function (): void {
    [$session, $token] = pixSession();
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0])->assertJsonPath('primary_index', 1);

    pixPost($this, $session, $token, 'avatar', 'Interesting, could you say more about that?')
        ->assertStatus(202)->assertJsonPath('primary_index', 1);
    pixPost($this, $session, $token, 'candidate', 'I led a migration of our billing system last year.')
        ->assertStatus(202)->assertJsonPath('primary_index', 1);
});

test('a verbatim re-ask does not advance primary_index and the next distinct primary yields 3', function (): void {
    [$session, $token] = pixSession();
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0]);
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[1])->assertJsonPath('primary_index', 2);

    pixPost($this, $session, $token, 'avatar', 'Let me repeat. '.PIX_PRIMARIES[1])
        ->assertStatus(202)->assertJsonPath('primary_index', 2);
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[2])
        ->assertStatus(202)->assertJsonPath('primary_index', 3);
});

test('primary_index is 0 before any scripted primary', function (): void {
    [$session, $token] = pixSession();

    pixPost($this, $session, $token, 'candidate', 'Hello, can you hear me?')
        ->assertStatus(202)->assertJsonPath('primary_index', 0);
});

test('a competency whose frozen set holds no primaries always returns 0', function (?array $primaries): void {
    [$session, $token] = pixSession($primaries);

    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0])->assertStatus(202)->assertJsonPath('primary_index', 0);
    pixPost($this, $session, $token, 'candidate', 'An answer.')->assertStatus(202)->assertJsonPath('primary_index', 0);
})->with([[null], [[]]]);

test('the count restarts at 1 after a re-offer discards the transcript', function (): void {
    [$session, $token] = pixSession();
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0]);
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[1])->assertJsonPath('primary_index', 2);

    // What `ResetSessionForRetry` does to the transcript of a re-offered competency.
    Utterance::where('interview_session_id', $session->id)->delete();

    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0])->assertStatus(202)->assertJsonPath('primary_index', 1);
});

test('primary_index is scoped to its own competency session', function (): void {
    [$first, $token, $participant] = pixSession();
    foreach (PIX_PRIMARIES as $primary) {
        pixPost($this, $first, $token, 'avatar', $primary);
    }
    [$second, $secondToken] = pixSession(['Describe a deadline you missed.'], participant: $participant, code: 'COL');

    pixPost($this, $second, $secondToken, 'avatar', 'Describe a deadline you missed.')
        ->assertStatus(202)->assertJsonPath('primary_index', 1);
    pixPost($this, $first, $token, 'candidate', 'Still answering the first.')->assertJsonPath('primary_index', 3);
});

test('a dropped utterance still answers 409 with its original body and no primary_index', function (): void {
    [$session, $token] = pixSession(status: 'completed');

    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0])
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Session is no longer in_corso.']);
});

test('the stored question_asked event keeps its 0-based index', function (): void {
    [$session, $token, $participant] = pixSession();
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[0]);
    pixPost($this, $session, $token, 'avatar', PIX_PRIMARIES[1])->assertJsonPath('primary_index', 2);

    $indices = TenantContextScope::runFor($session->organization_id, fn () => InterviewEvent::where('participant_id', $participant->id)
        ->where('type', 'question_asked')->orderBy('id')->get()->map(fn ($e) => $e->data['question_index'])->all());

    expect($indices)->toBe([0, 1]);
});
