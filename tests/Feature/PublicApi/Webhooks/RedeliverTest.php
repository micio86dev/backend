<?php

declare(strict_types=1);

/**
 * `POST /v1/webhooks/deliveries/{id}/redeliver` — BEAI Public API
 * (public-api step 7), SPEC.md §3.6 "Redeliver". T-WHD-008..015.
 */

use App\Enums\ApiKeyMode;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookSkipReason;
use App\Jobs\DeliverWebhookJob;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-WHD-008: a pending delivery cannot be redelivered — 409 invalid_state', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor(
        $org->id,
        fn () => WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Pending]),
    );

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(409)->assertJsonPath('code', 'invalid_state');
    $this->assertProblemMatchesContract($response, 409);
    Queue::assertNotPushed(DeliverWebhookJob::class);
});

test('T-WHD-008b: a skipped delivery cannot be redelivered — 409 invalid_state', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Skipped,
        'skip_reason' => WebhookSkipReason::NoWebhookUrl,
        'target_url' => null,
        'attempt_count' => 0,
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(409)->assertJsonPath('code', 'invalid_state');
    Queue::assertNotPushed(DeliverWebhookJob::class);
});

test('T-WHD-009: a delivered delivery redelivers — 202, DeliverWebhookJob dispatched, attempt_count preserved, delivered_at cleared', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Delivered,
        'delivered_at' => now()->subMinutes(5),
        'attempt_count' => 2,
        'last_response_status' => 200,
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(202);
    $this->assertMatchesContract($response, 'POST', '/webhooks/deliveries/{id}/redeliver');

    $body = $response->json();
    expect($body['status'])->toBe('pending');
    expect($body['attempt_count'])->toBe(2);
    expect($body['delivered_at'])->toBeNull();

    Queue::assertPushed(DeliverWebhookJob::class);

    $fresh = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::find($delivery->id));
    expect($fresh->status)->toBe(WebhookDeliveryStatus::Pending);
    expect($fresh->delivered_at)->toBeNull();
    expect($fresh->attempt_count)->toBe(2);
});

test('T-WHD-010: a failed_permanent delivery redelivers — 202', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::FailedPermanent,
        'last_response_status' => 422,
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(202)->assertJsonPath('status', 'pending');
    Queue::assertPushed(DeliverWebhookJob::class);
});

test('T-WHD-011: a dead delivery redelivers — 202', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor(
        $org->id,
        fn () => WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Dead]),
    );

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(202)->assertJsonPath('status', 'pending');
    Queue::assertPushed(DeliverWebhookJob::class);
});

test('T-WHD-012: redelivering another organization\'s delivery answers 404, never 409', function (): void {
    Queue::fake();

    ['org' => $orgA] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $projectA = Step6Fixtures::project($orgA);
    $participantA = Step6Fixtures::participantWithTranscript($orgA, $projectA, 'completato');

    $deliveryA = TenantContextScope::runFor(
        $orgA->id,
        fn () => WebhookDelivery::factory()->forParticipant($participantA)->create(['status' => WebhookDeliveryStatus::Dead]),
    );

    ['key' => $keyB] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyB])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($deliveryA).'/redeliver');

    $response->assertStatus(404);
    Queue::assertNotPushed(DeliverWebhookJob::class);
});

test('T-WHD-013: missing webhooks:write scope answers 403 insufficient_scope', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/whd_0197a6d2-0000-7000-8000-000000000000/redeliver');

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
});

test('T-WHD-014: an unknown (but well-formed) delivery id answers 404', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/whd_0197a6d2-0000-7000-8000-000000000000/redeliver');

    $response->assertStatus(404);
});

test('T-WHD-015: a malformed delivery id (wrong prefix / not a UUID) answers 404, never 400', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);

    $wrongPrefix = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/prj_01ARZ3NDEKTSV4RRFFQ69G5FAV/redeliver');
    $wrongPrefix->assertStatus(404);

    $notUuid = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/whd_not-a-uuid/redeliver');
    $notUuid->assertStatus(404);
});

// ─── gga pre-commit follow-up (finding 1): attempt_count continuation ───────

test('T-WHD-016: redelivering a dead delivery continues attempt_count from its pre-redeliver value, never resets it to 1', function (): void {
    // Runs the job for REAL (sync queue driver, no Queue::fake()) against a
    // faked HTTP receiver — DeliverWebhookJob::handle() writes
    // 'attempt_count' => $this->attempts(), which is the FRESH job
    // dispatch's own counter (starts at 1) and would silently overwrite a
    // dead row's real lifetime count and grant it a full new max_attempts
    // budget unless the controller passes the row's pre-redeliver
    // attempt_count through as an offset.
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);

    $targetUrl = 'https://client.example.com/inbound-webhook';

    $project = TenantContextScope::runFor($org->id, fn () => Project::factory()->create([
        'organization_id' => $org->id,
        'webhook_url' => $targetUrl,
        'webhook_secret' => 'a-real-secret',
    ]));
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Dead,
        'target_url' => $targetUrl,
        'attempt_count' => 5,
        'max_attempts' => 6,
    ]));

    Http::fake([$targetUrl => Http::response('', 200)]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(202);

    Http::assertSentCount(1);

    $fresh = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::find($delivery->id));
    expect($fresh->status)->toBe(WebhookDeliveryStatus::Delivered);
    expect($fresh->attempt_count)->toBe(6);
    expect($fresh->delivered_at)->not->toBeNull();
});

// ─── gga pre-commit follow-up (finding 2): soft-deleted project ────────────

test('T-WHD-017: redelivering a delivery whose project was later soft-deleted still succeeds', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Dead,
    ]));

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    Queue::fake();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.WebhookDeliveryId::encode($delivery).'/redeliver');

    $response->assertStatus(202);
    $body = $response->json();
    expect($body['project_id'])->toBe(PublicId::encode($project));
    Queue::assertPushed(DeliverWebhookJob::class);
});

// ─── gga pre-commit follow-up (finding 3): check-then-write race ──────────

test('T-WHD-018: the conditional redeliver UPDATE is atomic — a second attempt against an already-transitioned row affects zero rows', function (): void {
    // True process-level concurrency cannot be produced inside one PHP test
    // process (same honest limitation `Tests\Feature\C6\ConcurrentUpsertTest`
    // documents for its own "direct DB test"). This proves the mechanism
    // the fix relies on directly: TWO requests that both observed the SAME
    // pre-race 'dead' status can each only win the conditional UPDATE once,
    // because the SECOND invocation's own WHERE clause is re-evaluated
    // against whatever the FIRST already committed — never a blind
    // unconditional write.
    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Dead,
    ]));

    $conditionalUpdate = fn () => WebhookDelivery::query()
        ->where('id', $delivery->id)
        ->where('organization_id', $org->id)
        ->whereIn('status', [WebhookDeliveryStatus::Delivered, WebhookDeliveryStatus::FailedPermanent, WebhookDeliveryStatus::Dead])
        ->update(['status' => WebhookDeliveryStatus::Pending->value, 'delivered_at' => null, 'next_attempt_at' => now()]);

    $firstAffected = TenantContextScope::runFor($org->id, $conditionalUpdate);
    $secondAffected = TenantContextScope::runFor($org->id, $conditionalUpdate);

    expect($firstAffected)->toBe(1);
    expect($secondAffected)->toBe(0);
});

test('T-WHD-019: two redeliver requests against the same delivery result in exactly one dispatched job', function (): void {
    // Sequential HTTP calls (this codebase's own established "simulates a
    // second [request] sent" convention — see ConcurrentUpsertTest) proving
    // the end-to-end contract: the SECOND call, whose own resolveDelivery()
    // read observes the row AFTER the first's atomic UPDATE already
    // transitioned it, is rejected before ever reaching dispatch().
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['webhooks:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');

    $delivery = TenantContextScope::runFor($org->id, fn () => WebhookDelivery::factory()->forParticipant($participant)->create([
        'status' => WebhookDeliveryStatus::Dead,
    ]));

    $encodedId = WebhookDeliveryId::encode($delivery);

    $first = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.$encodedId.'/redeliver');
    $second = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/webhooks/deliveries/'.$encodedId.'/redeliver');

    $first->assertStatus(202);
    $second->assertStatus(409)->assertJsonPath('code', 'invalid_state');

    Queue::assertPushed(DeliverWebhookJob::class, 1);
});
