<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Models\User;
use App\Services\AbilitiesValidator;
use App\Services\ApiKeyGenerator;
use App\Support\Audit\AuditRecorder;
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
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array'],
            'abilities.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Validate abilities against the canonical set
        if (! AbilitiesValidator::validate($validated['abilities'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['abilities' => ['One or more abilities are not in the allowed set.']],
            ], 422);
        }

        $rawKey = ApiKeyGenerator::generate();
        $hash = ApiKeyGenerator::hash($rawKey);

        /** @var User $user */
        $user = $request->user();

        // key_hash is NOT in $fillable (security invariant: cannot be mass-assigned).
        // Use forceFill to set it once at creation — this is the only place it is ever written.
        $client = new ApiClient;
        $client->forceFill([
            'organization_id' => $user->organization_id,
            'name' => $validated['name'],
            'abilities' => $validated['abilities'],
            'expires_at' => $validated['expires_at'] ?? null,
            'key_hash' => $hash,
        ]);
        $client->save();

        // The raw key and its hash are both absent from the payload below:
        // AuditRecorder redacts key_hash by name, and $rawKey is never handed
        // to it in the first place. An audit trail that captures credentials is
        // a breach with good intentions.
        app(AuditRecorder::class)->record(
            action: 'api_client.created',
            subjectType: 'api_client',
            subjectId: (int) $client->getKey(),
            after: [
                'name' => $client->name,
                'abilities' => $client->abilities,
                'expires_at' => $client->expires_at?->toIso8601String(),
            ],
        );

        return response()->json([
            'data' => new ApiClientResource($client),
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

        /** @var User $user */
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
        //
        // Argument order is load-bearing. Carbon 3 returns a SIGNED diff, so
        // $expires_at->diffInSeconds(now()) on a FUTURE date yields a negative
        // number and max(1, …) collapses every TTL to 1 second. The receiver must
        // be the earlier instant: now()->diffInSeconds($expires_at).
        // max(1, …) still clamps an already-expired key to the minimum TTL.
        //
        // ceil(), not a plain (int) cast: the diff is a float and `expires_at`
        // is stored at second precision, so truncating would let the denylist
        // entry expire up to a second BEFORE the key it revokes. Rounding up
        // is the only direction that keeps the entry alive as long as the key.
        try {
            $ttl = $apiClient->expires_at !== null
                ? max(1, (int) ceil(now()->diffInSeconds($apiClient->expires_at)))
                : 365 * 24 * 3600;

            Cache::put('client_revoked:'.$apiClient->id, true, $ttl);
        } catch (\Throwable) {
            // Non-fatal — DB is already updated; Redis is a fast-path optimisation.
        }

        // Recorded AFTER the durable DB write, so the trail never claims a
        // revocation that did not commit. The Redis fast-path above is
        // best-effort and its outcome is deliberately not audited: it is a
        // cache, and a cache miss is not a security event.
        app(AuditRecorder::class)->record(
            action: 'api_client.revoked',
            subjectType: 'api_client',
            subjectId: (int) $apiClient->getKey(),
            before: ['is_active' => true],
            after: ['is_active' => false],
        );

        return response()->json(null, 204);
    }
}
