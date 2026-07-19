<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Services\AbilitiesValidator;
use App\Services\ApiKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

/**
 * ApiClientController (C5 — M2M API Authentication).
 *
 * Admin-only credential management for M2M API clients.
 *
 * Routes (all under /api/m2m, auth:api + TenantContext):
 *   POST   /clients          — create a new client; returns 201 with one-time api_key
 *   GET    /clients          — list org-scoped clients (paginated; no key material)
 *   DELETE /clients/{id}     — revoke (soft-revoke via is_active=false + Redis denylist)
 *   (NO show endpoint)       — GET /clients/{id} → 404
 *
 * Security invariants:
 * - api_key is returned ONCE in the 201 response as a top-level sibling of "data".
 * - key_hash is NEVER returned in any response.
 * - DB write (is_active=false) is committed BEFORE the Redis denylist write.
 *
 * REQ-2, REQ-7, REQ-8 / design §Credential management API
 */
final class ApiClientController extends Controller
{
    /**
     * Create a new M2M API client.
     *
     * POST /api/m2m/clients
     * Auth: auth:api (admin only via ApiClientPolicy)
     *
     * Response (201):
     * {
     *   "data": { ...ApiClientResource... },
     *   "api_key": "beai_live_..."   ← returned ONCE, never stored raw
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ApiClient::class);

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'abilities'  => ['required', 'array'],
            'abilities.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Validate abilities against the canonical set
        if (! AbilitiesValidator::validate($validated['abilities'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['abilities' => ['One or more abilities are not in the allowed set.']],
            ], 422);
        }

        $rawKey = ApiKeyGenerator::generate();
        $hash = ApiKeyGenerator::hash($rawKey);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // key_hash is NOT in $fillable (security invariant: cannot be mass-assigned).
        // Use forceFill to set it once at creation — this is the only place it is ever written.
        $client = new ApiClient;
        $client->forceFill([
            'organization_id' => $user->organization_id,
            'name'            => $validated['name'],
            'abilities'       => $validated['abilities'],
            'expires_at'      => $validated['expires_at'] ?? null,
            'key_hash'        => $hash,
        ]);
        $client->save();

        return response()->json([
            'data'    => new ApiClientResource($client),
            'api_key' => $rawKey,  // returned ONCE — never stored raw, never logged
        ], 201);
    }

    /**
     * List M2M API clients for the authenticated admin's organization.
     *
     * GET /api/m2m/clients
     * Auth: auth:api (admin only via ApiClientPolicy)
     *
     * Never returns key_hash or raw api_key.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApiClient::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $clients = ApiClient::where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return ApiClientResource::collection($clients);
    }

    /**
     * Revoke an M2M API client.
     *
     * DELETE /api/m2m/clients/{apiClient}
     * Auth: auth:api (admin only, same org via ApiClientPolicy::delete)
     *
     * Write ordering invariant (design §Revocation write ordering):
     *   1. DB write (is_active=false) committed FIRST — durable authoritative flag
     *   2. Redis denylist write SECOND — fast-path cache
     *
     * A crash between the two writes leaves the system in the safer state:
     * is_active=false in DB → next guard lookup rejects the key.
     */
    public function destroy(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->authorize('delete', $apiClient);

        // 1. Durable DB write FIRST — this is the authoritative revocation.
        $apiClient->is_active = false;
        $apiClient->save();

        // 2. Redis fast-path SECOND — best-effort, exception-guarded.
        // TTL: remaining life if expires_at set; 1-year fallback for non-expiring keys.
        try {
            $ttl = $apiClient->expires_at !== null
                ? max(1, $apiClient->expires_at->diffInSeconds(now()))
                : 365 * 24 * 3600;

            Cache::put('client_revoked:' . $apiClient->id, true, $ttl);
        } catch (\Throwable) {
            // Non-fatal — DB is already updated; Redis is a fast-path optimisation.
        }

        return response()->json(null, 204);
    }
}
