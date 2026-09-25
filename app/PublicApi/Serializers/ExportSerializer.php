<?php

declare(strict_types=1);

namespace App\PublicApi\Serializers;

use App\Enums\ApiKeyMode;
use App\Models\Export;
use App\Support\PublicApi\PublicId;
use Carbon\CarbonInterface;

/**
 * Public-safe `Export` shape for the BEAI Public API (`/v1`) — SPEC.md §3.3
 * "Exports", `public-api/openapi.yaml`'s `Export` schema, exercised by
 * `POST /v1/exports`, `GET /v1/exports` and `GET /v1/exports/{id}`.
 *
 * `download_url`/`download_expires_at` are NOT derived from the model alone
 * — a signed URL is minted per REQUEST (`Storage::disk()->temporaryUrl()`,
 * the same "sign it at read time, never store it" discipline
 * `RecordingController` already established for `interview_recordings`), so
 * both are passed in by the caller rather than read off `$export` — mirrors
 * why `WebhookDeliverySerializer::toArray()` takes the already-eager-loaded
 * relations rather than lazy-loading them itself: this class stays a pure
 * function of its arguments, with no hidden I/O.
 */
final class ExportSerializer
{
    /**
     * @return array{id: string, status: string, scope: string, format: string, livemode: bool, from: string|null, to: string|null, record_count: int|null, download_url: string|null, download_expires_at: string|null, size_bytes: int|null, checksum_sha256: string|null, failure_reason: string|null, created_at: string, completed_at: string|null}
     */
    public static function toArray(Export $export, ?string $downloadUrl = null, ?CarbonInterface $downloadExpiresAt = null): array
    {
        return [
            'id' => PublicId::encode($export),
            'status' => $export->status->value,
            'scope' => $export->scope->value,
            'format' => $export->format->value,
            'livemode' => $export->mode === ApiKeyMode::Live,
            'from' => $export->from_at?->toIso8601String(),
            'to' => $export->to_at?->toIso8601String(),
            'record_count' => $export->record_count,
            'download_url' => $downloadUrl,
            'download_expires_at' => $downloadExpiresAt?->toIso8601String(),
            'size_bytes' => $export->size_bytes,
            'checksum_sha256' => $export->checksum_sha256,
            'failure_reason' => $export->failure_reason,
            'created_at' => $export->created_at->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
        ];
    }
}
