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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FrameworkVersion $fv */
        $fv = $this->resource;

        return [
            'id' => $fv->id,
            'organization_id' => $fv->organization_id,
            'version' => $fv->version,
            'label' => $fv->label,
            'is_locked' => $fv->is_locked,
            'created_at' => $fv->created_at?->toISOString(),
            'updated_at' => $fv->updated_at?->toISOString(),
        ];
    }
}
