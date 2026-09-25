<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews/{id}/transcript` — BEAI Public API (public-api step
 * 6), SPEC.md §3.3. T-INT-017 (read gate), T-INT-018 (shape/ordering, G-37
 * question_index derivation with follow-ups).
 */

use App\Support\PublicApi\PublicId;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-INT-017: transcript answers 409 transcript_not_ready for pending/in_progress/error, and 200 for under_evaluation/completed', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);

    $notReadyStatuses = ['in_attesa', 'in_corso', 'errore'];

    foreach ($notReadyStatuses as $status) {
        $participant = Step6Fixtures::participantWithTranscript($org, $project, $status);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/transcript');

        $response->assertStatus(409)->assertJsonPath('code', 'transcript_not_ready');
        $this->assertProblemMatchesContract($response, 409);
    }

    $readyStatuses = ['in_valutazione', 'completato'];

    foreach ($readyStatuses as $status) {
        $participant = Step6Fixtures::participantWithTranscript($org, $project, $status);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/transcript');

        $response->assertOk();
        $this->assertMatchesContract($response, 'GET', '/interviews/{id}/transcript');
    }
});

test('T-INT-018: transcript shape and ordering — every turn carries the G-37 question_index ordinal, follow-ups included', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/transcript');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}/transcript');

    $body = $response->json();
    expect($body['interview_id'])->toBe(PublicId::encode($participant));
    expect($body['language'])->toBeString();

    $turns = $body['turns'];
    expect($turns)->toHaveCount(8);

    // index is sequential and 0-based across the whole array.
    foreach ($turns as $i => $turn) {
        expect($turn['index'])->toBe($i);
        expect($turn['competency_code'])->toBe('COL');
    }

    // Fixture order: primary(Q0) → candidate → candidate → follow_up →
    // candidate → primary(Q1) → candidate → candidate.
    $expectedQuestionIndex = [0, 0, 0, 0, 0, 1, 1, 1];
    $actualQuestionIndex = array_column($turns, 'question_index');
    expect($actualQuestionIndex)->toBe($expectedQuestionIndex);

    $expectedSpeaker = ['avatar', 'candidate', 'candidate', 'avatar', 'candidate', 'avatar', 'candidate', 'candidate'];
    expect(array_column($turns, 'speaker'))->toBe($expectedSpeaker);

    // ts is ordered ascending.
    $timestamps = array_map(fn (string $ts): int => strtotime($ts), array_column($turns, 'ts'));
    $sorted = $timestamps;
    sort($sorted);
    expect($timestamps)->toBe($sorted);
});
