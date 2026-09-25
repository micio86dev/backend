<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

/**
 * Encodes/decodes the BEAI Public API (`/v1`) `whd_` id for a
 * `webhook_deliveries` row — SPEC.md §3.6, `public-api/openapi.yaml`'s
 * `webhookDeliveryId` parameter pattern
 * (`^whd_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`).
 *
 * Deliberately NOT `App\Support\PublicApi\PublicId` (public-api step 7):
 * every OTHER `/v1` resource stores an opaque `public_id` ULID minted by
 * `App\Models\Concerns\HasPublicId`, and `PublicId::ULID_PATTERN` enforces
 * that shape everywhere — a lowercase-hex UUID with dashes never matches
 * it. G-15/SPEC.md §3.6 deliberately add NO new column for this id: the
 * C10 delivery pipeline already mints `webhook_deliveries.delivery_id` as
 * a UUID (`WebhookDeliveryRecorder::record()`, `Str::uuid()`) — the value
 * receivers already see on `X-BEAI-Delivery-Id`. This class is the ONLY
 * place that joins the `whd_` prefix to it, in either direction, mirroring
 * `PublicId`'s own single-responsibility discipline for its own (ULID)
 * id shape rather than widening `PublicId` itself to support a second,
 * differently-shaped id family.
 */
final class WebhookDeliveryId
{
    private const PREFIX = 'whd_';

    public static function encode(WebhookDelivery $delivery): string
    {
        return self::PREFIX.$delivery->delivery_id;
    }

    /**
     * @return string|null the bare UUID when `$value` starts with `whd_`
     *                     and the remainder is a syntactically valid UUID; `null` on any
     *                     mismatch — wrong prefix or a malformed remainder. Never throws:
     *                     mirrors `PublicId::decode()`'s own contract — every caller treats
     *                     `null` as "this id cannot possibly resolve to a row", always a `404`,
     *                     never a `400` (SPEC.md §3.2).
     */
    public static function decode(string $value): ?string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $remainder = substr($value, strlen(self::PREFIX));

        return Str::isUuid($remainder) ? $remainder : null;
    }
}
