<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LlmModelResource;
use App\Models\LlmModel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * LlmModelController (pluggable-conversation-llm PR P1).
 *
 * Read-only, global, and readable by all three authorization roles
 * (admin/operator/viewer) — no policy check, mirroring FrameworkController's
 * doctrine for the framework catalog: there is no ownership to authorize on
 * a global price list, and hiding it from the operator who has to explain a
 * cost line is pointless (design D9).
 */
class LlmModelController extends Controller
{
    /**
     * GET /api/llm-models
     */
    public function index(): AnonymousResourceCollection
    {
        return LlmModelResource::collection(
            LlmModel::orderBy('sort_order')->get()
        );
    }
}
