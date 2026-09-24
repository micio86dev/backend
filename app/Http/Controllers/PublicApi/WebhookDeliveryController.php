<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\PublicApi\QueryValidationException;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhookJob;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\WebhookDelivery;
use App\PublicApi\Serializers\WebhookDeliverySerializer;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\IncludeTrashed;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\WebhookDeliveryId;
use App\Support\Tenancy\TenantResolver;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `GET /v1/webhooks/deliveries` and `POST /v1/webhooks/deliveries/{id}/
 * redeliver` — BEAI Public API (public-api step 7), SPEC.md §3.6 "Delivery
 * log"/"Redeliver". The public API does not introduce a second webhook
 * system — this controller is a read/redeliver surface over the EXISTING
 * C10 `webhook_deliveries` log; endpoint configuration (URL, secret,
 * enabled events) stays backoffice-only.
 *
 * `WebhookDelivery` is a `TenantModel` — every query here is ALREADY
 * scoped to the authenticated organization by `App\Models\Concerns\
 * TenantScoped`'s global scope (stamped by `App\Http\Middleware\PublicApi\
 * PublicApiTenantContext` earlier in the `/v1` middleware stack), mirroring
 * `ProjectController`'s own docblock. `redeliver()` additionally filters by
 * `organization_id` explicitly when resolving the path id (mirrors
 * `RecordingController::resolveParticipant()`), since a cross-tenant id
 * must answer `404`, never leak via the global scope alone should a future
 * caller add `withoutGlobalScopes()` nearby.
 */
final class WebhookDeliveryController extends Controller
{
    /**
     * @var list<string>
     */
    private const EAGER_LOAD_PARTICIPANT = ['participant'];

    /**
     * States a delivery must be in for `redeliver()` to accept it — G-15:
     * "Redeliver adds one authorized edge from the terminal delivery
     * states back to pending, mirroring the participant errore→in_attesa
     * recovery edge." `pending` is excluded (already in flight — redelivering
     * it would race the job already scheduled for it) and `skipped` is
     * excluded (it was never sent anywhere — see
     * `App\Services\Webhooks\WebhookDeliveryRecorder`'s own docblock;
     * reopening it would need a `webhook_url`/`webhook_secret` that never
     * existed at delivery time, which this endpoint cannot supply).
     *
     * @var list<WebhookDeliveryStatus>
     */
    private const REDELIVERABLE_STATUSES = [
        WebhookDeliveryStatus::Delivered,
        WebhookDeliveryStatus::FailedPermanent,
        WebhookDeliveryStatus::Dead,
    ];

    // SPEC.md §3.2 filtering + `openapi.yaml`'s `listWebhookDeliveries`
    // parameters: `status`, `event_type`, `interview_id`. An unrecognised
    // `status`/`event_type` value answers `400 validation_failed` (G-28 —
    // a malformed QUERY PARAMETER, never `422`), mirroring
    // `ProjectController::validateFilters()`. A malformed/unknown
    // `interview_id` matches NOTHING rather than `400` — mirrors
    // `InterviewController::index()`'s own `project_id` filter: an id
    // filter behaves like a path parameter (mismatched prefix → no
    // match), never a format error on a list operation. Kept as a plain
    // comment, never a docblock paragraph above the method (step 7 review
    // follow-up, mirroring the fix in `InterviewController::transcript()`/
    // `answers()`/`scoring()`): Scramble exports a multi-paragraph
    // docblock's own trailing paragraphs as this OPERATION's public
    // `description`, and a docblock also risks a stray ` * `
    // continuation artifact mid-sentence on export — neither belongs in
    // caller-facing documentation.
    /**
     * `GET /v1/webhooks/deliveries` — SPEC.md §3.6 "Delivery log".
     */
    #[IgnoreResponse(422)]
    #[Response(200, description: 'A page of webhook delivery attempts for the caller\'s organization, newest first.', type: 'array{data: list<array{id: string, event_type: string, status: string, interview_id: string, project_id: string, candidate_ref: string, target_url: string|null, attempt_count: int, max_attempts: int, payload_version: string, last_response_status: int|null, last_attempt_at: string|null, next_attempt_at: string|null, delivered_at: string|null, created_at: string}>, next_cursor: string|null, has_more: bool}')]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $this->validateFilters($request);

        $query = WebhookDelivery::query()
            ->where('organization_id', $organization->id)
            ->with(self::eagerLoad());

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', WebhookDeliveryStatus::from($status));
        }

        $eventType = $request->query('event_type');
        if (is_string($eventType) && $eventType !== '') {
            $query->where('event_type', WebhookEventType::from($eventType));
        }

        $interviewId = $request->query('interview_id');
        if (is_string($interviewId) && $interviewId !== '') {
            $bareId = PublicId::decode($interviewId, Participant::publicIdPrefix());
            $internalId = $bareId === null
                ? null
                : Participant::query()->where('organization_id', $organization->id)->wherePublicId($bareId)->value('id');
            $query->where('participant_id', is_int($internalId) ? $internalId : -1);
        }

        $page = CursorPage::paginate(
            $query,
            $request,
            fn (WebhookDelivery $delivery): array => WebhookDeliverySerializer::toArray($delivery),
        );

        return response()->json($page);
    }

    // SPEC.md §3.6 "re-sends the frozen payload bytes with the same
    // `delivery_id` and a fresh timestamp/signature." This does NOT
    // duplicate C10's own retry machinery: it dispatches the SAME
    // `DeliverWebhookJob` the original delivery and every scheduled retry
    // already use, against the SAME row — `payload`/`delivery_id` are the
    // frozen values `WebhookDeliveryRecorder` wrote at INSERT time (never
    // regenerated), and `DeliverWebhookJob::handle()` signs a FRESH
    // timestamp itself on every run, including this one.
    //
    // `attempt_count` continuation (gga pre-commit follow-up, finding 1):
    // `DeliverWebhookJob::handle()` writes `'attempt_count' =>
    // $this->attempts()`, Laravel's OWN per-dispatch counter, which starts
    // at 1 for this brand-new `dispatch()` call — with no idea a `dead` row
    // already spent, say, 5 attempts. Passing the row's PRE-redeliver
    // `attempt_count` through as `DeliverWebhookJob`'s second constructor
    // argument (`$attemptCountOffset`) is what makes the row's count keep
    // counting the delivery's total lifetime attempts (5 → 6, never reset
    // to 1) instead of silently granting a full new `max_attempts` budget
    // on top of the one already exhausted — see that constructor
    // parameter's own docblock for the mechanism.
    //
    // Atomicity (gga pre-commit follow-up, finding 3): the state check and
    // the write are ONE statement — `whereIn('status', ...)` inside the
    // UPDATE itself, never a separate SELECT-then-branch-then-blind-save().
    // Two concurrent redeliver calls against the same row race on this
    // single conditional UPDATE, which Postgres serialises via ordinary
    // row-level locking: whichever commits first flips the row out of
    // REDELIVERABLE_STATUSES, so the second call's own WHERE clause — now
    // re-evaluated against that already-committed row — matches nothing,
    // `$affected` is `0`, and it answers `409` having dispatched no job at
    // all. This is what makes "exactly one dispatch per delivery" hold
    // under concurrency, not merely under sequential calls.
    //
    // Fidelity judgement call (G-item, step 7 report): SPEC.md's "frozen
    // payload BYTES" is read as `webhook_deliveries.payload` — the same
    // frozen array `DeliverWebhookJob::handle()` itself re-encodes via
    // `WebhookSigner::encode()` on every attempt (including the very
    // first one); there is no SEPARATE stored copy of the transmitted
    // bytes to replay instead. Re-running the SAME `encode()` call the
    // original delivery already trusts is the closest faithful reading
    // available, not a weaker approximation of a byte-identical replay
    // this codebase could have chosen instead. Kept as a plain comment,
    // never a docblock paragraph (see the identical reasoning above
    // `index()`) — this rationale is for the next maintainer, not for
    // `openapi.json`.
    /**
     * `POST /v1/webhooks/deliveries/{id}/redeliver` — re-queues one
     * delivery for immediate re-send. Allowed only from a terminal
     * `delivered`/`failed_permanent`/`dead` state.
     */
    #[Response(202, description: 'Re-queued for immediate re-delivery with the frozen payload bytes and a fresh signature timestamp.', type: 'array{id: string, event_type: string, status: string, interview_id: string, project_id: string, candidate_ref: string, target_url: string|null, attempt_count: int, max_attempts: int, payload_version: string, last_response_status: int|null, last_attempt_at: string|null, next_attempt_at: string|null, delivered_at: string|null, created_at: string}')]
    #[Response(409, description: 'The delivery is not in a terminal state (code=invalid_state).', type: Problem::PROBLEM_SHAPE)]
    public function redeliver(Request $request, string $id): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $delivery = $this->resolveDelivery($id, $organization);

        if ($delivery === null) {
            abort(404);
        }

        $preRedeliverAttemptCount = $delivery->attempt_count;

        // `delivered_at` is CHECK-constrained, at the database level, to be
        // non-null if-and-only-if status='delivered' (the owning
        // migration's own raw DDL, `webhook_deliveries_delivered_at_check`)
        // — reopening a 'delivered' row back to 'pending' without also
        // clearing this column would violate that constraint outright.
        $affected = WebhookDelivery::query()
            ->where('id', $delivery->id)
            ->where('organization_id', $organization->id)
            ->whereIn('status', self::REDELIVERABLE_STATUSES)
            ->update([
                'status' => WebhookDeliveryStatus::Pending->value,
                'delivered_at' => null,
                'next_attempt_at' => now(),
            ]);

        if ($affected !== 1) {
            return Problem::make(
                $request, 409, 'invalid_state', 'Invalid state',
                'Only a delivered, failed_permanent or dead delivery can be redelivered.',
            );
        }

        DeliverWebhookJob::dispatch($delivery->id, $preRedeliverAttemptCount);

        $reloaded = WebhookDelivery::query()
            ->where('id', $delivery->id)
            ->with(self::eagerLoad())
            ->first();

        return PublicApiJson::response(WebhookDeliverySerializer::toArray($reloaded ?? $delivery), 202);
    }

    /**
     * Unreachable through the real `/v1` stack (`PublicApiTenantContext`
     * already 401s first for a missing/invalid org) — resolved explicitly
     * rather than trusted, same defensive discipline as
     * `RecordingController::resolveOrganization()`.
     */
    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        return $organization;
    }

    private function resolveDelivery(string $rawId, Organization $organization): ?WebhookDelivery
    {
        $bareId = WebhookDeliveryId::decode($rawId);

        if ($bareId === null) {
            return null;
        }

        return WebhookDelivery::where('organization_id', $organization->id)
            ->where('delivery_id', $bareId)
            ->with(self::eagerLoad())
            ->first();
    }

    /**
     * gga pre-commit follow-up (step 7, finding 2): `project` is eager-loaded
     * with `SoftDeletingScope` dropped (`App\Support\PublicApi\
     * IncludeTrashed`, the SAME mechanics `InterviewController::
     * projectEagerLoad()` already established) — a plain `belongsTo`
     * would silently resolve to `null` for a delivery whose project was
     * later soft-deleted, and `WebhookDeliverySerializer::toArray()` would
     * then pass that `null` straight into `PublicId::encode()`, a hard
     * `TypeError` surfaced as an unhandled `500`. Column-restricted to
     * `id`/`public_id`: the serializer only ever reads `public_id` off
     * this relation, never a nested `Project` object (unlike
     * `InterviewController`'s own `?expand=project`, which this contract
     * does not offer).
     *
     * @return array{0: string, project: \Closure(Relation<*, *, *>): Builder<*>}
     */
    private static function eagerLoad(): array
    {
        return [
            ...self::EAGER_LOAD_PARTICIPANT,
            'project' => fn (Relation $relation): Builder => IncludeTrashed::forRelation($relation)->select(['id', 'public_id']),
        ];
    }

    private function validateFilters(Request $request): void
    {
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(WebhookDeliveryStatus::values())],
            'event_type' => ['sometimes', 'string', Rule::in(WebhookEventType::values())],
        ]);

        if ($validator->fails()) {
            throw new QueryValidationException($validator);
        }
    }
}
