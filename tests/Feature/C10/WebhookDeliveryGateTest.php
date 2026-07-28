<?php

declare(strict_types=1);

/**
 * RED — 8.1: WebhookDeliveryRecorder's 4-branch ordered decision gate (Δ1) —
 * C10 design.md D2/D10.
 *
 * Order: webhook_url null → webhook_secret null → event_type_disabled → pending.
 * Every `skipped` variant is terminal, never signed, no HTTP call is made — asserted
 * via Http::assertNothingSent(). The recorder itself never makes an HTTP call in ANY
 * branch (including `pending`): signing and sending are DeliverWebhookJob's
 * responsibility (PR5, out of this PR's scope), so `Http::assertNothingSent()` holds
 * for the `pending` case too — it is not a discriminator between skip and non-skip
 * here, but it does prove the recorder has no accidental side-channel HTTP call.
 */

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Enums\WebhookSkipReason;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDeliveryRecorder;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $projectAttrs
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10RecorderFixtures(array $projectAttrs = []): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(array_merge([
        'webhook_url' => 'https://receiver.example.test/hook',
        'webhook_secret' => 'whsec_test_secret_value',
        'webhook_events' => ['progress', 'evaluation'],
    ], $projectAttrs));

    $participant = Participant::factory()->forProject($project)->create();

    return [$org, $project, $participant];
}

/**
 * @return Closure(string): array<string, mixed>
 */
function c10StubPayload(): Closure
{
    return fn (string $deliveryId): array => ['delivery_id' => $deliveryId, 'stub' => true];
}

beforeEach(function (): void {
    Http::fake();
});

test('null webhook_url produces a terminal skipped row (no_webhook_url)', function (): void {
    [, $project, $participant] = c10RecorderFixtures(['webhook_url' => null, 'webhook_secret' => null]);

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, 'dedupe-no-url', c10StubPayload()
    );

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Skipped)
        ->and($delivery->skip_reason)->toBe(WebhookSkipReason::NoWebhookUrl)
        ->and($delivery->target_url)->toBeNull()
        ->and($delivery->attempt_count)->toBe(0);

    Http::assertNothingSent();
});

test('webhook_url set but webhook_secret null produces a terminal skipped row (no_webhook_secret)', function (): void {
    [, $project, $participant] = c10RecorderFixtures(['webhook_secret' => null]);

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, 'dedupe-no-secret', c10StubPayload()
    );

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Skipped)
        ->and($delivery->skip_reason)->toBe(WebhookSkipReason::NoWebhookSecret)
        ->and($delivery->target_url)->toBe($project->webhook_url);

    Http::assertNothingSent();
});

test('event type not enabled produces a terminal skipped row (event_type_disabled)', function (): void {
    [, $project, $participant] = c10RecorderFixtures(['webhook_events' => ['evaluation']]);

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Progress, 'dedupe-disabled', c10StubPayload()
    );

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Skipped)
        ->and($delivery->skip_reason)->toBe(WebhookSkipReason::EventTypeDisabled)
        ->and($delivery->target_url)->toBe($project->webhook_url);

    Http::assertNothingSent();
});

test('configured and enabled produces a pending row, dispatch-ready', function (): void {
    [, $project, $participant] = c10RecorderFixtures();

    $delivery = app(WebhookDeliveryRecorder::class)->record(
        $project->id, $participant->id, WebhookEventType::Evaluation, 'dedupe-pending', c10StubPayload()
    );

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->skip_reason)->toBeNull()
        ->and($delivery->target_url)->toBe($project->webhook_url)
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->max_attempts)->toBe((int) config('webhooks.delivery.max_attempts'));

    Http::assertNothingSent();
});

test('the three skip reasons remain distinguishable when queried side by side', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $noUrlProject = Project::factory()->create(['webhook_url' => null, 'webhook_secret' => null]);
    $noSecretProject = Project::factory()->create(['webhook_url' => 'https://a.test/hook', 'webhook_secret' => null]);
    $disabledProject = Project::factory()->create([
        'webhook_url' => 'https://b.test/hook',
        'webhook_secret' => 'secret',
        'webhook_events' => ['evaluation'],
    ]);

    $recorder = app(WebhookDeliveryRecorder::class);

    $noUrlDelivery = $recorder->record(
        $noUrlProject->id,
        Participant::factory()->forProject($noUrlProject)->create()->id,
        WebhookEventType::Progress,
        'k1',
        c10StubPayload()
    );
    $noSecretDelivery = $recorder->record(
        $noSecretProject->id,
        Participant::factory()->forProject($noSecretProject)->create()->id,
        WebhookEventType::Progress,
        'k2',
        c10StubPayload()
    );
    $disabledDelivery = $recorder->record(
        $disabledProject->id,
        Participant::factory()->forProject($disabledProject)->create()->id,
        WebhookEventType::Progress,
        'k3',
        c10StubPayload()
    );

    $skipReasons = [
        $noUrlDelivery->skip_reason,
        $noSecretDelivery->skip_reason,
        $disabledDelivery->skip_reason,
    ];

    expect($skipReasons)->toBe([
        WebhookSkipReason::NoWebhookUrl,
        WebhookSkipReason::NoWebhookSecret,
        WebhookSkipReason::EventTypeDisabled,
    ]);
    expect(array_unique(array_map(fn ($r) => $r->value, $skipReasons)))->toHaveCount(3);
    expect(WebhookDelivery::count())->toBe(3);
});
