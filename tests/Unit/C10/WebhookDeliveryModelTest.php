<?php

declare(strict_types=1);

/**
 * RED — 4.1: WebhookDelivery model (C10).
 *
 * Asserts:
 * - WebhookDelivery extends TenantModel (S19) — TenantModelArchTest passes with no
 *   allowlist edit needed, since extending TenantModel is exactly what that arch test
 *   requires of every non-excluded model.
 * - organization_id is NOT in $fillable — prevents payload-manipulation cross-org writes.
 * - Enum casts (event_type, status, skip_reason) round-trip through a DB refresh.
 * - Writing inside App\Support\Tenancy\TenantContextScope::runFor() (design.md D4)
 *   correctly stamps organization_id from the re-derived org, not ambient state.
 * - Writing with NO established tenant context fails closed
 *   (MissingTenantContextException), never a silent null organization_id stamp.
 */

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Enums\WebhookSkipReason;
use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\TenantModel;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContextScope;
use App\Support\Tenancy\TenantResolver;

/**
 * @return array{0: Organization, 1: Participant}
 */
function c10WebhookDeliveryModelFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $participant = Participant::factory()->forProject($project)->create();

    return [$org, $participant];
}

test('WebhookDelivery extends TenantModel', function (): void {
    expect(is_subclass_of(WebhookDelivery::class, TenantModel::class))->toBeTrue();
});

test('organization_id is not fillable on WebhookDelivery', function (): void {
    $model = new WebhookDelivery;

    expect($model->getFillable())->not->toContain('organization_id');
});

test('enum casts round-trip through a DB refresh', function (): void {
    [, $participant] = c10WebhookDeliveryModelFixtures();

    $delivery = WebhookDelivery::factory()
        ->forParticipant($participant)
        ->create([
            'event_type' => WebhookEventType::Progress,
            'status' => WebhookDeliveryStatus::Pending,
            'skip_reason' => null,
        ]);

    $delivery->refresh();

    expect($delivery->event_type)->toBeInstanceOf(WebhookEventType::class)
        ->and($delivery->event_type)->toBe(WebhookEventType::Progress)
        ->and($delivery->status)->toBeInstanceOf(WebhookDeliveryStatus::class)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->skip_reason)->toBeNull();
});

test('nullable skip_reason enum cast round-trips a set value', function (): void {
    [, $participant] = c10WebhookDeliveryModelFixtures();

    $delivery = WebhookDelivery::factory()
        ->forParticipant($participant)
        ->create([
            'status' => WebhookDeliveryStatus::Skipped,
            'skip_reason' => WebhookSkipReason::NoWebhookUrl,
            'target_url' => null,
            'attempt_count' => 0,
        ]);

    $delivery->refresh();

    expect($delivery->skip_reason)->toBeInstanceOf(WebhookSkipReason::class)
        ->and($delivery->skip_reason)->toBe(WebhookSkipReason::NoWebhookUrl);
});

test('payload cast round-trips as an array', function (): void {
    [, $participant] = c10WebhookDeliveryModelFixtures();

    $delivery = WebhookDelivery::factory()
        ->forParticipant($participant)
        ->create(['payload' => ['a' => 1, 'b' => ['c' => 2]]]);

    $delivery->refresh();

    expect($delivery->payload)->toBe(['a' => 1, 'b' => ['c' => 2]]);
});

test('creating inside TenantContextScope::runFor stamps organization_id from the re-derived org', function (): void {
    [$org, $participant] = c10WebhookDeliveryModelFixtures();

    // Simulate the queue-context pattern (design.md D4): no ambient org set, the
    // write happens strictly inside the closure-scoped boundary.
    app(TenantResolver::class)->setOrgId(null);
    app(TenantResolver::class)->setBypass(false);

    $delivery = TenantContextScope::runFor(
        $org->id,
        fn () => WebhookDelivery::factory()->forParticipant($participant)->create()
    );

    expect($delivery->organization_id)->toBe($org->id);

    // The ambient resolver must be restored to its pre-call state (null) afterwards.
    expect(app(TenantResolver::class)->getOrgId())->toBeNull();
});

test('creating with no established tenant context throws MissingTenantContextException', function (): void {
    [, $participant] = c10WebhookDeliveryModelFixtures();

    app(TenantResolver::class)->setOrgId(null);
    app(TenantResolver::class)->setBypass(false);

    expect(fn () => WebhookDelivery::factory()->forParticipant($participant)->create())
        ->toThrow(MissingTenantContextException::class);
});
