<?php

declare(strict_types=1);

/**
 * `App\PublicApi\Serializers\AnswersSerializer` — step 6 review follow-up,
 * finding 6: bucket selection for candidate speech following a re-asked
 * PRIMARY question.
 *
 * `SessionTurnReplay` already resolves a re-ask's own `question_index` to
 * the EARLIER primary it re-asks (`SessionTurnReplayTest` covers that
 * directly). The gap this file covers is different: when the re-ask
 * targets an EARLIER primary than the one most recently matched (not
 * simply the last one, which `SessionTurnReplayTest`'s own fixture
 * happens to also be), the candidate speech that follows must land in the
 * bucket for that EARLIER question, not silently append to whichever
 * bucket the LATEST advancing primary opened.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\PublicApi\Serializers\AnswersSerializer;
use App\Support\Tenancy\TenantContextScope;

test('candidate speech after a re-ask of an EARLIER (not the latest) primary lands in that earlier question\'s bucket', function (): void {
    $org = Organization::factory()->create();

    $answers = TenantContextScope::runFor($org->id, function () use ($org) {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'reask-bucket-'.uniqid(),
            'display_name' => 'Re-ask Bucket Fixture',
            'email' => uniqid('reask-bucket-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
            'started_at' => now()->subMinutes(10),
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
            ['candidate', 'Answer to first.'],
            ['avatar', $primaries[1]],
            ['candidate', 'Answer to second.'],
            // A re-ask of the FIRST primary — not the most recently
            // matched one (the second) — e.g. a resume that replays an
            // earlier question. Must classify `primary`, resolve to
            // question_index 0, and NOT advance the pointer.
            ['avatar', $primaries[0]],
            ['candidate', 'Actually, better answer to first.'],
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

        return AnswersSerializer::toArray($participant->fresh());
    });

    expect($answers)->toHaveCount(2);

    $first = collect($answers)->firstWhere('question_index', 0);
    $second = collect($answers)->firstWhere('question_index', 1);

    expect($first['answer_text'])->toBe('Answer to first. Actually, better answer to first.');
    expect($second['answer_text'])->toBe('Answer to second.');
});
