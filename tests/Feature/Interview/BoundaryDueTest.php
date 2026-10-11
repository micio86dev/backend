<?php

declare(strict_types=1);

/**
 * `boundary_due` on the POST /utterance 202 (tavus-single-session-interview, API-06 / N9).
 *
 * Due when the row's SUBSTANTIVE candidate turns (>= projects.nudge_min_chars; all when null) reach
 * 1 + the ROW's own follow_up_budget snapshot + conversation.boundary_grace_turns.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Testing\TestResponse;

const BD_LONG = 'This is a deliberately long, substantive candidate answer with enough characters.';

/**
 * @return array{0: InterviewSession, 1: string}
 */
function boundaryDueSession(?int $nudge = 30, ?int $budget = 4, string $status = 'in_corso'): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'nudge_min_chars' => $nudge]);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'bd-'.uniqid(),
        'display_name' => 'Boundary Due',
        'email' => uniqid('bd-').'@example.test',
        'status' => 'in_corso',
    ])->save();

    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'tavus',
        'status' => $status,
        'follow_up_budget' => $budget,
    ]);

    return [$session, CandidateTokenFactory::mintCandidateToken($participant->fresh())];
}

function boundaryDueSeed(InterviewSession $session, int $count, string $speaker = 'candidate', string $text = BD_LONG): void
{
    for ($i = 0; $i < $count; $i++) {
        $u = new Utterance;
        $u->forceFill([
            'interview_session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now(),
        ])->save();
    }
}

function boundaryDuePost(mixed $test, InterviewSession $session, string $token, string $speaker = 'candidate', string $text = BD_LONG): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', [
            'session_id' => $session->id,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now()->toIso8601String(),
        ]);
}

test('below the threshold boundary_due is false', function (): void {
    [$session, $token] = boundaryDueSession();
    boundaryDueSeed($session, 4);

    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => false]);
});

test('at and above 1 + budget + grace substantive turns boundary_due is true', function (): void {
    [$session, $token] = boundaryDueSession();
    boundaryDueSeed($session, 5);

    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => true]);
    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => true]);
});

test('turns shorter than nudge_min_chars do not count', function (): void {
    [$session, $token] = boundaryDueSession();
    boundaryDueSeed($session, 5, 'candidate', 'ok');

    boundaryDuePost($this, $session, $token, 'candidate', 'yes')->assertStatus(202)->assertExactJson(['boundary_due' => false]);
});

test('every candidate turn counts when nudge_min_chars is null', function (): void {
    [$session, $token] = boundaryDueSession(nudge: null);
    boundaryDueSeed($session, 5, 'candidate', 'ok');

    boundaryDuePost($this, $session, $token, 'candidate', 'yes')->assertStatus(202)->assertExactJson(['boundary_due' => true]);
});

test('avatar turns never count', function (): void {
    [$session, $token] = boundaryDueSession();
    boundaryDueSeed($session, 5, 'avatar');

    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => false]);
});

test('the threshold uses the row follow_up_budget snapshot, not the global config', function (): void {
    config(['conversation.followup_budget' => 4]);
    [$session, $token] = boundaryDueSession(budget: 1);
    boundaryDueSeed($session, 2);

    // 1 + 1 + 1 = 3: the third substantive turn is due although the global budget would need 6.
    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => true]);
});

test('a row without a snapshot falls back to the configured budget', function (): void {
    config(['conversation.followup_budget' => 3, 'conversation.boundary_grace_turns' => 2]);
    [$session, $token] = boundaryDueSession(budget: null);
    boundaryDueSeed($session, 4);

    // Threshold: 1 + 3 (config) + 2 (config) = 6. At 5 turns (4 seeded + 1 posted), should be false.
    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => false]);
    // At 6 turns, should be true.
    boundaryDuePost($this, $session, $token)->assertStatus(202)->assertExactJson(['boundary_due' => true]);
});

test('409 and 422 are unchanged', function (): void {
    [$session, $token] = boundaryDueSession(status: 'completed');

    boundaryDuePost($this, $session, $token)->assertStatus(409)->assertExactJson(['message' => 'Session is no longer in_corso.']);
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/utterance', ['session_id' => $session->id])->assertStatus(422);
});

test('404 is unchanged for a session of another candidate', function (): void {
    [$session] = boundaryDueSession();
    [, $otherToken] = boundaryDueSession();

    boundaryDuePost($this, $session, $otherToken)->assertStatus(404);
});
