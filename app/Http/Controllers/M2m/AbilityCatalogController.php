<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;

/**
 * AbilityCatalogController (C5 — M2M API Authentication).
 *
 * Publishes the canonical ability set so a client does not have to guess it.
 *
 * The allowed names live in `config/m2m_abilities.php` and are enforced by
 * `AbilitiesValidator`: anything outside the set is a 422. Without this
 * endpoint the only way to learn the names was to read the server source, so
 * the backoffice had mirrored the list in a frontend constant — a copy that
 * would have gone stale the first time an ability was added or removed, and
 * gone stale silently, because a wrong name looks exactly like a valid one
 * until the create call is refused.
 *
 * Route: GET /api/m2m/abilities (auth:api + TenantContext, admin only)
 *
 * The set is global, not per-organization: it is the API's vocabulary, not a
 * tenant's data. Authorization is nonetheless the same `viewAny` gate as the
 * rest of credential management — a viewer who cannot see credentials has no
 * reason to enumerate the permissions they can carry.
 *
 * REQ-6, REQ-8 / design §Abilities canonicalization
 */
final class AbilityCatalogController extends Controller
{
    /**
     * List the abilities an API client may be granted.
     *
     * GET /api/m2m/abilities
     * Auth: auth:api (admin only via ApiClientPolicy::viewAny)
     *
     * Response (200): { "data": ["participants:create", ...] }
     */
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', ApiClient::class);

        // Read straight from config. A second list here — even one that
        // happened to match today — would recreate the exact drift this
        // endpoint exists to remove.
        //
        // Typed as a plain array rather than a `list`: config is authored by
        // hand, so nothing guarantees sequential keys, and a JSON-encoded
        // gap-keyed array serialises as an OBJECT. `array_values` is what makes
        // the response a JSON array whatever the file looks like.
        /** @var array<array-key, string> $allowed */
        $allowed = config('m2m_abilities.allowed', []);

        return response()->json(['data' => array_values($allowed)]);
    }
}
