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

test('answer_duration_seconds for a re-asked question does not span the unrelated time spent on a later question', function (): void {
    $org = Organization::factory()->create();

    $answers = TenantContextScope::runFor($org->id, function () use ($org) {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'reask-duration-'.uniqid(),
            'display_name' => 'Re-ask Duration Fixture',
            'email' => uniqid('reask-duration-').'@example.test',
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

        // Q0 asked, answered in two turns 5s apart (a real, bounded
        // answering span). Then Q1 is asked and answered ~500s later.
        // Then Q0 is RE-ASKED (a resume) and answered once more. The
        // re-ask's own turn must contribute its OWN span to Q0's
        // duration — never the ~500s gap spent on Q1 in between.
        $turns = [
            ['avatar', $primaries[0], 0],
            ['candidate', 'First part of the answer to Q0.', 10],
            ['candidate', 'Second part of the answer to Q0.', 15],
            ['avatar', $primaries[1], 20],
            ['candidate', 'Answer to Q1.', 30],
            ['avatar', $primaries[0], 500],
            ['candidate', 'Additional info for Q0.', 510],
        ];

        foreach ($turns as $i => [$speaker, $text, $offsetSeconds]) {
            $utterance = new Utterance;
            $utterance->forceFill([
                'organization_id' => $org->id,
                'interview_session_id' => $session->id,
                'speaker' => $speaker,
                'text' => $text,
                'ts' => $t0->copy()->addSeconds($offsetSeconds),
            ]);
            $utterance->save();
        }

        return AnswersSerializer::toArray($participant->fresh());
    });

    $first = collect($answers)->firstWhere('question_index', 0);

    // OLD (buggy) behaviour: lastCandidateTs (+510s) minus firstCandidateTs
    // (+10s) = 500s, silently counting the entire gap spent on Q1. The fix
    // sums only each contiguous run's own span: (15-10) + (510-510) = 5s.
    expect(round($first['answer_duration_seconds']))->toBe(5.0);
});

test('answer_duration_seconds for a re-asked question does not span an unanswered later question either', function (): void {
    // The run-detection previously only updated $lastRoutedPosition on
    // CANDIDATE turns. When a later primary is asked and NEVER answered
    // (silence), no candidate turn ever routes through that later
    // bucket, so $lastRoutedPosition is left pointing at the earlier
    // bucket's position the whole time. Re-asking that earlier question
    // then moves $currentPosition away and back to the SAME value with
    // nothing having ever witnessed the excursion, so the stale
    // $lastRoutedPosition coincidentally matches $currentPosition again
    // and the bug silently treats the re-ask's answer as a continuation
    // of the FIRST run — wrongly spanning the entire unanswered gap.
    $org = Organization::factory()->create();

    $answers = TenantContextScope::runFor($org->id, function () use ($org) {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'reask-silence-'.uniqid(),
            'display_name' => 'Re-ask Silence Fixture',
            'email' => uniqid('reask-silence-').'@example.test',
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

        // Q0 asked and answered once (a single, zero-span run). Q1 is
        // then asked but the candidate never answers it (silence — no
        // candidate turn at all). Q0 is later re-asked and answered.
        $turns = [
            ['avatar', $primaries[0], 0],
            ['candidate', 'Answer to Q0.', 10],
            ['avatar', $primaries[1], 20],
            // silence — no candidate turn for Q1
            ['avatar', $primaries[0], 500],
            ['candidate', 'Late addition to Q0.', 510],
        ];

        foreach ($turns as $i => [$speaker, $text, $offsetSeconds]) {
            $utterance = new Utterance;
            $utterance->forceFill([
                'organization_id' => $org->id,
                'interview_session_id' => $session->id,
                'speaker' => $speaker,
                'text' => $text,
                'ts' => $t0->copy()->addSeconds($offsetSeconds),
            ]);
            $utterance->save();
        }

        return AnswersSerializer::toArray($participant->fresh());
    });

    $first = collect($answers)->firstWhere('question_index', 0);

    // Correct behaviour: two separate zero-span runs, (10-10) + (510-510) = 0.
    // The bug this test catches produces 500 instead (510 - 10), silently
    // counting the whole unanswered Q1 gap as part of Q0's own answer time.
    expect($first['answer_duration_seconds'])->toBe(0.0);
});
