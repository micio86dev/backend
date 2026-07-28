<?php

declare(strict_types=1);

/**
 * RED — 10.8: SendEvaluationWebhook listener (C10, design.md D4 listener isolation).
 *
 * Hangs off the EXISTING EvaluationCompleted (api/app/Events/EvaluationCompleted.php,
 * fired at ScoreEvaluationJob.php:482) and EvaluationFailed (fired at :750) events —
 * auto-discovered exactly like DispatchScoringJob (api/app/Listeners/DispatchScoringJob.php
 * + api/app/Providers/EventServiceProvider.php's empty $listen array), no second
 * registration pattern invented.
 *
 * Plain listener (NOT ShouldQueue) — runs synchronously inside whatever dispatched
 * the event. A forced exception inside the recorder/assembler MUST be caught and
 * MUST NOT propagate back into the caller (ScoreEvaluationJob in production) — a
 * webhook-recording failure must never flip a successfully-scored participant to
 * 'errore'.
 */

use App\Enums\WebhookDeliveryStatus;
use App\Events\EvaluationCompleted;
use App\Events\EvaluationFailed;
use App\Jobs\DeliverWebhookJob;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10ListenerFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_listener_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    $participant = Participant::factory()->forProject($project)->create();

    return [$org, $project, $participant];
}

test('EvaluationCompleted (status=completed) records a delivery and dispatches DeliverWebhookJob synchronously', function (): void {
    Queue::fake();

    [, , $participant] = c10ListenerFixtures();
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);

    event(new EvaluationCompleted($evaluation->id));

    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->dedupe_key)->toBe((string) $evaluation->id);

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('EvaluationCompleted with status=pending still produces a delivered (dispatch-ready) webhook — spec scenario', function (): void {
    Queue::fake();

    [, , $participant] = c10ListenerFixtures();
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'pending',
        'evaluated_at' => now(),
    ]);

    event(new EvaluationCompleted($evaluation->id));

    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->payload['data']['status'])->toBe('pending');

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('EvaluationFailed records a delivery from the terminal participant state', function (): void {
    Queue::fake();

    [, , $participant] = c10ListenerFixtures();
    $participant->forceFill(['status' => 'errore'])->save();

    event(new EvaluationFailed($participant->id));

    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->payload['data']['status'])->toBe('errore')
        ->and($delivery->payload['data']['text'])->toBe([]);

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('a forced exception inside the recorder is caught and never propagates back to the caller', function (): void {
    Queue::fake();

    [, , $participant] = c10ListenerFixtures();

    // No Evaluation row exists for this id at all — the assembler/recorder chain
    // will throw (ModelNotFoundException resolving the evaluation) deep inside the
    // listener. The event() call itself MUST NOT throw past this point — mirroring
    // exactly what ScoreEvaluationJob (the real caller in production) requires.
    $nonExistentEvaluationId = 999999999;

    expect(fn () => event(new EvaluationCompleted($nonExistentEvaluationId)))->not->toThrow(Throwable::class);

    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('skipped gate outcome (no_webhook_url) dispatches nothing', function (): void {
    Queue::fake();

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['webhook_url' => null, 'webhook_secret' => null]);
    $participant = Participant::factory()->forProject($project)->create();

    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participant->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);

    event(new EvaluationCompleted($evaluation->id));

    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Skipped);

    Queue::assertNothingPushed();
});
