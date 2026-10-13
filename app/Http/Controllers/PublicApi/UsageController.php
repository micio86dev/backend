<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\ApiKeyMode;
use App\Exceptions\PublicApi\QueryValidationException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Rules\PublicApi\Iso8601DateTime;
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\QueryFilters;
use App\Support\PublicApi\UsageAggregator;
use App\Support\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * `GET /v1/usage`.
 *
 * The organization is resolved explicitly from `TenantResolver` (`Participant` is a
 * plain model); the tenant-scoped models read here are already filtered by the
 * ambient tenant scope that `PublicApiTenantContext` stamps.
 */
final class UsageController extends Controller
{
    /**
     * Get usage.
     *
     * Returns the organization's usage figures for the key's mode over the reporting window set by `from` and `to`.
     */
    #[Response(200, description: 'Usage summary for the requested window.')]
    #[Response(400, description: 'Malformed query parameter, or from is after to (code=validation_failed).', type: Problem::PROBLEM_SHAPE)]
    #[QueryParameter('from', description: 'Start of the reporting window. Strict ISO 8601 date-time. Defaults to the start of the current month (UTC). from > to answers 400 validation_failed.', type: 'string')]
    #[QueryParameter('to', description: 'End of the reporting window. Strict ISO 8601 date-time. Defaults to now (UTC).', type: 'string')]
    public function show(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $this->validateFilters($request);

        $from = $this->resolveFrom($request);
        $to = $this->resolveTo($request);

        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        return PublicApiJson::response(
            UsageAggregator::build($organization, $mode, $from, $to),
        );
    }

    /**
     * Unreachable through the real `/v1` stack (`PublicApiTenantContext`
     * already 401s first for a missing/invalid org) — resolved explicitly
     * rather than trusted, same defensive discipline as every other `/v1`
     * controller's own `resolveOrganization()`.
     */
    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        return $organization;
    }

    /**
     * `from` defaults to the start of the current UTC calendar month
     * — never "all time":
     * unlike `App\Support\Admin\DashboardDateRange` (whose absent bound
     * means "no filter"), this endpoint's contract states an explicit
     * default window.
     */
    private function resolveFrom(Request $request): CarbonImmutable
    {
        $raw = $request->query('from');

        if (is_string($raw) && $raw !== '') {
            $parsed = Iso8601DateTime::parse($raw);

            // Converted to UTC so the SQL bound and the echoed `from` carry the same instant.
            if ($parsed !== null) {
                return $parsed->utc();
            }
        }

        return CarbonImmutable::now('UTC')->startOfMonth();
    }

    /**
     * `to` defaults to now (UTC).
     */
    private function resolveTo(Request $request): CarbonImmutable
    {
        $raw = $request->query('to');

        if (is_string($raw) && $raw !== '') {
            $parsed = Iso8601DateTime::parse($raw);

            // Converted to UTC, as in resolveFrom().
            if ($parsed !== null) {
                return $parsed->utc();
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * `from > to` answers `400 validation_failed` (a bad query parameter is `400`, never `422`).
     *
     * Validated on the effective pair after defaults, via the same resolvers `show()`
     * queries with, so a one-sided request with an inverted effective window is still refused.
     */
    private function validateFilters(Request $request): void
    {
        // An empty bound is "not provided": see `QueryFilters`.
        $validator = Validator::make(QueryFilters::provided($request), [
            'from' => ['sometimes', 'string', new Iso8601DateTime],
            'to' => ['sometimes', 'string', new Iso8601DateTime],
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($request): void {
            $fromParsed = $this->resolveFrom($request);
            $toParsed = $this->resolveTo($request);

            if ($fromParsed->greaterThan($toParsed)) {
                $validator->errors()->add('to', 'The to must not be before from.');
            }
        });

        if ($validator->fails()) {
            throw new QueryValidationException($validator);
        }
    }
}
