<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Enums\WebhookSkipReason;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContextScope;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WebhookDeliveryRecorder — the ordered delivery-decision gate and the single INSERT
 * path for `webhook_deliveries` (C10 — Webhooks Integration, design.md D1-D4, D10, Δ1).
 *
 * Ordered gate (Δ1 — 4 steps; the original proposal only had 3):
 *   1. `webhook_url` null                    → skipped / no_webhook_url
 *   2. `webhook_secret` null                 → skipped / no_webhook_secret
 *   3. event type not in `webhook_events`    → skipped / event_type_disabled
 *   4. otherwise                             → pending
 *
 * All 3 `skipped` variants are terminal: never signed, never HTTP-called. A `pending`
 * row is dispatch-ready — dispatching `DeliverWebhookJob` is the CALLER's
 * responsibility (the PR5 `SendEvaluationWebhook`/`SendProgressWebhook` listeners),
 * NOT this class's; this class never makes an HTTP call in any branch.
 *
 * Tenancy (design.md D4, Δ3). This class is designed to run from a queued job with a
 * null ambient `TenantResolver`, or from the SSO exchange's unauthenticated public
 * endpoint — neither has a reliable ambient tenant context. The `Project` row is
 * therefore resolved via `withoutGlobalScopes()->find($projectId)`: a single
 * explicit-id lookup (not a scan), safe because the caller already knows exactly
 * which project it means — mirrors the established `ScoreEvaluationJob` read pattern
 * (`ScoreEvaluationJob.php:186-188`). Do NOT generalize this `withoutGlobalScopes()`
 * usage into HTTP-context list/show endpoints — those must resolve tenant-scoped
 * models via plain `findOrFail()` AFTER `TenantContext` has run
 * (`ProjectController.php:23-28`), never `withoutGlobalScopes()`.
 *
 * `$orgId` is re-derived from the loaded Project row, never trusted from ambient
 * state. The INSERT is wrapped in `App\Support\Tenancy\TenantContextScope::runFor()`
 * (the real class — `TenantScope::run()` does not exist, Δ3) so
 * `TenantScoped::creating` stamps the correct `organization_id` instead of throwing
 * `MissingTenantContextException`. The previous ambient (orgId, bypass) pair is
 * restored in `runFor()`'s `finally` block regardless of outcome.
 *
 * Idempotency (design.md D1/D4). A UNIQUE index on `(organization_id, project_id,
 * event_type, dedupe_key)` arbitrates duplicate emissions — including the SSO
 * exchange's TOCTOU race at `SsoExchangeController.php:137-158` — WITHOUT touching
 * that controller's deliberately atomic upsert. A 23505 violation is caught
 * (`ScoreEvaluationJob.php:171-189` precedent) and the EXISTING row is returned. This
 * doubles as the frozen-payload guarantee: the freshly built `$payload` from a losing
 * (duplicate or re-score) attempt is simply discarded, never applied as an UPDATE.
 *
 * REQ: WebhookDeliveryRecorder (C10 D1-D4, D10, Δ1)
 */
final class WebhookDeliveryRecorder
{
    /**
     * @param  Closure(string): array<string, mixed>  $payloadFactory  Receives the
     *                                                                 freshly generated delivery_id (generated HERE, at row-creation time —
     *                                                                 design.md D1: "generated ONCE at row creation") and returns the frozen
     *                                                                 payload array to persist. Called for EVERY branch, including `skipped`
     *                                                                 — `webhook_deliveries.payload` is NOT NULL, so even a skipped delivery
     *                                                                 records what WOULD have been sent, for audit/debugging (C11).
     */
    public function record(
        int $projectId,
        int $participantId,
        WebhookEventType $eventType,
        string $dedupeKey,
        Closure $payloadFactory,
    ): WebhookDelivery {
        /** @var Project|null $project */
        $project = Project::withoutGlobalScopes()->find($projectId);

        if ($project === null) {
            throw new RuntimeException("WebhookDeliveryRecorder: project {$projectId} not found.");
        }

        $orgId = $project->organization_id;

        return TenantContextScope::runFor(
            $orgId,
            function () use ($project, $participantId, $eventType, $dedupeKey, $payloadFactory, $orgId): WebhookDelivery {
                [$status, $skipReason, $targetUrl] = $this->decide($project, $eventType);

                $deliveryId = (string) Str::uuid();
                $payload = $payloadFactory($deliveryId);

                $attributes = [
                    'project_id' => $project->id,
                    'participant_id' => $participantId,
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'dedupe_key' => $dedupeKey,
                    'status' => $status,
                    'skip_reason' => $skipReason,
                    'target_url' => $targetUrl,
                    'payload' => $payload,
                    'payload_version' => (string) config('webhooks.payload.version'),
                    'attempt_count' => 0,
                    'max_attempts' => (int) config('webhooks.delivery.max_attempts'),
                ];

                try {
                    // The INSERT runs inside its own DB::transaction() so a caught
                    // UniqueConstraintViolationException rolls back to a SAVEPOINT
                    // (Laravel automatically nests via savepoints when already inside
                    // an outer transaction) rather than aborting the enclosing
                    // transaction at the Postgres level — without this, a caller (or a
                    // RefreshDatabase-wrapped test) already inside a transaction would
                    // find EVERY subsequent statement fail with "current transaction is
                    // aborted" even after the exception is caught here.
                    return DB::transaction(fn (): WebhookDelivery => WebhookDelivery::create($attributes));
                } catch (UniqueConstraintViolationException) {
                    // Concurrent/duplicate emission — the DB is the arbiter, not app
                    // logic. Reload and return the existing (winning) row; $payload
                    // built above for the loser is discarded — see class doc.
                    /** @var WebhookDelivery $existing */
                    $existing = WebhookDelivery::where('organization_id', $orgId)
                        ->where('project_id', $project->id)
                        ->where('event_type', $eventType->value)
                        ->where('dedupe_key', $dedupeKey)
                        ->firstOrFail();

                    return $existing;
                }
            }
        );
    }

    /**
     * The ordered gate (Δ1).
     *
     * @return array{0: WebhookDeliveryStatus, 1: WebhookSkipReason|null, 2: string|null}
     */
    private function decide(Project $project, WebhookEventType $eventType): array
    {
        if ($project->webhook_url === null) {
            return [WebhookDeliveryStatus::Skipped, WebhookSkipReason::NoWebhookUrl, null];
        }

        // Eloquent-only read: webhook_secret is cast 'encrypted' (Project.php:88) and
        // decrypts transparently here regardless of withoutGlobalScopes() — that only
        // strips the tenant WHERE filter, never attribute casts.
        if ($project->webhook_secret === null) {
            return [WebhookDeliveryStatus::Skipped, WebhookSkipReason::NoWebhookSecret, $project->webhook_url];
        }

        $enabledEvents = $project->webhook_events ?? [];
        if (! in_array($eventType->value, $enabledEvents, true)) {
            return [WebhookDeliveryStatus::Skipped, WebhookSkipReason::EventTypeDisabled, $project->webhook_url];
        }

        return [WebhookDeliveryStatus::Pending, null, $project->webhook_url];
    }
}
