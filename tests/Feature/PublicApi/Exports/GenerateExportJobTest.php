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
