<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ApiClientResource (C5 — M2M API Authentication).
 *
 * Serializes an ApiClient for API responses.
 *
 * SECURITY INVARIANT: key_hash and raw api_key MUST NEVER appear in this resource.
 *   - key_hash is in $hidden (excluded from toArray/toJson) AND explicitly omitted here.
 *   - api_key (the raw bearer key) is transient — it exists only in the 201 store
 *     response body and is injected by ApiClientController::store() as a top-level
 *     sibling of "data", NOT via this resource.
 *   - This resource is used for index and all subsequent responses — neither key
 *     material is ever exposed through it.
 *
 * @mixin ApiClient
 */
class ApiClientResource extends JsonResource
{
    /**
     * Explicit whitelist — only safe fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiClient $client */
        $client = $this->resource;

        return [
            'id'           => $client->id,
            'name'         => $client->name,
            'abilities'    => $client->abilities ?? [],
            'is_active'    => $client->is_active,
            'expires_at'   => $client->expires_at?->toISOString(),
            'last_used_at' => $client->last_used_at?->toISOString(),
            'created_at'   => $client->created_at?->toISOString(),
            // key_hash: intentionally excluded (hidden + security-critical)
            // api_key:  intentionally excluded (transient; in 201 body only)
            // organization_id: not needed in resource; returned directly in whoami
        ];
    }
}
