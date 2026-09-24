<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\SessionTurnReplay` (public-api step 6, G-37) —
 * unit-level coverage of the re-ask branch (`TranscriptTest`/`AnswersTest`
 * only exercise the straight-line, no-re-ask path).
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Support\PublicApi\SessionTurnReplay;
use App\Support\Tenancy\TenantContextScope;

test('a verbatim re-ask of an already-matched primary resolves to that primary\'s own question_index, and never advances the pointer', function (): void {
    $org = Organization::factory()->create();

    $entries = TenantContextScope::runFor($org->id, function () use ($org) {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'reask-'.uniqid(),
            'display_name' => 'Re-ask Fixture',
            'email' => uniqid('reask-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
        ]);
        $participant->save();

        $primaries = ['First question?', 'Second question?'];

        $session = InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => 'COL',
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'fake',
            'status' => 'in_corso',
            'primary_questions' => $primaries,
        ]);

        $t0 = now();
        $turns = [
            ['avatar', $primaries[0]],
            ['candidate', 'My first answer.'],
            // A resume re-asks the SAME primary verbatim — must classify
            // as `primary`, resolve to question_index 0 (not a new 1),
            // and NOT advance the pointer.
            ['avatar', $primaries[0]],
            ['candidate', 'My second attempt at the first answer.'],
            ['avatar', $primaries[1]],
        ];

        foreach ($turns as $i => [$speaker, $text]) {
            $utterance = new Utterance;
            $utterance->forceFill([
                'organization_id' => $org->id,
                'interview_session_id' => $session->id,
                'speaker' => $speaker,
                'text' => $text,
                'ts' => $t0->copy()->addSeconds($i * 10),
            ]);
            $utterance->save();
        }

        return SessionTurnReplay::forSession($session->fresh());
    });

    $questionIndexes = array_column($entries, 'question_index');
    $advances = array_column($entries, 'advances_primary');

    expect($questionIndexes)->toBe([0, 0, 0, 0, 1]);
    expect($advances)->toBe([true, false, false, false, true]);
});
