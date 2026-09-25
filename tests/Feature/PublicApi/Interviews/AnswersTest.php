<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews/{id}/answers` — BEAI Public API (public-api step 6),
 * SPEC.md §3.3. T-INT-019: answers derivation (two questions, a follow-up
 * turn attached to the right question, durations from timestamps).
 */

use App\Support\PublicApi\PublicId;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-INT-019: answers group the transcript by question, a follow-up stays attached to its question, and durations are derived from timestamps', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/answers');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}/answers');

    $body = $response->json();
    expect($body['interview_id'])->toBe(PublicId::encode($participant));

    $answers = $body['answers'];
    expect($answers)->toHaveCount(2);

    // Question 0: primary at +0s, candidate turns at +20s/+35s, a
    // follow_up avatar turn at +50s (contributes no text), then a THIRD
    // candidate turn at +65s that belongs to the SAME question (the
    // follow-up never opens a new answer).
    expect($answers[0]['competency_code'])->toBe('COL');
    expect($answers[0]['question_index'])->toBe(0);
    expect($answers[0]['question_text'])->toBe('Describe a time you collaborated with a colleague.');
    expect($answers[0]['answer_text'])->toBe(
        'I worked closely with a colleague on a cross-team project. '
        .'We agreed on shared goals early, which made the collaboration smooth. '
        .'The project shipped on time and both teams were satisfied.'
    );
    expect($answers[0]['started_at_seconds'])->toBeNumeric();
    // Question started at +0s from the fixture's own t0; participant
    // started_at is 1 minute (60s) before t0.
    expect(round($answers[0]['started_at_seconds']))->toBe(60.0);
    // Candidate turns for question 0 span +20s..+65s → 45s.
    expect(round($answers[0]['answer_duration_seconds']))->toBe(45.0);

    // gga finding 2: a whole-number offset keeps its fractional part on the
    // wire (JSON_PRESERVE_ZERO_FRACTION) — asserted on the RAW body string,
    // never the already-decoded PHP value.
    $raw = $response->getContent();
    expect($raw)->toContain('"started_at_seconds":60.0');
    expect($raw)->toContain('"answer_duration_seconds":45.0');

    // Question 1: primary at +90s, candidate turns at +110s/+125s.
    expect($answers[1]['competency_code'])->toBe('COL');
    expect($answers[1]['question_index'])->toBe(1);
    expect($answers[1]['question_text'])->toBe('How do you handle disagreement within a team?');
    expect($answers[1]['answer_text'])->toBe(
        'I try to understand the other perspective before responding. '
        .'Usually we find a compromise that keeps the project moving.'
    );
    expect(round($answers[1]['started_at_seconds']))->toBe(150.0);
    expect(round($answers[1]['answer_duration_seconds']))->toBe(15.0);
});

test('T-INT-019: answers gate mirrors the transcript gate — 409 transcript_not_ready below under_evaluation', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/answers');

    $response->assertStatus(409)->assertJsonPath('code', 'transcript_not_ready');
    $this->assertProblemMatchesContract($response, 409);
});
