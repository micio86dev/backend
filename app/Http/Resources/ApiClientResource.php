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
     * Scramble does not honour a `@var` annotation on the local `$client`
     * assignment below (local-assignment type inference gap), so every
     * `$client->x` property fetch degrades to its default inferred type
     * when Scramble statically walks this method body. A plain `@return`
     * tag does NOT override that body inference (verified empirically —
     * `scramble:export` kept emitting `string` for `id`/`is_active` with a
     * standard `@return array{...}` present); Scramble's actual override
     * hook is the package-specific `@scramble-return` tag below, which
     * replaces the inferred return type outright. Both tags are kept:
     * `@return` for PHPStan/IDE tooling, `@scramble-return` for the
     * generator. Declarative, touches zero runtime code.
     *
     * @return array{id: int, name: string, abilities: list<string>, is_active: bool, state: 'active'|'expired'|'revoked', expires_at: string|null, last_used_at: string|null, created_at: string|null}
     *
     * @scramble-return array{id: int, name: string, abilities: list<string>, is_active: bool, state: 'active'|'expired'|'revoked', expires_at: string|null, last_used_at: string|null, created_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var ApiClient $client */
        $client = $this->resource;

        return [
            'id' => $client->id,
            'name' => $client->name,
            // array_values(): `abilities` casts to a PHP array, not a
            // guaranteed list — reindexing here is what actually backs the
            // `list<string>` contract above, not just satisfying the checker.
            'abilities' => array_values($client->abilities ?? []),
            'is_active' => $client->is_active,
            'state' => $client->state(),
            'expires_at' => $client->expires_at?->toISOString(),
            'last_used_at' => $client->last_used_at?->toISOString(),
            // `created_at` itself is never null (ApiClient.php's own
            // `@property Carbon $created_at`, confirmed by PHPStan when a
            // nullsafe `?->` was tried here first: "Using nullsafe method
            // call on non-nullable type ... never null"). The `string|null`
            // in the return type above is for `Carbon::toISOString(bool):
            // ?string` itself — Carbon's own signature is nullable — not
            // for the object being called on.
            'created_at' => $client->created_at->toISOString(),
            // key_hash: intentionally excluded (hidden + security-critical)
            // api_key:  intentionally excluded (transient; in 201 body only)
            // organization_id: not needed in resource; returned directly in whoami
        ];
    }
}
