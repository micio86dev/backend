<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\ApiKeyMode;
use App\Enums\ExportFormat;
use App\Enums\ExportScope;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicApi\CreateExportRequest;
use App\Jobs\PublicApi\GenerateExportJob;
use App\Models\Export;
use App\Models\Organization;
use App\PublicApi\Serializers\ExportSerializer;
use App\Rules\PublicApi\Iso8601DateTime;
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\CursorPage;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicApiJson;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `POST /v1/exports`, `GET /v1/exports` and `GET /v1/exports/{id}` — BEAI
 * Public API (public-api step 8), SPEC.md §3.3 "Exports", `openapi.yaml`'s
 * `createExport`/`listExports`/`getExport` operations.
 *
 * `Export` IS a `TenantModel` (unlike `Participant`/`WebhookDelivery`'s own
 * split — see each model's own docblock) — every query here filters
 * `organization_id` EXPLICITLY anyway (D22: stated, never relied on
 * transitively), mirroring `WebhookDeliveryController`'s identical
 * discipline for the same reason.
 */
final class ExportController extends Controller
{
    /**
     * Download URL TTL — `openapi.yaml`'s own `Export.download_url`
     * description: "Signed, valid 1 hour from `ready`."
     */
    private const DOWNLOAD_TTL_MINUTES = 60;

    #[Response(202, description: 'Export job accepted.')]
    #[Response(429, description: 'An export is already in progress for this organization (code=export_in_progress).', type: Problem::PROBLEM_SHAPE)]
    public function store(CreateExportRequest $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        $fromRaw = $request->input('from');
        $toRaw = $request->input('to');

        $attributes = [
            'mode' => $mode,
            'scope' => ExportScope::from($request->string('scope')->toString()),
            'format' => ExportFormat::from($request->string('format')->toString()),
            'from_at' => is_string($fromRaw) ? Iso8601DateTime::parse($fromRaw) : null,
            'to_at' => is_string($toRaw) ? Iso8601DateTime::parse($toRaw) : null,
            'include_transcripts' => $request->boolean('include_transcripts', true),
            'include_scoring' => $request->boolean('include_scoring', true),
            'include_audio' => $request->boolean('include_audio', false),
            'status' => ExportStatus::Queued,
        ];

        try {
            // DB::transaction() (mirrors App\Services\Webhooks\
            // WebhookDeliveryRecorder::record()'s own identical discipline,
            // same docblock reasoning): a caught
            // UniqueConstraintViolationException must roll back to a
            // SAVEPOINT, not abort the whole request-level transaction —
            // without this, every statement after the catch (including this
            // very 429 response's own request-id stamping, which reads
            // through the same connection) would fail with "current
            // transaction is aborted" under RefreshDatabase-wrapped tests
            // and under any real caller nested inside an outer transaction.
            $export = DB::transaction(fn (): Export => Export::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return Problem::make(
                $request, 429, 'export_in_progress', 'Export in progress',
                'Only one export can be in progress per organization at a time.',
            );
        }

        GenerateExportJob::dispatch($export->id);

        return PublicApiJson::response(ExportSerializer::toArray($export), 202);
    }

    #[Response(200, description: 'A page of this organization\'s export jobs, newest first.')]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;

        $query = Export::query()->where('organization_id', $organization->id)->where('mode', $mode);

        $page = CursorPage::paginate(
            $query,
            $request,
            fn (Export $export): array => ExportSerializer::toArray($export, ...$this->downloadUrlFor($export, $organization)),
        );

        return response()->json($page);
    }

    #[Response(200, description: 'The export job, with a fresh signed download URL when ready.')]
    public function show(Request $request, string $id): JsonResponse
    {
        $organization = $this->resolveOrganization();
        $mode = app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live;
        $export = $this->resolveExport($id, $organization, $mode);

        if ($export === null) {
            abort(404);
        }

        [$url, $expiresAt] = $this->downloadUrlFor($export, $organization);

        return PublicApiJson::response(ExportSerializer::toArray($export, $url, $expiresAt));
    }

    /**
     * Signs a fresh download URL at READ time — never stored — for a
     * `ready` export whose object key is genuinely bound to this
     * organization's own prefix. Mirrors `RecordingController::show()`'s
     * identical "structural org-binding guard, independent of the row
     * already being tenant-scoped" discipline: a key that does not start
     * with `exports/{org_id}/` is refused, never presigned, even though the
     * row itself is already `organization_id`-scoped.
     *
     * @return array{0: string|null, 1: CarbonImmutable|null}
     */
    private function downloadUrlFor(Export $export, Organization $organization): array
    {
        if ($export->status !== ExportStatus::Ready || $export->object_key === null) {
            return [null, null];
        }

        $requiredPrefix = 'exports/'.$organization->id.'/';

        if (! str_starts_with($export->object_key, $requiredPrefix)) {
            return [null, null];
        }

        $expiresAt = CarbonImmutable::now()->addMinutes(self::DOWNLOAD_TTL_MINUTES);

        try {
            $url = Storage::disk()->temporaryUrl($export->object_key, $expiresAt);
        } catch (Throwable $e) {
            Log::error('public-api: failed to generate a signed export download URL', [
                'organization_id' => $organization->id,
                'export_id' => $export->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [null, null];
        }

        return [$url, $expiresAt];
    }

    /**
     * Unreachable through the real `/v1` stack (`PublicApiTenantContext`
     * already 401s first) — resolved explicitly rather than trusted, same
     * defensive discipline as every other `/v1` controller's own
     * `resolveOrganization()`.
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
     * `mode`-scoped (public-api pre-commit gate, round 3, HIGH finding 1): a
     * `beai_test_` key must never read a `live` export row (or vice versa) —
     * SPEC.md §3.7's "must never read or write live data" applied here the
     * same way `UsageAggregator`/`GenerateExportJob::buildContent()` already
     * scope every other export/usage read by the requesting key's own mode.
     */
    private function resolveExport(string $rawId, Organization $organization, ApiKeyMode $mode): ?Export
    {
        $bareId = PublicId::decode($rawId, Export::publicIdPrefix());

        if ($bareId === null) {
            return null;
        }

        return Export::where('organization_id', $organization->id)
            ->where('mode', $mode)
            ->wherePublicId($bareId)
            ->first();
    }
}
