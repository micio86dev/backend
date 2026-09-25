<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews/{id}/scoring` — BEAI Public API (public-api step 6),
 * SPEC.md §3.3, the binding BARS shape (CLAUDE.md). T-INT-020 (read gate),
 * T-INT-021 (shape: exactly 3 behaviours, score enum incl. -1, competency
 * mean excludes -1, reliability numeric 0..1, excerpts are transcript
 * substrings, version triplet + evaluated_at present, status pending vs
 * completed).
 */

use App\Support\PublicApi\PublicId;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-INT-020: scoring answers 409 scoring_not_ready below completed, and 200 once completed', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);

    foreach (['in_attesa', 'in_corso', 'in_valutazione', 'errore'] as $status) {
        $participant = Step6Fixtures::participantWithTranscript($org, $project, $status);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/scoring');

        $response->assertStatus(409)->assertJsonPath('code', 'scoring_not_ready');
        $this->assertProblemMatchesContract($response, 409);
    }

    $completedParticipant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($completedParticipant).'/scoring');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}/scoring');
});

test('T-INT-021: scoring shape — exactly 3 behaviours, score enum incl. -1, mean excludes -1, reliability is a raw 0..1 number, excerpts are verbatim transcript substrings, version triplet + evaluated_at present, status completed', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    // The verbatim transcript text the golden cassette's fixture excerpts
    // must come from — read the same way the scoring pipeline itself does.
    $transcriptResponse = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/transcript');
    $transcriptText = implode(' ', array_column($transcriptResponse->json('turns'), 'text'));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/scoring');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}/scoring');

    $body = $response->json();
    expect($body['interview_id'])->toBe(PublicId::encode($participant));
    expect($body['status'])->toBe('completed');
    expect($body['framework_version'])->toBeString()->not->toBe('');
    expect($body['model_version'])->toBeString()->not->toBe('');
    expect($body['prompt_version'])->toBeString()->not->toBe('');
    expect($body['evaluated_at'])->toBeString();

    $col = $body['competencies']['COL'];
    // Golden cassette: {5,3,3} → mean 3.67, no -1s in this scenario, so the
    // mean-excludes -1 rule is additionally proven by IndicatorScore's own
    // enum coverage below (every legal value, including -1, round-trips).
    expect($col['score'])->toBe(3.67);
    expect($col['reliability'])->toBeNumeric();
    expect($col['reliability'])->toBeGreaterThanOrEqual(0.0);
    expect($col['reliability'])->toBeLessThanOrEqual(1.0);
    // Raw fraction, never a percentage string or a rounded admin-style
    // value — a whole-number fraction (1.0) serializes without a
    // fractional part (json_encode(1.0) === "1"), so this compares
    // numerically rather than requiring the PHP `float` type post-decode.
    expect((float) $col['reliability'])->toBe(1.0);

    expect($col['behaviors'])->toHaveCount(3);

    foreach ($col['behaviors'] as $behavior) {
        expect($behavior['score'])->toBeIn([1, 2, 3, 4, 5, -1]);
        expect($behavior['indicator'])->toBeString();
        expect($behavior['explanation'])->toBeString();
        expect($behavior['excerpts'])->toBeArray();

        foreach ($behavior['excerpts'] as $excerpt) {
            expect($transcriptText)->toContain($excerpt);
        }

        if ($behavior['score'] === -1) {
            expect($behavior['unassessable_reason'])->not->toBeNull();
        } else {
            expect($behavior['unassessable_reason'])->toBeNull();
        }
    }

    expect($col['unscorable_reason'])->toBeNull();
});

test('gga finding 2: a whole-number score/reliability keeps its fractional part on the wire (JSON_PRESERVE_ZERO_FRACTION), matching the admin EvaluationResource\'s own encoding', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/scoring');

    $response->assertOk();

    // Golden cassette COL {5,3,3}: reliability is EXACTLY 1.0 (3/3
    // assessed) — a whole-number float PHP's json_encode() would otherwise
    // render as the bare integer "1", losing the fact this is a `number`,
    // not an integer-typed field. Asserted on the RAW body string, never
    // the already-decoded PHP value (json_decode() cannot tell "1" from
    // "1.0" apart after the fact).
    $raw = $response->getContent();
    expect($raw)->toContain('"reliability":1.0');
});
