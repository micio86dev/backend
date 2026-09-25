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
 * `Export` IS a `TenantModel`, like `WebhookDelivery` (unlike `Participant`,
 * a plain model — see each model's own docblock) — every query here filters
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

    /**
     * Explicit `type:` on every 2xx `#[Response]` below (never left to
     * Scramble's own static inference) — `download_url`/`download_expires_at`
     * are always literally `null` at the call site inside `store()` (no
     * download exists yet for a just-`queued` row) and are threaded through
     * an array-destructured `downloadUrlFor()` call in `show()`/`index()`;
     * both shapes defeat Scramble's static analyzer (it narrowed the first
     * to a bare `"null"` literal type and the second to non-nullable
     * `"string"`), producing a schema that disagreed with
     * `ExportSerializer::toArray()`'s own accurate `string|null` PHPDoc and
     * with the actual runtime response. Same fix, same reasoning
     * `WebhookDeliveryController`'s own `#[Response]` attributes already
     * apply for an identical nullable-field drift.
     */
    private const EXPORT_SHAPE = 'array{id: string, status: string, scope: string, format: string, livemode: bool, from: string|null, to: string|null, record_count: int|null, download_url: string|null, download_expires_at: string|null, size_bytes: int|null, checksum_sha256: string|null, failure_reason: string|null, created_at: string, completed_at: string|null}';

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
            // ->utc() (pre-commit gate, round 4, finding 2): a numeric-
            // offset value (e.g. `+02:00`) parses into a CarbonImmutable
            // whose OWN timezone carries that offset, not UTC.
            // Eloquent's `datetime` cast formats the Carbon instance in ITS
            // OWN timezone when writing to the DB (`HasAttributes::
            // fromDateTime()` never normalizes to UTC), so an unconverted
            // value would persist its LOCAL wall-clock digits as if they
            // were already UTC — silently shifting the stored instant by
            // the offset, and `GenerateExportJob::buildContent()`'s own
            // `from_at`/`to_at` window filter would then compare against
            // the wrong instant too. Same fix, same reasoning as
            // `InterviewController::index()`'s own identical `->utc()` call.
            'from_at' => is_string($fromRaw) ? Iso8601DateTime::parse($fromRaw)?->utc() : null,
            'to_at' => is_string($toRaw) ? Iso8601DateTime::parse($toRaw)?->utc() : null,
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
        } catch (UniqueConstraintViolationException $e) {
            // Constraint-checked (pre-commit gate, round 5, finding 6): the ONLY
            // unique index this INSERT can legitimately race on for the
            // `export_in_progress` story is the partial index below
            // (database/migrations/2026_09_24_160000_create_exports_table.php).
            // `exports` also carries `exports_public_id_unique` — collapsing THAT
            // (an unrelated, astronomically unlikely public_id collision) into the
            // same 429 would misreport a genuinely different failure as "an export
            // is already in progress". `$e->index` is populated by
            // `Illuminate\Database\Connection::runQueryCallback()` for every
            // Postgres unique-violation, so this is a real check, not a comment
            // promising one. Anything else rethrows and is picked up by
            // `PublicApiExceptionRenderer`'s own generic `default => 500
            // internal_error` arm — the same "let it propagate" outcome
            // `WebhookDeliveryRecorder::record()`'s sibling catch has no analogous
            // narrowing for, because IT only ever races on ITS OWN single unique
            // index.
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

    #[Response(200, description: 'A page of this organization\'s export jobs, newest first.', type: 'array{data: list<'.self::EXPORT_SHAPE.'>, next_cursor: string|null, has_more: bool}')]
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
