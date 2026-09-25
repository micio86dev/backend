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

    // organizationId threaded through exactly the way ScoreEvaluationJob::failed()
    // now does (pre-commit gate, round 5, finding 2) — the real trusted source.
    event(new EvaluationFailed($participant->id, $participant->organization_id));

    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->payload['data']['status'])->toBe('errore')
        ->and($delivery->payload['data']['text'])->toBe([]);

    Queue::assertPushed(DeliverWebhookJob::class);
});

// ─── Org-scoped reads (pre-commit gate, round 5, findings 1 and 2) ────────
//
// Both handleCompleted() and handleFailed() previously resolved their
// Participant row UNSCOPED (or — for handleFailed() — org-scoped against an
// organization_id that came from that SAME unscoped read, a circular check
// that could never fail). Both fixtures below construct a genuine
// evaluation/participant-organization mismatch via direct model
// manipulation — proving the org-scoping actually rejects it instead of
// merely looking like a guard.

test('EvaluationCompleted whose Evaluation.organization_id disagrees with its participant\'s real organization does not deliver a webhook', function (): void {
    Queue::fake();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $resolver = app(TenantResolver::class);

    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);
    $projectB = Project::factory()->create([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_listener_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    $participantOfOrgB = Participant::factory()->forProject($projectB)->create();

    // Evaluation.organization_id is stamped from the ACTIVE resolver at
    // creation time (EvaluationFactory's own docblock) — independent of
    // participant_id, which still points at org B's participant. This is
    // exactly the corrupt-data shape the finding describes: the two
    // organization ids disagree.
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    $evaluation = Evaluation::factory()->create([
        'participant_id' => $participantOfOrgB->id,
        'status' => 'completed',
        'evaluated_at' => now(),
    ]);
    expect($evaluation->organization_id)->toBe($orgA->id);

    event(new EvaluationCompleted($evaluation->id));

    // Participant::where('organization_id', $evaluation->organization_id)
    // ->findOrFail(...) must fail to resolve org B's participant under org
    // A — caught by the listener's own outer try/catch, exactly like the
    // existing "forced exception" test above.
    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('EvaluationFailed whose event organizationId disagrees with the participant\'s real organization does not deliver a webhook', function (): void {
    Queue::fake();

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);

    $projectB = Project::factory()->create([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_listener_test_secret',
        'webhook_events' => ['progress', 'evaluation'],
    ]);
    $participantOfOrgB = Participant::factory()->forProject($projectB)->create();

    // The event's own organizationId claims org A — a source independent of
    // (and disagreeing with) the participant row's real organization_id.
    // Proves the check can actually fail, not just tautologically pass.
    event(new EvaluationFailed($participantOfOrgB->id, $orgA->id));

    // App\Listeners\NotifyOnScoringFailure is a SEPARATE auto-discovered
    // EvaluationFailed listener (C12) — it dispatches SendOperatorNotificationJob
    // unconditionally and is unrelated to this fix, so only DeliverWebhookJob is
    // asserted here, not "nothing pushed at all".
    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNotPushed(DeliverWebhookJob::class);
});

test('EvaluationFailed with no organizationId (ScoreEvaluationJob could not derive one) does not deliver a webhook and does not throw past the listener', function (): void {
    Queue::fake();

    [, , $participant] = c10ListenerFixtures();
    $participant->forceFill(['status' => 'errore'])->save();

    // Mirrors ScoreEvaluationJob::failed()'s own genuine "cannot derive
    // organization context" branch — no trustworthy org is threaded at all.
    expect(fn () => event(new EvaluationFailed($participant->id)))->not->toThrow(Throwable::class);

    expect(WebhookDelivery::count())->toBe(0);
    Queue::assertNotPushed(DeliverWebhookJob::class);
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
