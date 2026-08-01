<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WebhookDeliveryOutcome;
use App\Enums\WebhookDeliveryStatus;
use App\Events\WebhookDeliveryDead;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\RetryClassifier;
use App\Services\Webhooks\SecretRedactor;
use App\Services\Webhooks\WebhookSigner;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeliverWebhookJob — the delivery-attempt state machine (C10 — Webhooks Integration,
 * design.md D2/D3, ~95%-coverage correctness-critical zone).
 *
 * Payload is a SCALAR `int $deliveryId` — never a model (S1-S7 pattern, mirrors
 * `ScoreEvaluationJob::__construct(private readonly int $participantId, …)`,
 * `ScoreEvaluationJob.php:102-105`) — avoids `SerializesModels` re-resolving a
 * TenantModel through a global scope with a null ambient resolver.
 *
 * State machine (design.md D2):
 *   pending → delivered           (2xx)
 *   pending → pending (retry)     (retryable: 408/429/5xx/timeout/connection error,
 *                                  attempts() < max_attempts) — release($delay), never throw
 *   pending → failed_permanent    (any other 4xx — will not fix itself on retry)
 *   pending → dead                (retryable AND attempts() >= max_attempts)
 * `delivered`/`failed_permanent`/`dead`/`skipped` are terminal — the FIRST action in
 * `handle()` is a guard that returns immediately for a non-`pending` row (idempotency
 * under queue re-delivery, task 10.5).
 *
 * Tenancy (design.md D4): the row and project are resolved via
 * `withoutGlobalScopes()->find()` (explicit-id reads, no context needed). The
 * persistence step — and ONLY the persistence step — is wrapped in
 * `App\Support\Tenancy\TenantContextScope::runFor($delivery->organization_id, …)` so
 * the UPDATE's tenant-scoped WHERE clause matches under a null ambient resolver
 * (queue-context S3/S7). This class MUST keep referencing `TenantContextScope::` in
 * its own source — `tests/Arch/Tenancy/QueuedJobTenantContextArchTest.php` enforces
 * this for every `ShouldQueue` class recursively under `app/` (C10 PR5 hardening).
 *
 * Signature/body coupling (design.md D6, hard rule): the raw bytes signed by
 * `WebhookSigner::encode()` are the EXACT bytes transmitted via `Http::withBody()`.
 * `Http::post($url, $array)` is NEVER used here — it would re-encode the array with
 * different flags and silently break every receiver's independent verification.
 *
 * REQ: DeliverWebhookJob (C10 D2/D3)
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private readonly int $deliveryId,
    ) {}

    /**
     * Per-job execution ceiling in seconds — the job-level envelope around
     * the per-attempt HTTP budget (config/webhooks.php:79-80 — 5s connect +
     * 10s read = 15s per attempt), leaving headroom for signing, retry
     * bookkeeping, and persistence within a single handle() invocation.
     */
    public int $timeout = 60;

    /**
     * Queue-level attempt ceiling — read from config, never hardcoded. This is a
     * SAFETY NET for a genuinely unexpected exception (e.g. a DB error inside the
     * save() itself); the modeled retryable-HTTP-failure path never throws and never
     * exercises this framework mechanism — see class doc.
     */
    public function tries(): int
    {
        return (int) config('webhooks.delivery.max_attempts');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('webhooks.delivery.backoff_seconds');

        return $backoff;
    }

    public function handle(WebhookSigner $signer, SecretRedactor $redactor, RetryClassifier $classifier): void
    {
        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null) {
            Log::warning('DeliverWebhookJob: delivery row not found', ['delivery_id' => $this->deliveryId]);

            return;
        }

        // Idempotency guard (task 10.5) — terminal rows are a no-op. First action,
        // before any HTTP call.
        if ($delivery->status !== WebhookDeliveryStatus::Pending) {
            return;
        }

        /** @var Project|null $project */
        $project = Project::withoutGlobalScopes()->find($delivery->project_id);
        $secret = $project?->webhook_secret;

        $attemptCount = $this->attempts();

        if ($secret === null || $delivery->target_url === null) {
            // Defensive: should never happen for a row the recorder marked 'pending'
            // (the gate already required both), but fail closed rather than send an
            // unsigned request if project config changed between recording and delivery.
            $this->persist($delivery, function () use ($delivery, $attemptCount): void {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::FailedPermanent,
                    'attempt_count' => $attemptCount,
                    'last_attempt_at' => now(),
                    'last_error' => 'webhook_url or webhook_secret missing at delivery time',
                ])->save();
            });

            return;
        }

        $timestamp = now()->getTimestamp();
        $rawBody = $signer->encode($delivery->payload);
        $hex = $signer->sign($timestamp, $rawBody, $secret);

        try {
            $response = Http::timeout((int) config('webhooks.http.timeout_seconds'))
                ->connectTimeout((int) config('webhooks.http.connect_timeout_seconds'))
                ->withHeaders([
                    'X-BEAI-Signature' => $signer->header($hex),
                    'X-BEAI-Timestamp' => (string) $timestamp,
                    'X-BEAI-Event' => $delivery->event_type->value,
                    'X-BEAI-Delivery-Id' => $delivery->delivery_id,
                    'User-Agent' => (string) config('webhooks.http.user_agent'),
                ])
                ->withBody($rawBody, 'application/json')
                ->post($delivery->target_url);

            $responseStatus = $response->status();
            $outcome = $classifier->classify($responseStatus);
            $errorBody = $outcome === WebhookDeliveryOutcome::Delivered ? null : $response->body();
        } catch (ConnectionException $e) {
            $responseStatus = null;
            $outcome = WebhookDeliveryOutcome::Retryable;
            $errorBody = $e->getMessage();
        }

        $lastError = $errorBody !== null
            ? $redactor->redactAndTruncate($errorBody, $secret, (int) config('webhooks.errors.max_last_error_chars'))
            : null;

        $this->recordOutcome($delivery, $outcome, $attemptCount, $responseStatus, $lastError);
    }

    private function recordOutcome(
        WebhookDelivery $delivery,
        WebhookDeliveryOutcome $outcome,
        int $attemptCount,
        ?int $responseStatus,
        ?string $lastError,
    ): void {
        match ($outcome) {
            WebhookDeliveryOutcome::Delivered => $this->persist($delivery, function () use ($delivery, $attemptCount, $responseStatus): void {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Delivered,
                    'attempt_count' => $attemptCount,
                    'last_attempt_at' => now(),
                    'delivered_at' => now(),
                    'last_response_status' => $responseStatus,
                    'last_error' => null,
                ])->save();
            }),

            WebhookDeliveryOutcome::FailedPermanent => $this->persist($delivery, function () use ($delivery, $attemptCount, $responseStatus, $lastError): void {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::FailedPermanent,
                    'attempt_count' => $attemptCount,
                    'last_attempt_at' => now(),
                    'last_response_status' => $responseStatus,
                    'last_error' => $lastError,
                ])->save();
            }),

            WebhookDeliveryOutcome::Retryable => $this->recordRetryable($delivery, $attemptCount, $responseStatus, $lastError),
        };
    }

    private function recordRetryable(WebhookDelivery $delivery, int $attemptCount, ?int $responseStatus, ?string $lastError): void
    {
        if ($attemptCount >= $delivery->max_attempts) {
            $this->persist($delivery, function () use ($delivery, $attemptCount, $responseStatus, $lastError): void {
                $delivery->forceFill([
                    'status' => WebhookDeliveryStatus::Dead,
                    'attempt_count' => $attemptCount,
                    'last_attempt_at' => now(),
                    'last_response_status' => $responseStatus,
                    'last_error' => $lastError,
                ])->save();
            });

            Log::error('webhook.delivery.dead', [
                'delivery_id' => $delivery->delivery_id,
                'attempt_count' => $attemptCount,
            ]);

            // C12 D5. Placed here — immediately after persist(), OUTSIDE the
            // runFor() closure and alongside the existing log line — so the
            // listener never inherits an ambient organization it is required to
            // re-derive for itself.
            WebhookDeliveryDead::dispatch($delivery->getKey());

            return;
        }

        $delay = $this->backoffDelayFor($attemptCount);

        $this->persist($delivery, function () use ($delivery, $attemptCount, $responseStatus, $lastError, $delay): void {
            $delivery->forceFill([
                'attempt_count' => $attemptCount,
                'last_attempt_at' => now(),
                'next_attempt_at' => now()->addSeconds($delay),
                'last_response_status' => $responseStatus,
                'last_error' => $lastError,
            ])->save();
        });

        // Never throw (design.md D3 hard rule) — under `sync` this is a documented
        // no-op (Job::release() only sets a flag; SyncJob::attempts() stays 1
        // forever); under `database` this genuinely re-queues with $delay.
        $this->release($delay);
    }

    private function backoffDelayFor(int $attemptCount): int
    {
        $backoff = $this->backoff();
        $index = $attemptCount - 1;

        return $backoff[$index] ?? (int) end($backoff);
    }

    /**
     * Wrap a persistence closure in TenantContextScope::runFor() — see class doc.
     *
     * @param  \Closure(): void  $write
     */
    private function persist(WebhookDelivery $delivery, \Closure $write): void
    {
        TenantContextScope::runFor($delivery->organization_id, $write);
    }

    /**
     * Safety net only (design.md D3): covers a genuinely unexpected exception (e.g. a
     * DB error during save()) that Laravel's own tries()/backoff() mechanism
     * exhausted. If the row is still non-terminal, set it dead rather than stranding
     * it 'pending' with no job left to act on it.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('DeliverWebhookJob: job exhausted retries or crashed', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);

        /** @var WebhookDelivery|null $delivery */
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        $terminal = [
            WebhookDeliveryStatus::Delivered,
            WebhookDeliveryStatus::FailedPermanent,
            WebhookDeliveryStatus::Dead,
            WebhookDeliveryStatus::Skipped,
        ];

        if (in_array($delivery->status, $terminal, true)) {
            return;
        }

        $this->persist($delivery, function () use ($delivery): void {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Dead,
                'last_error' => 'job_failed_before_outcome_recorded',
            ])->save();
        });

        Log::error('webhook.delivery.dead', [
            'delivery_id' => $this->deliveryId,
            'reason' => 'unhandled_exception',
        ]);

        // The safety net's emission. recordRetryable() returns early for
        // terminal rows and this method does too, so the two are mutually
        // exclusive per row — but emitting from only one of them would leave a
        // silent hole exactly where an unhandled exception killed the job, i.e.
        // the case an operator most needs to hear about.
        WebhookDeliveryDead::dispatch($delivery->getKey());
    }
}
