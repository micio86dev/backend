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
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `POST /v1/exports`, `GET /v1/exports` and `GET /v1/exports/{id}`.
 *
 * `Export` is a tenant-scoped model; every query still filters `organization_id`
 * explicitly rather than relying on the ambient scope.
 */
final class ExportController extends Controller
{
    /** Signed download URL lifetime: valid 1 hour from ready. */
    private const DOWNLOAD_TTL_MINUTES = 60;

    /**
     * Explicit response shape: Scramble's static inference narrows the always-null
     * `download_url` in `store()` and the destructured pair in `show()`/`index()` wrongly.
     */
    private const EXPORT_SHAPE = 'array{id: string, status: string, scope: string, format: string, livemode: bool, from: string|null, to: string|null, record_count: int|null, download_url: string|null, download_expires_at: string|null, size_bytes: int|null, checksum_sha256: string|null, failure_reason: string|null, created_at: string, completed_at: string|null}';

    /**
     * Create an export.
     *
     * Starts an asynchronous export of the organization's data for the key's mode, optionally limited to a time window. Responds `202` with the export job; poll `GET /exports/{id}` until it is ready. Only one export can be in progress per organization and mode at a time.
     */
    #[Response(202, description: 'Export job accepted.', type: self::EXPORT_SHAPE)]
    #[Response(429, description: 'An export is already in progress for this organization and mode (code=export_in_progress).', type: Problem::PROBLEM_SHAPE)]
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
            // Converted to UTC: a numeric-offset value would otherwise be stored as local wall-clock digits.
            'from_at' => is_string($fromRaw) ? Iso8601DateTime::parse($fromRaw)?->utc() : null,
            'to_at' => is_string($toRaw) ? Iso8601DateTime::parse($toRaw)?->utc() : null,
            'include_transcripts' => $request->boolean('include_transcripts', true),
            'include_scoring' => $request->boolean('include_scoring', true),
            'include_audio' => $request->boolean('include_audio', false),
            'status' => ExportStatus::Queued,
        ];

        try {
            // A savepoint, so the caught unique violation does not abort an outer transaction.
            $export = DB::transaction(fn (): Export => Export::create($attributes));
        } catch (UniqueConstraintViolationException $e) {
            // Only the one-active-export index means "in progress"; any other violation is a different failure.
            if ($e->index !== 'exports_one_active_per_organization') {
                throw $e;
            }

            return Problem::make(
                $request, 429, 'export_in_progress', 'Export in progress',
                'Only one export can be in progress per organization and mode at a time.',
            );
        }

        GenerateExportJob::dispatch($export->id);

        return PublicApiJson::response(ExportSerializer::toArray($export), 202);
    }

    /**
     * List exports.
     *
     * Returns the organization's export jobs for the key's mode in cursor-paginated pages, newest first.
     */
    #[IgnoreResponse(422)]
    #[Response(400, description: 'Malformed query parameter.', type: Problem::PROBLEM_SHAPE)]
    #[Response(200, description: 'A page of this organization\'s export jobs, newest first.', type: 'array{data: list<'.self::EXPORT_SHAPE.'>, next_cursor: string|null, has_more: bool}')]
    #[QueryParameter('cursor', description: 'Opaque pagination cursor from a previous page\'s next_cursor. Omit for the first page. A present but malformed value answers 400 invalid_cursor.', type: 'string')]
    #[QueryParameter('limit', description: 'Page size, 1-100 (default 25). Out of range answers 400 validation_failed.', type: 'integer')]
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

    /**
     * Get an export.
     *
     * Returns one export job. When it is ready, the response carries a freshly signed download URL, valid for one hour. A malformed or unknown id answers `404 not_found`.
     */
    #[Response(200, description: 'The export job, with a fresh signed download URL when ready.', type: self::EXPORT_SHAPE)]
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
     * Signs a fresh download URL at read time (never stored) for a ready export whose
     * object key lives under this organization's own `exports/{org_id}/` prefix.
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

    /** Resolved explicitly rather than trusted: the tenant middleware already answers 401 first. */
    private function resolveOrganization(): Organization
    {
        $orgId = app(TenantResolver::class)->getOrgId();

        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        return $organization;
    }

    /** Mode-scoped: a test key never reads a live export, nor the reverse. */
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
