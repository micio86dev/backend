<?php

declare(strict_types=1);

namespace App\Jobs\PublicApi;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Models\Evaluation;
use App\Models\Export;
use App\Models\Participant;
use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\ScoringSerializer;
use App\PublicApi\Serializers\TranscriptSerializer;
use App\Support\PublicApi\IncludeTrashed;
use App\Support\Tenancy\TenantContextScope;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * `GenerateExportJob` — the async worker behind `POST /v1/exports`
 * (public-api step 8, SPEC.md §3.3 "Exports"/§3.4 "everything the admin
 * sees minus exclusions").
 *
 * Payload is a SCALAR `int $exportId` — never a model, mirroring
 * `App\Jobs\DeliverWebhookJob`/`App\Jobs\ScoreEvaluationJob`'s own identical
 * choice (a `TenantModel` re-resolved through `SerializesModels` under a
 * null ambient queue-context resolver is the exact bug those two classes'
 * own docblocks document avoiding).
 *
 * Tenancy: `Export::withoutGlobalScopes()->find()` (explicit-id read, no
 * context needed — same as `DeliverWebhookJob::handle()`'s own `$delivery`
 * lookup). Every WRITE to the export row is wrapped in
 * `TenantContextScope::runFor($export->organization_id, …)` so the tenant-
 * scoped UPDATE matches under a null ambient resolver.
 *
 * REUSES the existing public-API serializers (`InterviewSerializer`,
 * `TranscriptSerializer`, `ScoringSerializer`) rather than duplicating their
 * exposure logic — SPEC.md §3.4's "one serializer per resource, shared by
 * the public API and the export" requirement verbatim. `transcript`/
 * `scoring` are included per record only when that interview's OWN read
 * gate is actually open (`transcript_ready`/`scoring_ready`, the same flags
 * `GET /interviews/{id}` itself reports) — an export must never show MORE
 * than the equivalent live reads would for the SAME interview.
 *
 * **`include_audio` (G-item, disclosed rather than silently resolved):**
 * `CreateExportRequest.include_audio` is accepted and stored on the row,
 * but this first cut does not bundle audio bytes into the archive — JSONL/
 * CSV are text formats, and a real audio bundle needs its own archive
 * format (e.g. a zip/tar alongside the text export) that this slice does
 * not build. A `true` value is a no-op today; nothing in the exported
 * content changes because of it. Flagged for the decisions log rather than
 * silently ignored.
 *
 * **Format is written whole, not truly streamed** (records are collected
 * into one in-memory buffer before a single `Storage::put()`) — the same
 * "compute in PHP, revisit at scale" posture `App\Http\Controllers\Api\
 * DashboardController`'s own docblock already states for a bounded,
 * org-scoped dataset. An organization's own interview count is bounded by
 * real usage, not by an unbounded global table.
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Batch size for the participant read. Bounds the DB QUERY only (one
     * `LIMIT`/`OFFSET` page at a time) — every row read still accumulates
     * into `$rows`/one in-memory string before the single `Storage::put()`
     * (see class doc's "Format is written whole" note), so this constant
     * does NOT bound this job's own memory footprint.
     */
    private const CHUNK_SIZE = 200;

    /**
     * Queue-level attempt ceiling (queue-runtime/spec.md Requirement 4 —
     * `tests/Arch/Queue/QueuedJobRetryOwnershipArchTest.php` — every
     * `ShouldQueue` job must declare its own retry ownership, never
     * silently inherit the worker's `--tries`). Mirrors
     * `App\Jobs\ScoreEvaluationJob`'s own choice: a SAFETY NET for a
     * transient infra failure (DB blip, storage hiccup) during generation,
     * never a substitute for the export's own `failed` terminal status —
     * `handle()`'s own try/catch already converts any generation-time
     * exception into `failed` without throwing, so this ceiling is only
     * ever exercised by something that escapes that catch entirely (e.g. an
     * OOM, or a crash between the catch and `persist()`).
     */
    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(
        private readonly int $exportId,
    ) {}

    public function handle(): void
    {
        /** @var Export|null $export */
        $export = Export::withoutGlobalScopes()->find($this->exportId);

        if ($export === null) {
            Log::warning('GenerateExportJob: export row not found', ['export_id' => $this->exportId]);

            return;
        }

        // Re-delivery/duplicate-dispatch guard (pre-commit gate, round 4,
        // finding 1 — CRITICAL: the previous version of this guard could
        // permanently strand an organization's export).
        //
        // `ready`/`failed`/`expired` are always a genuine duplicate — those
        // are TERMINAL statuses (generation already reached a final state),
        // so this invocation never has anything legitimate to do and must
        // always no-op.
        //
        // `processing` is NOT always a duplicate, and treating it as one
        // unconditionally (the old rule) is the bug: if a worker is killed
        // mid-run (hits the 1200s `$timeout`), the row is left `processing`
        // with no job left to act on it — `$tries=3` then makes Laravel
        // itself REDELIVER the same job, but the old guard silently no-opped
        // on that redelivery and returned SUCCESSFULLY, so `failed()` never
        // ran and the row was stuck `processing` forever. `retry_after`
        // (1500s, `config/queue.php`) is greater than the worker `$timeout`
        // (1200s), so a SECOND delivery that finds the row still
        // `processing` proves the FIRST attempt's lease has already
        // expired by the time this one runs — there is no genuinely
        // in-flight attempt left to race against.
        //
        // `$this->attempts() > 1` is exactly that signal (Laravel's own
        // per-DISPATCH attempt counter — 1 on the very first delivery,
        // incremented on every redelivery). A `processing` row on attempt 1
        // means something else claimed it moments ago (this job's own
        // `persist()` call below hasn't run yet on THIS attempt) — the
        // genuinely rare concurrent-duplicate case, where no lease has had
        // time to expire — and the conservative choice there is still to
        // no-op rather than race a second regeneration against the first.
        // A `processing` row on attempt N>1 means the PREVIOUS attempt
        // died: reclaim it and regenerate, rather than fail it outright,
        // because `buildContent()` is idempotent/replayable — it always
        // builds `$content` fresh from the current DB state into one
        // in-memory buffer, and the single `Storage::put()` at the end
        // OVERWRITES the same object key (class doc, "Format is written
        // whole") — so a reclaimed regeneration converges on the same
        // correct result a healthy first attempt would have, with no
        // partial/appended state to worry about. The job's own EXISTING
        // try/catch below still converts a genuine generation failure to
        // `failed` either way, so this never trades "stuck processing
        // forever" for "silently wrong ready" — at worst it retries once
        // more and then fails cleanly.
        $isReclaimableProcessing = $export->status === ExportStatus::Processing && $this->attempts() > 1;

        if ($export->status !== ExportStatus::Queued && ! $isReclaimableProcessing) {
            Log::info('GenerateExportJob: skipped — export row is not queued (duplicate dispatch or re-delivery)', [
                'export_id' => $export->id,
                'status' => $export->status->value,
            ]);

            return;
        }

        if ($isReclaimableProcessing) {
            Log::warning('GenerateExportJob: reclaiming a row left processing by a crashed prior attempt', [
                'export_id' => $export->id,
                'attempts' => $this->attempts(),
            ]);
        }

        $this->persist($export, function () use ($export): void {
            $export->forceFill(['status' => ExportStatus::Processing])->save();
        });

        try {
            // buildContent() reads Evaluation/InterviewSession — both
            // TenantModels whose global scope needs an ambient organization
            // id. Laravel's own queue worker (via
            // App\Providers\TenancyServiceProvider's Queue::before/restore
            // hook — see Tests\Helpers\PublicApi\Step6Fixtures::
            // buildCompletedScoredParticipant()'s own docblock for the exact
            // mechanism) resets the ambient TenantResolver to null before
            // ANY queued job's handle() runs, so these reads need the SAME
            // explicit TenantContextScope::runFor() wrapping every WRITE in
            // this class already uses — without it, every TenantModel query
            // below silently matches zero rows rather than throwing, and
            // `transcript`/`scoring` would silently vanish from every
            // export's content instead of failing loudly.
            [$content, $recordCount] = TenantContextScope::runFor(
                $export->organization_id,
                fn (): array => $this->buildContent($export),
            );

            $extension = $export->format === ExportFormat::Csv ? 'csv' : 'jsonl';
            $objectKey = sprintf('exports/%d/%d.%s', $export->organization_id, $export->id, $extension);

            Storage::disk()->put($objectKey, $content);
        } catch (Throwable $e) {
            Log::error('GenerateExportJob: generation failed', [
                'export_id' => $export->id,
                'organization_id' => $export->organization_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // A stable machine code, never `$e->getMessage()` (gga finding
            // 3): `failure_reason` is returned VERBATIM to an external
            // API-key caller by `ExportSerializer::toArray()`, and a
            // `QueryException`/storage-driver message can carry SQL, bound
            // values, or bucket/endpoint details. The real detail is
            // already in the `Log::error()` call above — this row only
            // ever needs to say WHAT happened, not the raw exception text.
            $this->persist($export, function () use ($export): void {
                $export->forceFill([
                    'status' => ExportStatus::Failed,
                    'failure_reason' => 'generation_failed',
                    'completed_at' => now(),
                ])->save();
            });

            return;
        }

        $this->persist($export, function () use ($export, $objectKey, $content, $recordCount): void {
            $export->forceFill([
                'status' => ExportStatus::Ready,
                'object_key' => $objectKey,
                'size_bytes' => strlen($content),
                'checksum_sha256' => hash('sha256', $content),
                'record_count' => $recordCount,
                'completed_at' => now(),
            ])->save();
        });
    }

    /**
     * Safety net (mirrors `DeliverWebhookJob::failed()`'s own discipline):
     * a genuinely unexpected exception (queue infra, DB error) that
     * exhausts Laravel's own retry mechanism must not strand the row
     * `processing` forever with no job left to act on it.
     */
    public function failed(Throwable $e): void
    {
        /** @var Export|null $export */
        $export = Export::withoutGlobalScopes()->find($this->exportId);

        if ($export === null || $export->status === ExportStatus::Ready) {
            return;
        }

        Log::error('GenerateExportJob: job failed before generation completed', [
            'export_id' => $export->id,
            'organization_id' => $export->organization_id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        // Stable machine code only — see the identical `handle()` catch
        // block's own comment (gga finding 3): the raw exception detail is
        // logged above, never stored on a row an external caller reads.
        $this->persist($export, function () use ($export): void {
            $export->forceFill([
                'status' => ExportStatus::Failed,
                'failure_reason' => 'job_failed_before_generation_completed',
                'completed_at' => now(),
            ])->save();
        });
    }

    /**
     * @return array{0: string, 1: int} [content, record_count]
     */
    private function buildContent(Export $export): array
    {
        $query = Participant::query()
            ->where('organization_id', $export->organization_id)
            ->where('mode', $export->mode)
            ->with(['project' => fn (Relation $relation): Builder => IncludeTrashed::forRelation($relation)->select(['id', 'public_id'])]);

        if ($export->from_at !== null) {
            $query->where('created_at', '>=', $export->from_at);
        }

        if ($export->to_at !== null) {
            $query->where('created_at', '<=', $export->to_at);
        }

        $rows = [];

        $query->orderBy('id')->chunk(self::CHUNK_SIZE, function (Collection $participants) use ($export, &$rows): void {
            $recordingReadyByParticipant = InterviewSerializer::recordingReadyForMany($participants);

            // One query per CHUNK, not one per participant (gga N+1 finding):
            // `buildRecord()` used to run its own `Evaluation::where(...)->exists()`
            // per participant inside this loop. Batched the same way
            // `recordingReadyForMany()` already batches recording readiness above.
            // is_numeric() narrows the raw `mixed` a plucked DB column value
            // carries (never a checked-cast silencer) — same discipline as
            // App\Support\PublicApi\UsageAggregator::toInt()'s own identical
            // helper, for the identical reason: a `participant_id` this
            // query itself selected is always numeric, but PHPStan cannot
            // know that from `pluck()`'s own `mixed` return type.
            $participantIdsWithEvaluation = Evaluation::query()
                ->whereIn('participant_id', $participants->pluck('id'))
                ->pluck('participant_id')
                ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();
            $hasEvaluationByParticipant = array_fill_keys($participantIdsWithEvaluation, true);

            foreach ($participants as $participant) {
                $rows[] = $this->buildRecord(
                    $export,
                    $participant,
                    $recordingReadyByParticipant[$participant->id] ?? false,
                    $hasEvaluationByParticipant[$participant->id] ?? false,
                );
            }
        });

        $content = $export->format === ExportFormat::Csv
            ? $this->toCsv($rows)
            : $this->toJsonl($rows);

        return [$content, count($rows)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecord(Export $export, Participant $participant, bool $recordingReady, bool $hasEvaluation): array
    {
        $record = InterviewSerializer::toArray($participant, expandProject: false, recordingReady: $recordingReady);

        if ($export->include_transcripts && $record['transcript_ready']) {
            $record['transcript'] = TranscriptSerializer::toArray($participant);
        }

        if ($export->include_scoring && $record['scoring_ready'] && $hasEvaluation) {
            $record['scoring'] = (new ScoringSerializer)->toArray($participant);
        }

        return $record;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function toJsonl(array $rows): string
    {
        return implode("\n", array_map(
            // JSON_PRESERVE_ZERO_FRACTION (gga finding 1) — without it, a
            // whole-number BARS score (e.g. `3.0`) renders as the bare
            // integer `3`, indistinguishable from a genuinely integer
            // field, and disagrees with the SAME score read live via
            // `GET /interviews/{id}/scoring`
            // (`App\Support\PublicApi\PublicApiJson::response()` already
            // applies this flag to every `/v1` JSON response).
            fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $rows,
        ));
    }

    /**
     * A deliberately simple flat CSV: every TOP-LEVEL scalar field becomes
     * its own column; every nested structure (`metadata`, `progress`,
     * `transcript`, `scoring`) is JSON-encoded into a single cell. A fully
     * normalized multi-table CSV export is out of scope for this first cut
     * (G-item, disclosed) — CSV is offered for spreadsheet consumers who
     * want the interview-level fields directly, not as a lossless
     * transcript/scoring interchange format (JSONL already is one).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        // The union of keys across EVERY row, never just $rows[0]'s own
        // keys (gga finding 2): `transcript`/`scoring` are only present on
        // a given row when THAT interview's own read gate is open (see
        // `buildRecord()`), so rows can carry different key sets. Writing
        // `array_keys($rows[0])` as the header while each row was written
        // positionally in its OWN key order silently shifted columns
        // between rows the instant readiness diverged. First-seen order
        // across all rows keeps the header (and therefore every row)
        // deterministic.
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }
        $columns = array_keys($columns);

        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('GenerateExportJob: could not open an in-memory stream for CSV generation.');
        }

        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            // Read every column in the SAME fixed order for every row,
            // filling '' for a key this particular row does not carry —
            // never `array_map` over the row's own (possibly narrower or
            // differently-ordered) key set.
            $line = array_map(
                fn (string $column): string => self::csvCell($row[$column] ?? null),
                $columns,
            );
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * One CSV cell's string rendering — a scalar renders as itself (`null`
     * as `''`), a nested structure (list/map) as its own JSON encoding (see
     * `toCsv()`'s own docblock for why). Narrows the genuinely `mixed`
     * per-field value defensively rather than casting it unchecked.
     *
     * `JSON_PRESERVE_ZERO_FRACTION` (gga finding 1) — same reasoning as
     * `toJsonl()`'s own identical flag: a nested `scoring` blob's
     * whole-number BARS score must render `3.0`, never the bare `3`
     * `json_encode()` produces without it.
     *
     * CSV formula injection (gga finding 4) — a cell whose rendered text
     * starts with `=`, `+`, `-` or `@` executes as a formula the instant a
     * spreadsheet tool (Excel/Sheets, exactly this export's audience)
     * opens it. Candidate-controlled text (`display_name`, transcript
     * content, metadata values) is never sanitized upstream, so every cell
     * gets the SAME leading-apostrophe guard right before it is written —
     * the one place both a raw scalar and a JSON-encoded blob's rendered
     * text both pass through.
     *
     * Booleans render as the literal strings `true`/`false` (pre-commit gate,
     * round 5, finding 3) — checked BEFORE the generic `is_scalar()` branch,
     * since `bool` is also scalar and `(string) false` casts to `''`,
     * indistinguishable in the CSV from `null` or a column this row simply
     * does not carry (see the union-of-keys reasoning above). `livemode`,
     * `transcript_ready` and `scoring_ready` are exactly the fields this
     * would silently corrupt.
     */
    private static function csvCell(mixed $value): string
    {
        if (is_array($value)) {
            $rendered = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_bool($value)) {
            $rendered = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $rendered = (string) $value;
        } else {
            $rendered = '';
        }

        return self::escapeFormulaPrefix($rendered);
    }

    /**
     * Prefixes a leading `=`, `+`, `-`, `@`, tab (`\t`) or carriage return
     * (`\r`) with a `'` so a spreadsheet application renders the cell as
     * literal text instead of evaluating it as a formula (gga finding 4;
     * tab/carriage-return added per pre-commit gate round 4, finding 4 —
     * OWASP's own CSV injection guidance also flags these two: some
     * spreadsheet parsers still evaluate a formula that starts with
     * leading whitespace before the `=`/`+`/`-`/`@`).
     */
    private static function escapeFormulaPrefix(string $value): string
    {
        return str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            || str_starts_with($value, "\t")
            || str_starts_with($value, "\r")
            ? "'".$value
            : $value;
    }

    /**
     * @param  Closure(): void  $write
     */
    private function persist(Export $export, Closure $write): void
    {
        TenantContextScope::runFor($export->organization_id, $write);
    }
}
