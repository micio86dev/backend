<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FrameworkVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FrameworkVersionResource (C4).
 *
 * Serializes a FrameworkVersion for GET /api/framework/versions.
 *
 * @mixin FrameworkVersion
 */
class FrameworkVersionResource extends JsonResource
{
    /**
     * `id`/`organization_id` backed by an explicit `(int)` cast (design.md
     * D1). Already exports correctly today per the committed snapshot —
     * annotated anyway so a future Scramble upgrade cannot silently
     * re-degrade the field.
     *
     * @return array{id: int, organization_id: int, version: string, label: string|null, is_locked: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, organization_id: int, version: string, label: string|null, is_locked: bool, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var FrameworkVersion $fv */
        $fv = $this->resource;

        return [
            'id' => (int) $fv->id,
            'organization_id' => (int) $fv->organization_id,
            'version' => $fv->version,
            'label' => $fv->label,
            'is_locked' => $fv->is_locked,
            'created_at' => $fv->created_at?->toISOString(),
            'updated_at' => $fv->updated_at?->toISOString(),
        ];
    }
}
