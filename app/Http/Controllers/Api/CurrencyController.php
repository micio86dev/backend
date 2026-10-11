<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/currencies — the active catalogue, readable by every authenticated
 * role. Global like the framework catalogue: no organization, no policy.
 */
final class CurrencyController extends Controller
{
    /**
     * List the active currencies.
     */
    public function index(): AnonymousResourceCollection
    {
        return CurrencyResource::collection(Currency::query()->active()->orderBy('code')->get());
    }
}
