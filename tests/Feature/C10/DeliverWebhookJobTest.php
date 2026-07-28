<?php

declare(strict_types=1);

/**
 * RED — 10.2-10.6: DeliverWebhookJob delivery state machine (C10, design.md D2/D3).
 *
 * `attempts()` under the `sync` driver (this repo's CI/local default —
 * phpunit.xml:51, ci.yml:45) is HARDCODED to 1 forever
 * (`vendor/laravel/framework/.../Jobs/SyncJob.php:56-59`) and `release()` never
 * actually re-invokes the job (`Job::release()` just sets a flag —
 * `vendor/laravel/framework/.../Jobs/Job.php:131-134`). So a genuine multi-attempt
 * sequence (retry #2, exhaustion at #6) can never be produced by dispatching under
 * `sync` and waiting — nothing re-delivers automatically. Tests that need attempt
 * N > 1 construct a fresh `DeliverWebhookJob` and inject a Mockery double of
 * `Illuminate\Contracts\Queue\Job` via the public `setJob()` from
 * `InteractsWithQueue`, with `attempts()` stubbed to the specific N being simulated —
 * this exercises the EXACT same code path a real `database`-driver worker would on
 * its Nth redelivery, without any wall-clock wait. The one test that must prove the
 * REAL `sync` no-op behavior (10.4) deliberately does NOT mock anything and instead
 * dispatches for real under the ambient `sync` connection.
 */

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhookJob;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\RetryClassifier;
use App\Services\Webhooks\SecretRedactor;
use App\Services\Webhooks\WebhookDeliveryRecorder;
use App\Services\Webhooks\WebhookSigner;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @return array{0: Organization, 1: Project, 2: Participant, 3: WebhookDelivery}
 */
function c10PendingDelivery(?string $webhookSecret = null): array
{
    [$org, $project, $participant] = c10RecorderFixtures(
        $webhookSecret !== null ? ['webhook_secret' => $webhookSecret] : []
    );

    $recorder = app(WebhookDeliveryRecorder::class);
    $delivery = $recorder->record(
        $project->id,
        $participant->id,
        WebhookEventType::Evaluation,
        (string) Str::uuid(),
        fn (string $id): array => [
            'version' => '1.0',
            'event' => 'evaluation',
            'delivery_id' => $id,
            'candidate_ref' => $participant->candidate_ref,
            'data' => ['status' => 'completed'],
        ]
    );

    return [$org, $project, $participant, $delivery];
}

/**
 * Build a fresh DeliverWebhookJob with the underlying queue Job contract mocked so
 * attempts() reports exactly $attempts — simulating one specific real worker
 * attempt (see class doc above for why this is necessary rather than looping a real
 * dispatch under `sync`).
 */
function c10JobAtAttempt(int $deliveryId, int $attempts): DeliverWebhookJob
{
    $job = new DeliverWebhookJob($deliveryId);

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);
    // Loosely allowed here — tests that need to assert release() call count/args
    // build their own mock explicitly (see the exhaustion and non-retryable tests).
    $queueJob->shouldReceive('release')->zeroOrMoreTimes();

    $job->setJob($queueJob);

    return $job;
}

function c10InvokeHandle(DeliverWebhookJob $job): void
{
    $job->handle(app(WebhookSigner::class), app(SecretRedactor::class), app(RetryClassifier::class));
}

test('non-retryable 4xx produces failed_permanent after attempt_count=1, no release call', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    Http::fake([$delivery->target_url => Http::response(['error' => 'bad request'], 404)]);

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->never();

    $job = new DeliverWebhookJob($delivery->id);
    $job->setJob($queueJob);
    c10InvokeHandle($job);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::FailedPermanent)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_response_status)->toBe(404)
        ->and($delivery->delivered_at)->toBeNull();
});

test('retryable 503 exhausts to dead at attempt_count=6, never delivered', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    Http::fake([$delivery->target_url => Http::response(['error' => 'unavailable'], 503)]);

    // Simulate 6 real worker attempts — each a fresh job instance at attempts()=N,
    // exactly as a `database`-driver worker would redeliver after each release().
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);

        if ($attempt < 6) {
            $queueJob->shouldReceive('release')->once()->with(Mockery::type('int'));
        } else {
            $queueJob->shouldReceive('release')->never();
        }

        $job = new DeliverWebhookJob($delivery->id);
        $job->setJob($queueJob);
        c10InvokeHandle($job);

        $delivery->refresh();

        if ($attempt < 6) {
            expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
                ->and($delivery->attempt_count)->toBe($attempt)
                ->and($delivery->next_attempt_at)->not->toBeNull();
        }
    }

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Dead)
        ->and($delivery->attempt_count)->toBe(6)
        ->and($delivery->delivered_at)->toBeNull();
});

test('success after one retryable failure produces delivered, attempt_count=2', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    $callCount = 0;
    Http::fake(function () use (&$callCount) {
        $callCount++;

        return $callCount === 1
            ? Http::response(['error' => 'unavailable'], 503)
            : Http::response(['ok' => true], 200);
    });

    $attempt1 = c10JobAtAttempt($delivery->id, 1);
    c10InvokeHandle($attempt1);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1);

    $attempt2 = c10JobAtAttempt($delivery->id, 2);
    c10InvokeHandle($attempt2);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempt_count)->toBe(2)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->last_response_status)->toBe(200);
});

test('delivery_id is byte-identical across attempts; X-BEAI-Timestamp and X-BEAI-Signature differ per attempt', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    $recordedRequests = [];
    Http::fake(function ($request) use (&$recordedRequests) {
        $recordedRequests[] = $request;

        return count($recordedRequests) === 1
            ? Http::response(['error' => 'unavailable'], 503)
            : Http::response(['ok' => true], 200);
    });

    // Two back-to-back manual invocations (see class doc — no real re-delivery
    // happens under `sync`) can otherwise land in the SAME wall-clock second,
    // producing an accidentally-identical Unix timestamp that has nothing to do with
    // the implementation: real retries are always minutes-to-hours apart per the
    // backoff curve ([10, 60, 300, 1800, 7200]s), so this collision can only happen
    // in an artificially fast test. Controlling time explicitly (not asserting on
    // unmanaged wall-clock behavior) removes that test-only flake.
    Carbon::setTestNow(now());
    c10InvokeHandle(c10JobAtAttempt($delivery->id, 1));

    Carbon::setTestNow(now()->addSeconds(60));
    c10InvokeHandle(c10JobAtAttempt($delivery->id, 2));

    Carbon::setTestNow();

    expect($recordedRequests)->toHaveCount(2);

    $first = $recordedRequests[0];
    $second = $recordedRequests[1];

    expect($first->header('X-BEAI-Delivery-Id')[0])->toBe($delivery->delivery_id)
        ->and($second->header('X-BEAI-Delivery-Id')[0])->toBe($delivery->delivery_id);

    expect($first->header('X-BEAI-Timestamp')[0])->not->toBe($second->header('X-BEAI-Timestamp')[0]);
    expect($first->header('X-BEAI-Signature')[0])->not->toBe($second->header('X-BEAI-Signature')[0]);
});

test('the exact transmitted body is what was signed — a receiver-side independent recomputation verifies', function (): void {
    [, $project, , $delivery] = c10PendingDelivery();

    $recordedRequest = null;
    Http::fake(function ($request) use (&$recordedRequest) {
        $recordedRequest = $request;

        return Http::response(['ok' => true], 200);
    });

    c10InvokeHandle(c10JobAtAttempt($delivery->id, 1));

    expect($recordedRequest)->not->toBeNull();

    $rawBody = $recordedRequest->body();
    $timestamp = (int) $recordedRequest->header('X-BEAI-Timestamp')[0];
    $signatureHeader = $recordedRequest->header('X-BEAI-Signature')[0];
    $providedHex = Str::after($signatureHeader, 'v1=');

    // Independent recomputation — bypasses WebhookSigner entirely, using bare
    // hash_hmac exactly as an external receiver's verifier would.
    $project->refresh();
    $expectedHex = hash_hmac('sha256', $timestamp.'.'.$rawBody, $project->webhook_secret);

    expect($providedHex)->toBe($expectedHex);

    // And the reverse: re-encoding the payload with the DEFAULT json_encode() flags
    // (the forbidden Http::post($url, $array) shape) would NOT match — proving the
    // guarantee holds at this HTTP boundary, not just inside WebhookSigner's own unit
    // tests (PR3).
    $defaultEncoded = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
    $wrongHex = hash_hmac('sha256', $timestamp.'.'.$defaultEncoded, $project->webhook_secret);
    expect($wrongHex)->not->toBe($providedHex);
});

test('sync-release no-op regression: a real dispatch under sync leaves the row pending, next_attempt_at set, and never throws', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    Http::fake([$delivery->target_url => Http::response(['error' => 'unavailable'], 503)]);

    // Real dispatch under the ambient `sync` connection (phpunit.xml:51) — NOT
    // Queue::fake(), NOT a mocked Job contract. This exercises the actual
    // Illuminate\Queue\Jobs\SyncJob::release() no-op, not an assumption about it.
    DeliverWebhookJob::dispatch($delivery->id);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->not->toBeNull();
});

test('terminal-row idempotency guard: a re-executed already-terminal row is a no-op', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    Http::fake();

    $delivery->forceFill(['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now()])->save();

    c10InvokeHandle(c10JobAtAttempt($delivery->id, 1));

    Http::assertNothingSent();

    $reloaded = $delivery->fresh();
    expect($reloaded->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($reloaded->attempt_count)->toBe($delivery->attempt_count);
});

test('secret non-leak: a receiver echoing the secret in a 500 body never leaks it into the row or any log line', function (): void {
    $secret = 'whsec_must_never_leak_9f8e7d6c5b';
    [, , , $delivery] = c10PendingDelivery($secret);

    Http::fake([$delivery->target_url => Http::response(['error' => "Unauthorized: {$secret}"], 500)]);

    // Log::listen pattern — ProviderSecretTest.php:137-177 precedent. Captures both
    // the message string AND the context array (json-encoded) for every log line
    // emitted during the job's execution, across every channel/level.
    $logLines = [];
    Log::listen(function ($event) use (&$logLines): void {
        $message = $event->message ?? '';
        $context = is_array($event->context ?? null) ? json_encode($event->context) : '';
        $logLines[] = $message.' '.$context;
    });

    c10InvokeHandle(c10JobAtAttempt($delivery->id, 1));

    $delivery->refresh();

    // (a) never in the row.
    expect(json_encode($delivery->getAttributes()))->not->toContain($secret);
    expect($delivery->last_error)->not->toContain($secret);
    // Redaction applies BEFORE truncation — the placeholder must survive.
    expect($delivery->last_error)->toContain('[redacted]');

    // (b) never in any log line emitted during the attempt.
    foreach ($logLines as $line) {
        expect($line)->not->toContain($secret);
    }
});

test('handle() is a no-op when the delivery row does not exist', function (): void {
    Http::fake();

    c10InvokeHandle(c10JobAtAttempt(999999999, 1));

    Http::assertNothingSent();
});

test('handle() fails closed (failed_permanent) when the project webhook_secret is missing at delivery time', function (): void {
    [, $project, , $delivery] = c10PendingDelivery();

    // Simulate the project config changing between recording (recorder saw a secret)
    // and delivery (a later admin action cleared it) — the row is still 'pending'.
    $project->forceFill(['webhook_secret' => null])->save();

    Http::fake();

    c10InvokeHandle(c10JobAtAttempt($delivery->id, 1));

    Http::assertNothingSent();

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::FailedPermanent)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_error)->toBe('webhook_url or webhook_secret missing at delivery time');
});

test('a connection/timeout error (no HTTP response at all) classifies as retryable and leaves the row pending', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    Http::fake(function (): void {
        throw new ConnectionException('Connection timed out');
    });

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(Mockery::type('int'));

    $job = new DeliverWebhookJob($delivery->id);
    $job->setJob($queueJob);
    c10InvokeHandle($job);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->last_response_status)->toBeNull()
        ->and($delivery->last_error)->toContain('Connection timed out');
});

test('failed() safety net sets dead when the row is still non-terminal', function (): void {
    [, , , $delivery] = c10PendingDelivery();

    $job = new DeliverWebhookJob($delivery->id);
    $job->failed(new RuntimeException('unexpected DB error mid-save'));

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Dead)
        ->and($delivery->last_error)->toBe('job_failed_before_outcome_recorded');
});

test('failed() is a no-op when the row is already terminal', function (): void {
    [, , , $delivery] = c10PendingDelivery();
    $delivery->forceFill(['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now()])->save();

    $job = new DeliverWebhookJob($delivery->id);
    $job->failed(new RuntimeException('should not matter'));

    $reloaded = $delivery->fresh();
    expect($reloaded->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($reloaded->last_error)->not->toBe('job_failed_before_outcome_recorded');
});

test('failed() is a no-op when the delivery row does not exist', function (): void {
    $job = new DeliverWebhookJob(999999999);

    // Must not throw — the row-not-found branch simply returns.
    $job->failed(new RuntimeException('irrelevant'));

    expect(true)->toBeTrue();
});
