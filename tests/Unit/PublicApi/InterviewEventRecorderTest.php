<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\InterviewEventRecorder` (public-api step 6, G-38)
 * — unit-level coverage of the recorder itself, separate from the seam
 * integration tests (`T-INT-024`): every typed method writes the right
 * `InterviewEvent` type/data, and a write failure never throws into the
 * caller (this class's own "NEVER THROWS INTO THE CALLER" contract).
 */

use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\InterviewEventRecorder;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Log;

function ierParticipant(Organization $org): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org): Participant {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        $p = new Participant;
        $p->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'ier-'.uniqid(),
            'display_name' => 'Recorder Fixture',
            'email' => uniqid('ier-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
        ]);
        $p->save();

        return $p->fresh();
    });
}

test('every typed method records the correct InterviewEvent type', function (): void {
    $org = Organization::factory()->create();
    $participant = ierParticipant($org);

    InterviewEventRecorder::sessionStarted($org->id, $participant->id);
    InterviewEventRecorder::questionAsked($org->id, $participant->id, 'COL', 0);
    InterviewEventRecorder::answerRecorded($org->id, $participant->id, 'COL', 0);
    InterviewEventRecorder::sessionEnded($org->id, $participant->id);
    InterviewEventRecorder::underEvaluation($org->id, $participant->id);
    InterviewEventRecorder::transcriptReady($org->id, $participant->id);
    InterviewEventRecorder::completed($org->id, $participant->id);
    InterviewEventRecorder::error($org->id, $participant->id);
    InterviewEventRecorder::scoringReady($org->id, $participant->id);

    $events = TenantContextScope::runFor(
        $org->id,
        fn () => InterviewEvent::where('participant_id', $participant->id)->orderBy('id')->get(),
    );

    expect($events->pluck('type')->all())->toBe([
        'session_started', 'question_asked', 'answer_recorded', 'session_ended',
        'under_evaluation', 'transcript_ready', 'completed', 'error', 'scoring_ready',
    ]);

    $questionAsked = $events->firstWhere('type', 'question_asked');
    // toEqual, not toBe: jsonb does not guarantee key order round-trip.
    expect($questionAsked->data)->toEqual(['competency_code' => 'COL', 'question_index' => 0]);

    $sessionStarted = $events->firstWhere('type', 'session_started');
    expect($sessionStarted->data)->toBeNull();
});

test('a write failure is logged and swallowed — never thrown into the caller', function (): void {
    Log::spy();

    // An organization id with no real Organization row — TenantContextScope::
    // runFor() still sets the resolver, but InterviewEvent::create()'s
    // organization_id foreign key fails at the database, exercising the
    // catch(Throwable) branch this class's own docblock documents.
    $bogusOrgId = 999999999;

    InterviewEventRecorder::sessionStarted($bogusOrgId, 1);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'public-api: failed to record interview event'
            && $context['organization_id'] === $bogusOrgId
            && $context['type'] === 'session_started'
        );
});
