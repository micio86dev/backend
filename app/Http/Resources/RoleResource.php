<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RoleResource (C3 Framework Catalog).
 *
 * Serializes a Role model for the GET /api/framework/roles endpoint.
 * Uses the current app locale (set by FrameworkController locale resolution).
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'code' => $role->code,
            'name' => $role->name,
            'responsibilities' => $role->responsibilities,
            'competency_count' => $role->competencies()->count(),
        ];
    }
}
