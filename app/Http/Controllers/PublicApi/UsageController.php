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
use App\Support\PublicApi\UsageAggregator;
use App\Support\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * `GET /v1/usage` — BEAI Public API (public-api step 8), SPEC.md §3.3
 * "The dashboard figures" / §3.4 "Usage specifically", `openapi.yaml`'s
 * `getUsage` operation.
 *
 * `Organization` is resolved explicitly from `App\Support\Tenancy\
 * TenantResolver`, mirroring every other `/v1` controller's own docblock
 * (`Participant` is a plain model, not `TenantModel`) — `Evaluation`/
 * `AiRequest`/`InterviewSessionLlmUsage`/`WebhookDelivery` on the other hand
 * ARE `TenantModel`s and are therefore already org-scoped by the ambient
 * `TenantContext` global scope `App\Http\Middleware\PublicApi\
 * PublicApiTenantContext` stamps (`DashboardController`'s own docblock notes
 * the identical reasoning for the admin surface).
 */
final class UsageController extends Controller
{
    #[Response(200, description: 'Usage summary for the requested window.')]
    #[Response(400, description: 'Malformed query parameter, or from is after to (code=validation_failed).', type: Problem::PROBLEM_SHAPE)]
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
     * (`openapi.yaml`'s own parameter description) — never "all time":
     * unlike `App\Support\Admin\DashboardDateRange` (whose absent bound
     * means "no filter"), this endpoint's contract states an explicit
     * default window.
     */
    private function resolveFrom(Request $request): CarbonImmutable
    {
        $raw = $request->query('from');

        if (is_string($raw) && $raw !== '') {
            $parsed = Iso8601DateTime::parse($raw);

            // ->utc() (pre-commit gate, round 4, finding 2): a numeric-
            // offset value (e.g. `+02:00`) parses into a CarbonImmutable
            // whose OWN timezone carries that offset, not UTC. Binding it
            // as-is lets the query builder (`UsageAggregator`'s own
            // `whereBetween()`) format the WALL-CLOCK digits in that
            // offset into the SQL parameter, which Postgres then reads back
            // in the connection's session timezone — silently comparing
            // against the wrong instant. Same fix, same reasoning as
            // `InterviewController::index()`'s own identical `->utc()` call
            // on `created_after`/`created_before`. Converting HERE (not
            // just at the query-binding site) also makes the echoed
            // `from`/`to` response fields reflect the UTC-converted
            // instant, since `show()` reuses this same return value for
            // both.
            if ($parsed !== null) {
                return $parsed->utc();
            }
        }

        return CarbonImmutable::now('UTC')->startOfMonth();
    }

    /**
     * `to` defaults to now (UTC) — `openapi.yaml`'s own parameter description.
     */
    private function resolveTo(Request $request): CarbonImmutable
    {
        $raw = $request->query('to');

        if (is_string($raw) && $raw !== '') {
            $parsed = Iso8601DateTime::parse($raw);

            // ->utc() — see resolveFrom()'s own identical comment.
            if ($parsed !== null) {
                return $parsed->utc();
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * `from > to` answers `400 validation_failed` (task-level rule, not an
     * explicit `openapi.yaml` constraint — the schema declares both as plain
     * `date-time` strings with no cross-field relation). Mirrors
     * `InterviewController::validateFilterFormats()`'s own
     * `QueryValidationException` discipline (G-28: a malformed/inconsistent
     * QUERY PARAMETER is `400`, never `422`).
     *
     * Validated on the EFFECTIVE (post-default) pair, never on the raw
     * request input alone: `resolveFrom()`/`resolveTo()` are the exact
     * methods `show()` itself uses to compute the window it actually
     * queries, so this ordering check and the real read always agree. A
     * one-sided request (only `from`, or only `to`) still has an effective
     * `to`/`from` — `now()` or "start of the current UTC month",
     * respectively — and an inverted EFFECTIVE window must still answer
     * `400`, not silently query backwards and return an all-zero `200`.
     */
    private function validateFilters(Request $request): void
    {
        $validator = Validator::make($request->query(), [
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
