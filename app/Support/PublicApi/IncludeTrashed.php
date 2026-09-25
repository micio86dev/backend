<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Drops `SoftDeletingScope` from a `belongsTo` eager-load constraint's
 * relation query — the shared mechanics `App\Http\Controllers\PublicApi\
 * InterviewController::projectIncludingTrashed()` established first (step
 * 5 review follow-up, item 9 / gga round 3 finding 1), and
 * `App\Http\Controllers\PublicApi\WebhookDeliveryController` needs too
 * (gga pre-commit follow-up, step 7 finding 2): a `webhook_deliveries` row
 * whose `project` was later soft-deleted must still resolve through a
 * `belongsTo` eager-load, never silently null out the relation and crash
 * a caller that trusts it non-null (`App\PublicApi\Serializers\
 * WebhookDeliverySerializer::toArray()` used to pass that `null` straight
 * into `PublicId::encode()`, a hard `TypeError`, surfaced to the caller as
 * an unhandled `500`).
 *
 * `$relation` is typed bare `Relation` (not `BelongsTo`) deliberately —
 * mirrors `InterviewController::projectIncludingTrashed()`'s own
 * reasoning: Larastan's `RelationForwardsCallsExtension` (which resolves a
 * model-specific macro like `withTrashed()` on a relation instance) only
 * works when the relation's generic `TRelatedModel` is CONCRETELY known —
 * which an inline `with([...])` eager-load constraint closure's own
 * parameter never is. `getQuery()`/`withoutGlobalScope()` are both
 * ORDINARILY declared methods on `Relation`/`Builder` — never macros — so
 * reaching the exact same effect `withTrashed()` has needs no generic
 * resolution at all.
 */
final class IncludeTrashed
{
    /**
     * @param  Relation<*, *, *>  $relation
     * @return Builder<*>
     */
    public static function forRelation(Relation $relation): Builder
    {
        return $relation->getQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }
}
