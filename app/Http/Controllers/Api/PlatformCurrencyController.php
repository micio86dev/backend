<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCurrencyRequest;
use App\Http\Requests\UpdateCurrencyRequest;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform currency catalogue (organization-currencies D9/D10). Superadmin
 * only; every write is ONE `PlatformAuditWriter` row in the same transaction,
 * so an unaudited rate change cannot exist.
 *
 * The in-use check runs under `lockForUpdate` on the currency row: the
 * organizations foreign key takes a key-share lock on it, so an assignment
 * racing a delete or a deactivation waits instead of slipping through. The 409
 * body names no organization (D11): the catalogue never answers who uses what.
 *
 * `abort_unless` is written out per action, like `PlatformAvatarTemplateController`,
 * because Scramble documents only the refusals a controller visibly makes. `@throws
 * AuthorizationException` is deliberate although `abort_unless` raises an HttpException: it is
 * the annotation Scramble maps to the documented 403 response.
 */
final class PlatformCurrencyController extends Controller
{
    public function __construct(private readonly PlatformAuditWriter $audit) {}

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    private function conflict(string $error): JsonResponse
    {
        return response()->json(['message' => $error], Response::HTTP_CONFLICT);
    }

    /**
     * List every currency, inactive ones included.
     *
     * @throws AuthorizationException
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        return CurrencyResource::collection(Currency::query()->orderBy('code')->get());
    }

    /**
     * Add a currency to the catalogue.
     *
     * @throws AuthorizationException
     */
    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        // Inactive unless the request says otherwise: a rate-bearing currency may be created
        // active (StoreCurrencyRequest already refuses activation without a rate).
        $data = [...$request->validated(), 'is_active' => $request->validated('is_active') ?? false];

        try {
            $currency = DB::transaction(function () use ($request, $data): Currency {
                $currency = Currency::create($data);
                $this->audit->record($request->user()?->id, 'currency.created', 'Currency', null, null, $data);

                return $currency;
            });
        } catch (UniqueConstraintViolationException) {
            // A racing request committed this code after the `unique` rule passed: same answer as the rule.
            throw ValidationException::withMessages(['code' => StoreCurrencyRequest::MESSAGES['code.unique']]);
        }

        return (new CurrencyResource($currency->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Edit a currency's rate, symbol, decimals or active flag.
     *
     * @throws AuthorizationException
     */
    #[ResponseDoc(200, type: 'array{data: \App\Http\Resources\CurrencyResource}')]
    #[ResponseDoc(409, description: 'The currency is in use, or it is USD (immutable).', type: "array{message: 'currency_in_use'|'currency_usd_immutable'}")]
    public function update(UpdateCurrencyRequest $request, string $code): CurrencyResource|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $result = DB::transaction(function () use ($request, $code): Currency|JsonResponse {
            $currency = Currency::query()->lockForUpdate()->findOrFail($code);
            $data = $request->validated();

            $deactivates = ($data['is_active'] ?? true) === false && $currency->is_active;
            $changesUsdRate = isset($data['rate_per_usd']) && (float) $data['rate_per_usd'] !== 1.0;
            if ($code === 'USD' && ($deactivates || $changesUsdRate)) {
                return $this->conflict('currency_usd_immutable');
            }
            if ($deactivates && $this->isAssigned($code)) {
                return $this->conflict('currency_in_use');
            }

            $currency->fill($data);
            $changed = array_keys($currency->getDirty());
            if ($changed === []) {
                return $currency;
            }

            $before = ['code' => $code] + Arr::only($currency->getOriginal(), $changed);
            $currency->save();
            $after = ['code' => $code] + Arr::only($currency->getAttributes(), $changed);
            $this->audit->record($request->user()?->id, 'currency.updated', 'Currency', null, $before, $after);

            return $currency;
        });

        return $result instanceof JsonResponse ? $result : new CurrencyResource($result->refresh());
    }

    /**
     * Delete a currency nobody uses.
     *
     * @throws AuthorizationException
     */
    public function destroy(Request $request, string $code): Response|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        return DB::transaction(function () use ($request, $code): Response|JsonResponse {
            $currency = Currency::query()->lockForUpdate()->findOrFail($code);

            if ($code === 'USD') {
                return $this->conflict('currency_usd_immutable');
            }
            if ($this->isAssigned($code)) {
                return $this->conflict('currency_in_use');
            }

            $before = $currency->only(['code', 'symbol', 'decimals', 'rate_per_usd', 'rate_as_of', 'is_active']);
            $currency->delete();
            $this->audit->record($request->user()?->id, 'currency.deleted', 'Currency', null, $before, null);

            return response()->noContent();
        });
    }

    private function isAssigned(string $code): bool
    {
        return Organization::query()->where('currency_code', $code)->exists();
    }
}
