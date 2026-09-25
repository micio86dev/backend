<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApiKeyMode;
use App\Enums\ExportFormat;
use App\Enums\ExportScope;
use App\Enums\ExportStatus;
use App\Models\Export;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for `App\Models\Export` (public-api step 8).
 *
 * NOTE: organization_id is NOT fillable — it is stamped by
 * TenantScoped.creating from the active TenantResolver. Callers MUST create
 * inside App\Support\Tenancy\TenantContextScope::runFor() (same discipline
 * as WebhookDeliveryFactory's own docblock).
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * A legal 'queued' row — the state every real POST /v1/exports creates.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => ApiKeyMode::Live,
            'scope' => ExportScope::Interviews,
            'format' => ExportFormat::Jsonl,
            'from_at' => null,
            'to_at' => null,
            'include_transcripts' => true,
            'include_scoring' => true,
            'include_audio' => false,
            'status' => ExportStatus::Queued,
        ];
    }

    /**
     * `object_key` is parameterized by the owning organization id so it
     * matches `ExportController::downloadUrlFor()`'s own real
     * `exports/{org_id}/…` prefix check — a bare `'exports/fixture/…'` key
     * (the previous default) never starts with `exports/{organization_id}/`
     * for ANY real organization, so every caller of this state silently got
     * `download_url: null` unless it overrode the key explicitly.
     *
     * `organization_id` is not yet set on `$attrs`/the not-yet-created model
     * (it is stamped by `TenantScoped::creating`, which fires on `save()`,
     * AFTER this state closure resolves) — read from the ambient
     * `TenantResolver` instead, which every caller of this factory (see
     * `Export`'s own class doc: "callers MUST create inside
     * `TenantContextScope::runFor()`") already has set to the target
     * organization for the whole duration of the `create()` call.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ExportStatus::Ready,
            'record_count' => 1,
            'object_key' => sprintf('exports/%s/export.jsonl', app(TenantResolver::class)->getOrgId() ?? 'fixture'),
            'size_bytes' => 42,
            'checksum_sha256' => hash('sha256', 'fixture'),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attrs): array => [
            'status' => ExportStatus::Failed,
            'failure_reason' => 'fixture failure',
            'completed_at' => now(),
        ]);
    }
}
