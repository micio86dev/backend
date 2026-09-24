<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Services\AbilitiesValidator;
use App\Services\ApiKeyGenerator;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

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

        // The ORG IN CONTEXT, not the actor's own column. See index() below
        // for why those are different for a superadmin.
        $orgId = app(TenantResolver::class)->getOrgId();

        // No client selected. A key belongs to the tenant it authenticates
        // FOR, so there is nothing to create here — `organization_id` is NOT
        // NULL and the insert used to die on the constraint with a 500. The
        // backoffice hides this section in the all-clients view, but the rail
        // is an affordance and this is the control: refuse, legibly, before
        // anything is written. Machine-facing body, not localized.
        if ($orgId === null) {
            return response()->json(['error' => 'no_client_selected'], 409);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array'],
            'abilities.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            // public-api step 2 (SPEC.md §3.7 test mode): optional, defaults
            // to 'live' — every client issued before this field existed IS a
            // live client, so defaulting new ones the same way keeps one
            // behaviour rather than a silent split. Rule::enum() validates
            // against App\Enums\ApiKeyMode's own backed values — the single
            // source of truth for the mode literal (review follow-up finding 3).
            'mode' => ['nullable', 'string', Rule::enum(ApiKeyMode::class)],
        ]);

        // Validate abilities against the canonical set
        if (! AbilitiesValidator::validate($validated['abilities'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['abilities' => ['One or more abilities are not in the allowed set.']],
            ], 422);
        }

        $mode = $validated['mode'] ?? 'live';
        $rawKey = ApiKeyGenerator::generate($mode);
        $hash = ApiKeyGenerator::hash($rawKey);

        // key_hash/key_prefix are NOT in $fillable (security invariant: cannot
        // be mass-assigned). Use forceFill to set them once at creation — this
        // is the only place either is ever written.
        $client = new ApiClient;
        $client->forceFill([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'abilities' => $validated['abilities'],
            'expires_at' => $validated['expires_at'] ?? null,
            'mode' => $mode,
            'key_hash' => $hash,
            'key_prefix' => ApiKeyGenerator::prefixOf($rawKey),
        ]);
        $client->save();

        // The raw key and its hash are both absent from the payload below:
        // AuditRecorder redacts key_hash by name, and $rawKey is never handed
        // to it in the first place. An audit trail that captures credentials is
        // a breach with good intentions. key_prefix/mode are NOT secrets — the
        // prefix is deliberately visible (ApiClientResource exposes it too)
        // and mode is a plain classification, so both are safe audit context.
        app(AuditRecorder::class)->record(
            action: 'api_client.created',
            subjectType: 'api_client',
            subjectId: (int) $client->getKey(),
            after: [
                'name' => $client->name,
                'abilities' => $client->abilities,
                'expires_at' => $client->expires_at?->toIso8601String(),
                'mode' => $client->mode->value,
                'key_prefix' => $client->key_prefix,
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
     * Unpaginated (generated-client-truth-and-session-safety D5) — the panel
     * answers a whole-set question: what can authenticate against my org,
     * and what did I revoke. Not a page-at-a-time one; `UserController::index`
     * already returns an unpaginated org-scoped `->get()` for the same class
     * of operator-managed collection. `is_active` first so the rows that
     * matter most stay first even at unusual scale.
     *
     * Never returns key_hash or raw api_key.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ApiClient::class);

        // THE RESOLVER, never `$request->user()->organization_id`.
        //
        // They agree for an ordinary admin and diverge for exactly one
        // identity. `TenantContext` narrows a superadmin who selected a client
        // by setting `resolver->setOrgId($actingOrgId)` and deliberately
        // leaves `users.organization_id` null — that null is what MAKES them a
        // superadmin, and writing to it would turn a view into an
        // impersonation. Every TenantModel reads the resolver through
        // `TenantScoped` and follows the selection for free; `ApiClient` is
        // not one (the M2M guard must find a key before any tenant context
        // exists), so this is the one query that has to ask for itself.
        //
        // Asking the user instead is what made this list come back EMPTY on
        // every request a superadmin made while acting as a client: the filter
        // was `organization_id = null`, which no row can match.
        $orgId = app(TenantResolver::class)->getOrgId();

        // No client selected: an empty list, not every tenant's keys. The
        // superadmin bypass exists so BEAI can operate the platform, but there
        // is no all-clients view of CREDENTIALS to operate — a key is only
        // meaningful inside the org it speaks for, and a merged list would be
        // the cross-tenant read surface the tenancy rules exist to forbid.
        if ($orgId === null) {
            return ApiClientResource::collection(collect());
        }

        $clients = ApiClient::where('organization_id', $orgId)
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

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
