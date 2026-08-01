<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * WhoamiController (C5 — M2M API Authentication).
 *
 * GET /api/m2m/whoami
 * Protected: auth:api-m2m (no ability required — authentication alone is sufficient)
 *
 * Returns identity information for the authenticated M2M client.
 * MUST NOT expose key_hash, api_key, or any key material.
 *
 * REQ-9
 */
final class WhoamiController extends Controller
{
    /**
     * Return the authenticated M2M client identity.
     *
     * Response shape: { client_id, organization_id, abilities }
     */
    public function __invoke(): JsonResponse
    {
        /** @var ApiClient $client */
        $client = Auth::guard('api-m2m')->user();

        return response()->json([
            'client_id' => $client->id,
            'organization_id' => $client->organization_id,
            'abilities' => $client->abilities ?? [],
        ]);
    }
}
