<?php

declare(strict_types=1);

/**
 * `ResetSessionForRetry` — the single definition of "give this competency back"
 * (interview-continuous-flow, D3).
 *
 * Extracted from `RecoverFailedParticipant` so the automatic re-offer and the
 * operator recovery path cannot drift apart. Both call it; they differ only in
 * what triggers them and whether a bound applies.
 *
 * These assertions were owed and initially skipped — the behaviour was covered
 * only indirectly through the `/start` feature tests. That is not the same thing:
 * `error_count` surviving the reset IS the bound, and a bound deserves an
 * assertion of its own rather than being inferred from a downstream symptom.
 */

use App\Actions\InterviewSession\ResetSessionForRetry;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * An errored session with a transcript, in tenant context.
 *
 * @return array{0: InterviewSession, 1: Organization, 2: Participant}
 */
function resetFixture(int $errorCount = 1): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'assessment_type' => 'standard']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'reset-'.uniqid(),
        'display_name' => 'Reset Test Candidate',
        'status' => 'in_corso',
        'started_at' => now(),
    ]);
    $participant->save();

    $session = new InterviewSession;
    $session->forceFill([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'provider-ref-to-clear',
        'status' => 'error',
        'ended_reason' => 'error',
        'error_count' => $errorCount,
        'started_at' => now()->subMinutes(5),
        'ended_at' => now(),
    ]);
    $session->save();

    foreach (['candidate', 'avatar'] as $i => $speaker) {
        Utterance::create([
            'interview_session_id' => $session->id,
            'speaker' => $speaker,
            'text' => "first attempt turn {$i}",
            'ts' => now(),
        ]);
    }

    return [$session->fresh(), $org, $participant];
}

test('the reset clears the four session fields that make it resumable', function (): void {
    [$session] = resetFixture();

    (new ResetSessionForRetry)($session);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('pending');
    expect($fresh->provider_session_ref)->toBeNull();
    expect($fresh->ended_reason)->toBeNull();
    expect($fresh->ended_at)->toBeNull();
});

test('the reset does NOT touch error_count — this is the bound', function (): void {
    // A counter cleared by its own reset is not a bound: reset, fail, reset, fail,
    // forever. `markSessionError()` is its sole writer, deliberately.
    [$session] = resetFixture(errorCount: 1);

    (new ResetSessionForRetry)($session);

    expect($session->fresh()->error_count)->toBe(1, 'the spent attempt must survive the reset');
});

test('the reset deletes the previous attempt transcript and reports how many', function (): void {
    // A competency must never be scored on a transcript that mixes two attempts.
    [$session] = resetFixture();

    expect($session->utterances()->count())->toBe(2);

    $discarded = (new ResetSessionForRetry)($session);

    expect($discarded)->toBe(2);
    expect($session->fresh()->utterances()->count())->toBe(0);
});

test('the reset leaves the session started_at alone — it is a resume, not a restart', function (): void {
    [$session] = resetFixture();
    $startedAt = $session->started_at;

    (new ResetSessionForRetry)($session);

    expect($session->fresh()->started_at?->timestamp)->toBe($startedAt?->timestamp);
});

test('the reset never touches another session utterances', function (): void {
    [$sessionA, $orgA] = resetFixture();
    [$sessionB] = resetFixture();

    (new ResetSessionForRetry)($sessionA);

    // Raw count, unscoped: the guarantee is about the delete's WHERE clause, not
    // about what the tenant scope would have hidden from a scoped read anyway.
    expect(DB::table('utterances')->where('interview_session_id', $sessionB->id)->count())
        ->toBe(2, 'the delete must be scoped to the session it was handed');
    expect($orgA)->not->toBeNull();
});
