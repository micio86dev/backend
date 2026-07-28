<?php

declare(strict_types=1);

/**
 * RED — 8.2/8.3: dedupe + frozen-payload guarantee (C10 design.md D1/D4).
 *
 * The UNIQUE index on (organization_id, project_id, event_type, dedupe_key) is the
 * arbiter for BOTH concerns: a second emission with the same dedupe_key collapses
 * into the SAME row (8.2), and because the second INSERT attempt is caught and
 * discarded rather than applied as an UPDATE, a later re-emission carrying a
 * DIFFERENT payload (e.g. after a re-score) never overwrites what was already
 * recorded (8.3) — mirrors ScoreEvaluationJob.php:171-189's 23505-catch pattern.
 */

use App\Enums\WebhookEventType;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryRecorder;

test('two emissions with identical (organization_id, project_id, event_type, dedupe_key) collapse into exactly one row', function (): void {
    [, $project, $participant] = c10RecorderFixtures();
    $recorder = app(WebhookDeliveryRecorder::class);

    $dedupeKey = 'candidate-created-'.$participant->id;

    $first = $recorder->record(
        $project->id, $participant->id, WebhookEventType::Progress, $dedupeKey,
        fn (string $id): array => ['delivery_id' => $id, 'seq' => 1]
    );
    $second = $recorder->record(
        $project->id, $participant->id, WebhookEventType::Progress, $dedupeKey,
        fn (string $id): array => ['delivery_id' => $id, 'seq' => 2]
    );

    expect($second->id)->toBe($first->id)
        ->and($second->delivery_id)->toBe($first->delivery_id)
        ->and(WebhookDelivery::count())->toBe(1)
        // The loser's payload (seq=2) never won — proves the DB (not app logic) is the
        // arbiter of the race, and the winner's row is untouched by the loser.
        ->and($second->payload['seq'])->toBe(1);
});

test('a delivery row payload is unchanged by a later re-score attempt with a different payload (frozen payload)', function (): void {
    [, $project, $participant] = c10RecorderFixtures();
    $recorder = app(WebhookDeliveryRecorder::class);

    // Per spec, dedupe_key for evaluation events IS the evaluation_id — a re-score
    // reuses the SAME Evaluation row (evaluations.participant_id is globally unique),
    // so the re-emission's dedupe_key is identical to the original's.
    $evaluationDedupeKey = '501';

    // Non-whole scores (realistic CompetencyResult values, e.g. mean of {5,3,3}) —
    // a whole-number float like 3.0 round-trips through jsonb as the bare JSON
    // literal `3`, decoding back as PHP int(3) rather than float(3.0); that PHP/JSON
    // quirk is irrelevant to what this test proves (payload immutability) and would
    // make the assertion fail for a reason that has nothing to do with freezing.
    $original = $recorder->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, $evaluationDedupeKey,
        fn (string $id): array => ['delivery_id' => $id, 'text' => ['PRS' => ['score' => 3.67]]]
    );

    // Simulate a later re-score of the SAME Evaluation producing DIFFERENT scored data.
    $afterRescore = $recorder->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, $evaluationDedupeKey,
        fn (string $id): array => ['delivery_id' => $id, 'text' => ['PRS' => ['score' => 5.33]]]
    );

    expect($afterRescore->id)->toBe($original->id)
        ->and($afterRescore->payload['text']['PRS']['score'])->toBe(3.67)
        ->and(WebhookDelivery::count())->toBe(1);

    // Reload from the DB directly — proves the frozen state was actually persisted,
    // not just held in the in-memory model instance.
    $reloaded = WebhookDelivery::find($original->id);
    expect($reloaded->payload['text']['PRS']['score'])->toBe(3.67);
});
