<?php

declare(strict_types=1);

/**
 * `GET /v1/webhooks/deliveries` — BEAI Public API (public-api step 7),
 * SPEC.md §3.6 "Delivery log". T-WHD-001..007: the new public list/filter
 * surface over the EXISTING C10 `webhook_deliveries` log.
 *
 * The original spec's `T-WH-*` numbering (signature format, retry
 * schedule, auto-disable) is ALREADY covered by the existing C10 test
 * suite (`tests/Feature/C10/*`, `tests/Unit/C10/*`) — none of that is
 * re-tested here. `T-WHD-*` numbers only the genuinely NEW read/redeliver
 * surface this step adds.
 */

use App\Enums\ApiKeyMode;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-WHD-001: lists webhook deliveries for the caller\'s organization, newest first, contract-valid', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $deliveries = TenantContextScope::runFor($org->id, function () use ($participant): array {
        $older = WebhookDelivery::factory()->forParticipant($participant)->create([
            'status' => WebhookDeliveryStatus::Delivered,
            'delivered_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
        ]);
        $newer = WebhookDelivery::factory()->forParticipant($participant)->create([
            'status' => WebhookDeliveryStatus::Dead,
            'created_at' => now(),
        ]);

        return [$older, $newer];
    });
    [$older, $newer] = $deliveries;

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/webhooks/deliveries');

    $body = $response->json();
    expect($body['data'])->toHaveCount(2);
    expect($body['data'][0]['id'])->toBe(WebhookDeliveryId::encode($newer->fresh()));
    expect($body['data'][1]['id'])->toBe(WebhookDeliveryId::encode($older->fresh()));

    expect($body['data'][0]['interview_id'])->toBe(PublicId::encode($participant));
    expect($body['data'][0]['project_id'])->toBe(PublicId::encode($project));
    expect($body['data'][0]['candidate_ref'])->toBe($participant->candidate_ref);
    expect($body['data'][0])->not->toHaveKey('payload');
    expect($body['data'][0])->not->toHaveKey('dedupe_key');
    expect($body['data'][0])->not->toHaveKey('skip_reason');
    expect($body['data'][0])->not->toHaveKey('last_error');
});

test('T-WHD-002: filters by status', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $dead = TenantContextScope::runFor($org->id, function () use ($participant): WebhookDelivery {
        WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now()]);

        return WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Dead]);
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries?status=dead');

    $response->assertOk();
    $body = $response->json();
    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['id'])->toBe(WebhookDeliveryId::encode($dead->fresh()));
});

test('T-WHD-003: filters by event_type', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $progress = TenantContextScope::runFor($org->id, function () use ($participant): WebhookDelivery {
        WebhookDelivery::factory()->forParticipant($participant)->create(['event_type' => WebhookEventType::Evaluation]);

        return WebhookDelivery::factory()->forParticipant($participant)->create(['event_type' => WebhookEventType::Progress]);
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries?event_type=progress');

    $response->assertOk();
    $body = $response->json();
    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['id'])->toBe(WebhookDeliveryId::encode($progress->fresh()));
});

test('T-WHD-004: filters by interview_id', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);
    $project = Step6Fixtures::project($org);
    $participantA = Step6Fixtures::participantWithTranscript($org, $project, 'completato');
    $participantB = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $forA = TenantContextScope::runFor($org->id, function () use ($participantA, $participantB): WebhookDelivery {
        WebhookDelivery::factory()->forParticipant($participantB)->create();

        return WebhookDelivery::factory()->forParticipant($participantA)->create();
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries?interview_id='.PublicId::encode($participantA));

    $response->assertOk();
    $body = $response->json();
    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['id'])->toBe(WebhookDeliveryId::encode($forA->fresh()));
});

test('T-WHD-005: an unrecognised status or event_type filter answers 400 validation_failed, never 422', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);

    $badStatus = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries?status=bogus');
    $badStatus->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    $this->assertProblemMatchesContract($badStatus, 400);

    $badEvent = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries?event_type=bogus');
    $badEvent->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

test('T-WHD-006: tenant isolation — each organization sees only its own deliveries', function (): void {
    ['org' => $orgA, 'key' => $keyA] = Step6Fixtures::orgWithScopedKey(['webhooks:read']);
    $projectA = Step6Fixtures::project($orgA);
    $participantA = Step6Fixtures::participantWithTranscript($orgA, $projectA, 'completato');

    $orgB = Organization::factory()->create();
    $projectB = Step6Fixtures::project($orgB);
    $participantB = Step6Fixtures::participantWithTranscript($orgB, $projectB, 'completato');

    TenantContextScope::runFor($orgA->id, fn () => WebhookDelivery::factory()->forParticipant($participantA)->create());
    TenantContextScope::runFor($orgB->id, fn () => WebhookDelivery::factory()->forParticipant($participantB)->create());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])
        ->getJson('/api/v1/webhooks/deliveries');

    $response->assertOk();
    $body = $response->json();
    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['project_id'])->toBe(PublicId::encode($projectA));
});

test('T-WHD-007: missing webhooks:read scope answers 403 insufficient_scope', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/webhooks/deliveries');

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
    $this->assertProblemMatchesContract($response, 403);
});
