<?php

declare(strict_types=1);

/**
 * `App\Jobs\PublicApi\GenerateExportJob` — job-internal edge cases not
 * already exercised end-to-end via `ExportTest.php`'s own HTTP-level
 * T-EXP-* coverage: the row-not-found no-op, the `failed()` safety net, the
 * `from`/`to` date-window filter, and the `csv` format branch (only ever
 * created, never actually GENERATED, by the HTTP-level tests).
 *
 * Dispatcher-based per D5 test discipline (mirrors
 * `tests/Feature/Jobs/ScoreEvaluationJobTenancyTest.php`'s own identical
 * rule): every test that actually RUNS generation uses `::dispatch()`, never
 * `->handle()` directly (pre-commit gate, round 3, finding 4) —
 * `QUEUE_CONNECTION=sync` (`phpunit.xml`) still runs the job inline, but
 * ONLY `::dispatch()` goes through `App\Providers\TenancyServiceProvider`'s
 * `Queue::before` hook, which is the mechanism `GenerateExportJob::handle()`'s
 * own `TenantContextScope::runFor()` wrapping exists to survive. Calling
 * `->handle()` directly bypasses that hook entirely, so those tests were
 * never actually proving tenancy survives a real dispatch — only that the
 * job's OWN explicit re-scoping works when the ambient resolver happened to
 * already be in whatever state the previous test left it. `failed()` is
 * exempt: it is the safety net Laravel's own worker invokes directly when
 * retries are exhausted, never a case `Queue::before` participates in.
 */

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Jobs\PublicApi\GenerateExportJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Export;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('handle() is a no-op when the export row does not exist', function (): void {
    Log::spy();

    GenerateExportJob::dispatch(999999999);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: export row not found'
            && $context['export_id'] === 999999999
        );
});

test('failed() sets the row to failed when it is still non-terminal', function (): void {
    Log::spy();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['status' => ExportStatus::Processing]));

    $job = new GenerateExportJob($export->id);
    $job->failed(new RuntimeException('worker crashed mid-generation'));

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Failed);
    // Stable machine code only — never the raw exception message (gga
    // finding 3): the detail is logged, not stored on the row.
    expect($fresh->failure_reason)->toBe('job_failed_before_generation_completed');
    expect($fresh->completed_at)->not->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: job failed before generation completed'
            && $context['export_id'] === $export->id
            && $context['message'] === 'worker crashed mid-generation'
        );
});

test('failed() is a no-op when the row is already ready', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create());
    $originalReason = $export->failure_reason;

    $job = new GenerateExportJob($export->id);
    $job->failed(new RuntimeException('should not matter'));

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->failure_reason)->toBe($originalReason);
});

test('failed() is a no-op when the export row does not exist', function (): void {
    $job = new GenerateExportJob(999999999);

    // Must not throw — the row-not-found branch simply returns.
    $job->failed(new RuntimeException('irrelevant'));

    expect(true)->toBeTrue();
});

test('the from/to window narrows which interviews are exported', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);

    $inWindow = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    TenantContextScope::runFor($org->id, function () use ($inWindow): void {
        $inWindow->forceFill(['created_at' => now()])->save();
    });

    $outOfWindow = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    TenantContextScope::runFor($org->id, function () use ($outOfWindow): void {
        $outOfWindow->forceFill(['created_at' => now()->subYears(2)])->save();
    });

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'from_at' => now()->subDay(),
        'to_at' => now()->addDay(),
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->record_count)->toBe(1);

    $content = Storage::get($fresh->object_key);
    expect($content)->toContain($inWindow->candidate_ref);
    expect($content)->not->toContain($outOfWindow->candidate_ref);
});

test('format csv actually generates valid CSV content with a header row and one data row per interview', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->object_key)->toEndWith('.csv');

    $csv = Storage::get($fresh->object_key);
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(2);
    expect($lines[0])->toContain('id');
    expect($lines[0])->toContain('candidate_ref');
    expect($lines[1])->toContain($participant->candidate_ref);
});

test('a false boolean field renders as the literal string "false" in a CSV cell, never empty (pre-commit gate, round 5, finding 3)', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    // 'in_corso' is neither under_evaluation nor completato — InterviewSerializer's
    // own gate makes transcript_ready AND scoring_ready both false here.
    Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $csv = Storage::get($fresh->object_key);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);
    $row = array_combine($header, str_getcsv($lines[1]));

    expect($row['transcript_ready'])->toBe('false');
    expect($row['scoring_ready'])->toBe('false');
});

test('an empty organization (no interviews at all) produces an empty CSV body with no header row', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->record_count)->toBe(0);
    expect(Storage::get($fresh->object_key))->toBe('');
});

test('a competency with an integer-valued float score exports with the trailing .0 in both jsonl and the CSV cell', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    $scored = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    // Force the golden-cassette {5,3,3} -> 3.67 result down to a genuinely
    // integer-valued float (3.0) — json_encode(3.0) === "3" without
    // JSON_PRESERVE_ZERO_FRACTION, which is exactly the bug: the SAME
    // score, read live via GET /interviews/{id}/scoring
    // (App\Support\PublicApi\PublicApiJson::response(), which already
    // passes the flag), renders "3.0".
    TenantContextScope::runFor($org->id, function () use ($scored): void {
        $evaluationId = Evaluation::where('participant_id', $scored->id)->value('id');
        CompetencyResult::where('evaluation_id', $evaluationId)->update(['score' => 3.0]);
    });

    $jsonlExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['format' => ExportFormat::Jsonl]));
    GenerateExportJob::dispatch($jsonlExport->id);
    $jsonlFresh = TenantContextScope::runFor($org->id, fn () => Export::find($jsonlExport->id));
    expect($jsonlFresh->status)->toBe(ExportStatus::Ready);
    expect(Storage::get($jsonlFresh->object_key))->toContain('"score":3.0');

    $csvExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['format' => ExportFormat::Csv]));
    GenerateExportJob::dispatch($csvExport->id);
    $csvFresh = TenantContextScope::runFor($org->id, fn () => Export::find($csvExport->id));
    expect($csvFresh->status)->toBe(ExportStatus::Ready);

    $csv = Storage::get($csvFresh->object_key);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $scoringIndex = array_search('scoring', $header, true);

    expect($scoringIndex)->not->toBeFalse();
    expect($row[$scoringIndex])->toContain('"score":3.0');
});

test('a batch of interviews with mixed readiness keeps every CSV row aligned under the same fixed set of headers', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);

    // The domain's own gate order (App\PublicApi\Serializers\
    // InterviewSerializer::toArray(): `scoring_ready` implies
    // `transcript_ready`, never the reverse — completato is the ONLY
    // status with scoring_ready true, and it is also one of the two
    // statuses with transcript_ready true) makes "scoring ready,
    // transcript not" structurally unreachable. These three rows instead
    // exercise the two real ends of that gate order — neither ready,
    // transcript-only, and (transcript AND scoring) together — which is
    // exactly the "different key sets per row" case the union-of-keys fix
    // must still align correctly.
    $neither = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    $transcriptOnly = Step6Fixtures::participantWithTranscript($org, $project, 'in_valutazione');
    $both = Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->record_count)->toBe(3);

    $csv = Storage::get($fresh->object_key);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(4);

    $header = str_getcsv($lines[0]);
    $columnCount = count($header);

    foreach (array_slice($lines, 1) as $line) {
        expect(str_getcsv($line))->toHaveCount($columnCount);
    }

    expect($header)->toContain('transcript');
    expect($header)->toContain('scoring');

    $rowsByCandidateRef = [];
    foreach (array_slice($lines, 1) as $line) {
        $fields = array_combine($header, str_getcsv($line));
        $rowsByCandidateRef[$fields['candidate_ref']] = $fields;
    }

    expect($rowsByCandidateRef[$neither->candidate_ref]['transcript'])->toBe('');
    expect($rowsByCandidateRef[$neither->candidate_ref]['scoring'])->toBe('');

    expect($rowsByCandidateRef[$transcriptOnly->candidate_ref]['transcript'])->not->toBe('');
    expect($rowsByCandidateRef[$transcriptOnly->candidate_ref]['scoring'])->toBe('');

    expect($rowsByCandidateRef[$both->candidate_ref]['transcript'])->not->toBe('');
    expect($rowsByCandidateRef[$both->candidate_ref]['scoring'])->not->toBe('');
});

// ─── Re-delivery / duplicate-dispatch guard (step 8 review, finding 4) ────
//
// GenerateExportJob::handle() previously set status=processing
// unconditionally, with no guard against re-delivery (duplicate dispatch,
// or retry_after shorter than the job timeout) re-processing an already
// ready/failed row — which could also re-occupy the organization's own
// one-active-export-per-mode slot a second time.

test('handle() is a no-op when the export row is already ready — a duplicate dispatch does not regenerate it', function (): void {
    Storage::fake();
    Log::spy();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create([
        'object_key' => 'exports/'.$org->id.'/already-ready.jsonl',
        'checksum_sha256' => hash('sha256', 'original content'),
        'size_bytes' => strlen('original content'),
        'record_count' => 7,
    ]));
    Storage::put($export->object_key, 'original content');

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->checksum_sha256)->toBe(hash('sha256', 'original content'));
    expect($fresh->record_count)->toBe(7);
    expect(Storage::get($export->object_key))->toBe('original content');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: skipped — export row is not queued (duplicate dispatch or re-delivery)'
            && $context['export_id'] === $export->id
            && $context['status'] === 'ready'
        );
});

test('handle() is a no-op when the export row is already failed — a duplicate dispatch does not re-run generation', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->failed()->create());
    $originalReason = $export->failure_reason;

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Failed);
    expect($fresh->failure_reason)->toBe($originalReason);
});

// ─── Re-delivery RECLAIM guard (pre-commit gate, round 4, finding 1) ──────
//
// The previous version of this guard treated ANY `processing` row as "not
// mine to claim", unconditionally — including a row left `processing` by a
// worker that was killed mid-run (hits the 1200s `$timeout`). Laravel's own
// `$tries=3` then REDELIVERS the same job, but the old guard silently
// no-opped on that redelivery and returned SUCCESSFULLY, so `failed()`
// never ran and the row was stuck `processing` forever. `attempts() > 1` is
// what tells the two cases apart — see `GenerateExportJob::handle()`'s own
// updated guard comment for the full reasoning (`retry_after` > `$timeout`
// guarantees the previous attempt's lease has already expired by the time a
// redelivery is observed).
//
// `attempts()` under this repo's `sync` queue connection (phpunit.xml,
// ci.yml) is hardcoded to 1 forever — a genuine attempts()>1 redelivery can
// never be produced by `::dispatch()`ing and waiting under `sync`. These two
// tests instead inject a Mockery double of `Illuminate\Contracts\Queue\Job`
// via `setJob()` (`InteractsWithQueue`'s own public property) and call
// `->handle()` directly — mirrors `tests/Feature/C10/DeliverWebhookJobTest.php`'s
// own established `c10JobAtAttempt()` pattern for the identical reason. This
// is an explicit, documented exception to this file's own class-doc
// "::dispatch(), never ->handle()" discipline, the same class of exemption
// already granted to `failed()` above: `GenerateExportJob`'s own
// `TenantContextScope::runFor()` wrapping re-establishes tenant context
// explicitly for every read/write regardless of how `handle()` was invoked,
// so calling it directly here does not weaken the tenancy coverage that
// discipline exists to prove.

test('handle() reclaims and completes a row left processing by a crashed prior attempt (attempts()>1)', function (): void {
    Storage::fake();
    Log::spy();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    // Simulates the crash: a PRIOR attempt claimed the row (queued ->
    // processing) and then died before reaching a terminal status.
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['status' => ExportStatus::Processing]));

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);

    $job = new GenerateExportJob($export->id);
    $job->setJob($queueJob);
    $job->handle();

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);
    expect($fresh->record_count)->toBe(1);
    expect($fresh->object_key)->not->toBeNull();
    expect(Storage::exists($fresh->object_key))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: reclaiming a row left processing by a crashed prior attempt'
            && $context['export_id'] === $export->id
            && $context['attempts'] === 2
        );
});

test('handle() is still a no-op when the export row is processing on the FIRST attempt — no lease has had time to expire yet', function (): void {
    Log::spy();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['status' => ExportStatus::Processing]));

    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);

    $job = new GenerateExportJob($export->id);
    $job->setJob($queueJob);
    $job->handle();

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Processing);
    expect($fresh->object_key)->toBeNull();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: skipped — export row is not queued (duplicate dispatch or re-delivery)'
            && $context['export_id'] === $export->id
            && $context['status'] === 'processing'
        );
});

// ─── include_transcripts / include_scoring flags (step 8 review, finding 5) ─

test('include_transcripts=false omits the transcript key even when the interview is transcript_ready', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::participantWithTranscript($org, $project, 'in_valutazione');

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'include_transcripts' => false,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $record = json_decode(trim(Storage::get($fresh->object_key)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['transcript_ready'])->toBeTrue();
    expect($record)->not->toHaveKey('transcript');
});

test('include_scoring=false omits the scoring key even when the interview is scoring_ready', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'include_scoring' => false,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $record = json_decode(trim(Storage::get($fresh->object_key)), true, flags: JSON_THROW_ON_ERROR);
    expect($record['scoring_ready'])->toBeTrue();
    expect($record)->not->toHaveKey('scoring');
});

test('include_transcripts=true and include_scoring=true (factory default) include both keys when the interview is ready for them', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::buildCompletedScoredParticipant($org, $project);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create());

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $record = json_decode(trim(Storage::get($fresh->object_key)), true, flags: JSON_THROW_ON_ERROR);
    expect($record)->toHaveKey('transcript');
    expect($record)->toHaveKey('scoring');
});

test('a CSV cell value starting with a formula character is prefixed with a leading apostrophe', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    TenantContextScope::runFor($org->id, function () use ($participant): void {
        $participant->forceFill(['display_name' => '=1+1'])->save();
    });

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $csv = Storage::get($fresh->object_key);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $displayNameIndex = array_search('display_name', $header, true);

    expect($displayNameIndex)->not->toBeFalse();
    expect($row[$displayNameIndex])->toBe("'=1+1");
});

test('a CSV cell value starting with a tab character is prefixed with a leading apostrophe (pre-commit gate round 4, finding 4 — OWASP CSV injection guidance)', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    // A leading TAB, not one of the four characters (=,+,-,@) the original
    // guard covered — OWASP's own CSV injection guidance also flags a
    // leading tab/carriage-return: some spreadsheet parsers still evaluate
    // a formula that starts with whitespace before the `=`/`+`/`-`/`@`.
    TenantContextScope::runFor($org->id, function () use ($participant): void {
        $participant->forceFill(['display_name' => "\t=1+1"])->save();
    });

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $csv = Storage::get($fresh->object_key);
    expect($csv)->toContain("'\t=1+1");
});

test('a CSV cell value starting with a carriage return is prefixed with a leading apostrophe (pre-commit gate round 4, finding 4 — OWASP CSV injection guidance)', function (): void {
    Storage::fake();

    ['org' => $org] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    TenantContextScope::runFor($org->id, function () use ($participant): void {
        $participant->forceFill(['display_name' => "\r=1+1"])->save();
    });

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'format' => ExportFormat::Csv,
    ]));

    GenerateExportJob::dispatch($export->id);

    $fresh = TenantContextScope::runFor($org->id, fn () => Export::find($export->id));
    expect($fresh->status)->toBe(ExportStatus::Ready);

    $csv = Storage::get($fresh->object_key);
    expect($csv)->toContain("'\r=1+1");
});
