<?php

declare(strict_types=1);

/**
 * `POST /v1/exports`, `GET /v1/exports`, `GET /v1/exports/{id}` — BEAI
 * Public API (public-api step 8), SPEC.md §3.3 "Exports". T-EXP-001..010.
 */

use App\Enums\ApiKeyMode;
use App\Enums\ExportStatus;
use App\Jobs\PublicApi\GenerateExportJob;
use App\Models\ApiClient;
use App\Models\Export;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\ExposureCatalogue;
use Tests\Helpers\PublicApi\Step6Fixtures;

/**
 * Same-organization live/test key pair — mirrors
 * `tests/Feature/PublicApi/Usage/UsageTest.php`'s own T-USAGE-004 fixture
 * exactly (`Step6Fixtures::orgWithScopedKey()` only ever mints a `live`-mode
 * key, so the `test`-mode half is built by hand here the same way).
 *
 * @return array{org: Organization, liveKey: string, testKey: string}
 */
function exportModeKeyPair(array $abilities = ['exports:write', 'exports:read']): array
{
    $org = Organization::factory()->create();

    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create([
        'organization_id' => $org->id,
        'mode' => ApiKeyMode::Live,
        'abilities' => $abilities,
    ]);

    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create([
        'organization_id' => $org->id,
        'mode' => ApiKeyMode::Test,
        'abilities' => $abilities,
    ]);

    return ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey];
}

test('T-EXP-001: create happy path — 202, queued, then dispatches GenerateExportJob', function (): void {
    Queue::fake();

    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);

    $response->assertStatus(202);
    $this->assertMatchesContract($response, 'POST', '/exports');

    $body = $response->json();
    expect($body['id'])->toStartWith('exp_');
    expect($body['status'])->toBe('queued');
    expect($body['scope'])->toBe('interviews');
    expect($body['format'])->toBe('jsonl');
    expect($body['livemode'])->toBeTrue();

    Queue::assertPushed(GenerateExportJob::class);
});

test('T-EXP-002: format csv is accepted and stored, distinct from jsonl', function (): void {
    Queue::fake();

    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'all', 'format' => 'csv']);

    $response->assertStatus(202)->assertJsonPath('format', 'csv')->assertJsonPath('scope', 'all');
});

test('T-EXP-002b: from/to with a numeric offset are stored UTC-converted, never the raw local wall-clock value (pre-commit gate round 4, finding 2)', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    // 2026-01-02T00:00:00+02:00 === 2026-01-01T22:00:00Z — same instant
    // ReadInterviewsTest's own `created_after` offset regression test uses.
    // Proves both the ECHOED response fields and the STORED `from_at`/
    // `to_at` columns are the UTC-converted instant, never the raw
    // offset-bearing value `Illuminate\Database\Grammar` would otherwise
    // format without the timezone.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', [
            'scope' => 'interviews',
            'format' => 'jsonl',
            'from' => '2026-01-02T00:00:00+02:00',
            'to' => '2026-01-03T00:00:00+02:00',
        ]);

    $response->assertStatus(202);
    expect($response->json('from'))->toBe('2026-01-01T22:00:00+00:00');
    expect($response->json('to'))->toBe('2026-01-02T22:00:00+00:00');

    $stored = TenantContextScope::runFor($org->id, fn () => Export::query()->first());
    expect($stored->from_at->toIso8601String())->toBe('2026-01-01T22:00:00+00:00');
    expect($stored->to_at->toIso8601String())->toBe('2026-01-02T22:00:00+00:00');
});

test('T-EXP-003: a second export while one is already queued/processing answers 429 export_in_progress', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    TenantContextScope::runFor($org->id, fn () => Export::factory()->create());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);

    $response->assertStatus(429)->assertJsonPath('code', 'export_in_progress');
    $this->assertProblemMatchesContract($response, 429);
    Queue::assertNotPushed(GenerateExportJob::class);
});

test('T-EXP-003b: a ready export does not block a new one — only queued/processing do', function (): void {
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);

    $response->assertStatus(202);
    Queue::assertPushed(GenerateExportJob::class);
});

test('a UniqueConstraintViolationException on an UNRELATED constraint is never mapped to export_in_progress (pre-commit gate, round 5, finding 6)', function (): void {
    Queue::fake();

    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write']);

    // exports_public_id_unique — a genuinely different constraint than the
    // one-active-export-per-organization partial index `store()` actually
    // means to catch. Forced via a DB::transaction() facade double (the
    // pre-existing Mockery::mock()/shouldReceive() discipline this file
    // already uses for Storage) rather than a real collision, since
    // public_id is a random ULID with no practical way to force one.
    $unrelated = new UniqueConstraintViolationException(
        'pgsql',
        'insert into "exports" ...',
        [],
        new Exception('duplicate key value violates unique constraint "exports_public_id_unique"'),
    );
    $unrelated->setIndex('exports_public_id_unique');

    DB::shouldReceive('transaction')->once()->andThrow($unrelated);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);

    // Rethrown, never mapped to 429 — PublicApiExceptionRenderer's own generic
    // `default => 500 internal_error` arm is what actually answers it.
    $response->assertStatus(500)->assertJsonPath('code', 'internal_error');
    Queue::assertNotPushed(GenerateExportJob::class);
});

test('T-EXP-004: status polls queued -> ready once the real job runs (sync queue), record_count matches the seeded interview', function (): void {
    Storage::fake();
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::participantWithTranscript($org, $project, 'in_valutazione');

    $create = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $create->assertStatus(202)->assertJsonPath('status', 'queued');

    $exportId = $create->json('id');

    $internalId = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($exportId, Export::publicIdPrefix()))->value('id'),
    );

    // Run the real job — QUEUE_CONNECTION=sync in this suite (phpunit.xml)
    // means dispatch() would already have run it synchronously without
    // Queue::fake(); faked here specifically so this test can assert the
    // response body captured the 'queued' state above BEFORE the job ran,
    // then drive the SAME real handle() explicitly to observe 'ready'.
    (new GenerateExportJob($internalId))->handle();

    $show = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.$exportId);

    $show->assertOk();
    $this->assertMatchesContract($show, 'GET', '/exports/{id}');
    expect($show->json('status'))->toBe('ready');
    expect($show->json('record_count'))->toBe(1);
    expect($show->json('download_url'))->toBeString()->not->toBe('');
    expect($show->json('checksum_sha256'))->toBeString()->toHaveLength(64);
    expect($show->json('size_bytes'))->toBeGreaterThan(0);
});

test('T-EXP-005: download_url expires in 1 hour and is bound to this organization\'s own object key prefix', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'status' => ExportStatus::Ready,
        'object_key' => 'exports/'.$org->id.'/999.jsonl',
        'record_count' => 0,
        'size_bytes' => 2,
        'checksum_sha256' => hash('sha256', '[]'),
    ]));
    Storage::put($export->object_key, '[]');

    $before = now();
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($export));
    $after = now();

    $response->assertOk();
    expect($response->json('download_url'))->toBeString()->not->toBe('');

    $expiresAt = Carbon::parse($response->json('download_expires_at'));
    expect($expiresAt->diffInSeconds($before->copy()->addHour(), true))->toBeLessThan(5);
    expect($expiresAt->diffInSeconds($after->copy()->addHour(), true))->toBeLessThan(5);
});

test('T-EXP-005b: an object key outside this organization\'s prefix is refused a download URL', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'status' => ExportStatus::Ready,
        'object_key' => 'exports/999999/foreign.jsonl',
        'record_count' => 0,
    ]));
    Storage::put($export->object_key, '[]');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($export));

    $response->assertOk();
    expect($response->json('download_url'))->toBeNull();
});

test('T-EXP-006: checksum_sha256 matches the actual stored content, byte for byte', function (): void {
    Storage::fake();
    Queue::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    $create = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $exportId = $create->json('id');
    $internalId = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($exportId, Export::publicIdPrefix()))->value('id'),
    );

    (new GenerateExportJob($internalId))->handle();

    $export = TenantContextScope::runFor($org->id, fn () => Export::find($internalId));
    $stored = Storage::get($export->object_key);

    expect($export->checksum_sha256)->toBe(hash('sha256', $stored));
    expect($export->size_bytes)->toBe(strlen($stored));
});

test('T-EXP-007: a generation failure sets status failed with a failure_reason, never leaves the row processing forever', function (): void {
    Queue::fake();
    Log::spy();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);

    $create = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $exportId = $create->json('id');
    $internalId = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($exportId, Export::publicIdPrefix()))->value('id'),
    );

    // Storage::fake() deliberately NOT called — Storage::disk()->put()
    // against the real 'local' disk inside a path a Mockery double forces
    // to fail simulates a genuine generation-time storage failure. The
    // message deliberately looks like a real QueryException/storage-driver
    // message (connection details) — exactly the shape gga finding 3 says
    // must never reach an external API-key caller verbatim.
    $sensitiveMessage = 'disk exploded: dsn=pgsql://postgres:postgres@127.0.0.1:5432/beai_test';
    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('put')->once()->andThrow(new RuntimeException($sensitiveMessage));
    Storage::shouldReceive('disk')->once()->andReturn($diskDouble);

    (new GenerateExportJob($internalId))->handle();

    $export = TenantContextScope::runFor($org->id, fn () => Export::find($internalId));
    expect($export->status)->toBe(ExportStatus::Failed);
    // Stable machine code only — never the raw exception message (gga
    // finding 3): the detail is logged (asserted below), not stored on the
    // row or returned to the API caller.
    expect($export->failure_reason)->toBe('generation_failed');
    expect($export->completed_at)->not->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'GenerateExportJob: generation failed'
            && $context['export_id'] === $export->id
            && $context['message'] === $sensitiveMessage
        );

    // ExportSerializer returns failure_reason verbatim to the API caller —
    // it must carry the stable code, never the sensitive detail.
    $show = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.$exportId);
    $show->assertOk();
    expect($show->json('failure_reason'))->toBe('generation_failed');
    expect($show->json('failure_reason'))->not->toContain('dsn=');
});

test('T-EXP-008: tenant isolation — an organization never sees another organization\'s exports', function (): void {
    ['org' => $orgA] = Step6Fixtures::orgWithScopedKey(['exports:write']);
    TenantContextScope::runFor($orgA->id, fn () => Export::factory()->create());

    ['key' => $keyB] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyB])->getJson('/api/v1/exports');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('T-EXP-008b: listExports carries a fresh download_url for a ready export, matching the contract', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'status' => ExportStatus::Ready,
        'object_key' => 'exports/'.$org->id.'/list-ready.jsonl',
        'record_count' => 0,
        'size_bytes' => 2,
        'checksum_sha256' => hash('sha256', '[]'),
    ]));
    Storage::put($export->object_key, '[]');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/exports');

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/exports');

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe(PublicId::encode($export));
    expect($data[0]['status'])->toBe('ready');
    expect($data[0]['download_url'])->toBeString()->not->toBe('');
});

test('a still-queued export has no download_url yet', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($export));

    $response->assertOk();
    expect($response->json('status'))->toBe('queued');
    expect($response->json('download_url'))->toBeNull();
});

test('a malformed export id (wrong prefix) answers 404, never 400', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/prj_01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $response->assertStatus(404);
});

test('a storage backend failure while signing the download URL is swallowed, logged, and answers a null download_url rather than a 500', function (): void {
    Log::spy();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $export = TenantContextScope::runFor($org->id, fn () => Export::factory()->create([
        'status' => ExportStatus::Ready,
        'object_key' => 'exports/'.$org->id.'/broken.jsonl',
        'record_count' => 0,
    ]));

    $diskDouble = Mockery::mock();
    $diskDouble->shouldReceive('temporaryUrl')->once()->andThrow(new RuntimeException('temporaryUrl exploded'));
    Storage::shouldReceive('disk')->once()->andReturn($diskDouble);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($export));

    $response->assertOk();
    expect($response->json('download_url'))->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'public-api: failed to generate a signed export download URL'
            && $context['organization_id'] === $org->id
            && $context['export_id'] === $export->id
        );
});

test('T-EXP-009: a well-formed but unknown or cross-organization export id answers 404', function (): void {
    ['org' => $orgA] = Step6Fixtures::orgWithScopedKey(['exports:read']);
    $exportA = TenantContextScope::runFor($orgA->id, fn () => Export::factory()->create());

    ['key' => $keyB] = Step6Fixtures::orgWithScopedKey(['exports:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyB])
        ->getJson('/api/v1/exports/'.PublicId::encode($exportA));

    $response->assertStatus(404);
});

test('T-EXP-010: exposure parity — export content contains exactly what the public API exposes for an interview, minus nothing extra', function (): void {
    Storage::fake();

    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['exports:write', 'exports:read']);
    $project = Step6Fixtures::project($org);
    // Queue::fake() is applied AFTER this fixture, never before: the
    // fixture's own real ScoreEvaluationJob::dispatch() must actually run
    // (QUEUE_CONNECTION=sync) to produce a genuinely completed, scored
    // participant — faking it earlier silently leaves the participant at
    // 'in_valutazione' with no Evaluation row at all, which is exactly the
    // bug this test caught on its first run: `scoring_ready` was false and
    // no `scoring` key was ever added, for a reason entirely upstream of
    // GenerateExportJob itself.
    $scored = Step6Fixtures::buildCompletedScoredParticipant($org, $project);
    Queue::fake();

    $create = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $exportId = $create->json('id');
    $internalId = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($exportId, Export::publicIdPrefix()))->value('id'),
    );

    (new GenerateExportJob($internalId))->handle();

    $export = TenantContextScope::runFor($org->id, fn () => Export::find($internalId));
    $content = Storage::get($export->object_key);
    $record = json_decode(trim($content), true, flags: JSON_THROW_ON_ERROR);

    // The interview-level fields are the exact public Interview shape — no
    // exclusion-list field (ExposureCatalogue::exclusions()['Interview'])
    // leaked into the export, reusing the SAME frozen catalogue T-EXPOSE-001
    // already proves the live /v1/interviews/{id} endpoint against.
    $flatKeys = collect(ExposureCatalogue::flattenKeys($record))
        ->reject(fn (string $key): bool => str_starts_with($key, 'scoring.') || str_starts_with($key, 'transcript.'))
        ->all();

    foreach (ExposureCatalogue::exclusions()['Interview'] as $excluded) {
        expect($flatKeys)->not->toContain($excluded, "export leaked excluded Interview field [{$excluded}]");
    }

    expect($record['id'])->toBe(PublicId::encode($scored));
    expect($record)->toHaveKey('scoring');
    expect($record['scoring'])->toHaveKey('competencies');
});

// ─── Mode isolation (pre-commit gate, round 3, HIGH finding 1) ────────────────
//
// SPEC.md §3.7 "a beai_test_ key's requests must never read or write live
// data": ExportController::index()/resolveExport() previously filtered only
// by organization_id, so a test-mode key could list and download a LIVE
// export archive (real transcripts/scoring), and vice versa.

test('POST /v1/exports stamps the created row with the requesting key\'s own mode', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    $liveResponse = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $liveResponse->assertStatus(202)->assertJsonPath('livemode', true);

    $testResponse = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $testResponse->assertStatus(202)->assertJsonPath('livemode', false);

    $liveMode = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($liveResponse->json('id'), Export::publicIdPrefix()))->value('mode'),
    );
    $testMode = TenantContextScope::runFor(
        $org->id,
        fn () => Export::where('public_id', PublicId::decode($testResponse->json('id'), Export::publicIdPrefix()))->value('mode'),
    );

    expect($liveMode)->toBe(ApiKeyMode::Live);
    expect($testMode)->toBe(ApiKeyMode::Test);
});

test('a test-mode key never sees a live-mode export in the list, and a live-mode key never sees a test-mode export', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    // ->ready() (terminal status) — a plain factory ->create() would be
    // 'queued', and the partial unique index only allows ONE queued/
    // processing export per ORGANIZATION PER MODE (see the migration's own
    // docblock), so two default-state exports of the SAME mode on the same
    // org would collide; ->ready() sidesteps that entirely for this test.
    $liveExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Live]));
    $testExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Test]));

    $liveList = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/exports');
    $liveList->assertOk();
    $liveIds = array_column($liveList->json('data'), 'id');
    expect($liveIds)->toContain(PublicId::encode($liveExport));
    expect($liveIds)->not->toContain(PublicId::encode($testExport));

    $testList = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/exports');
    $testList->assertOk();
    $testIds = array_column($testList->json('data'), 'id');
    expect($testIds)->toContain(PublicId::encode($testExport));
    expect($testIds)->not->toContain(PublicId::encode($liveExport));
});

test('a test-mode key gets 404 (never leaking existence) when directly requesting a live-mode export id, and vice versa', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    $liveExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Live]));
    $testExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Test]));

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($liveExport))
        ->assertStatus(404);

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($testExport))
        ->assertStatus(404);
});

test('a queued TEST export does not block a LIVE POST /v1/exports for the same organization, but a second TEST POST still blocks with 429', function (): void {
    Queue::fake();

    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['mode' => ApiKeyMode::Test]));

    $liveResponse = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $liveResponse->assertStatus(202);

    $testResponse = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $testResponse->assertStatus(429)->assertJsonPath('code', 'export_in_progress');

    Queue::assertPushed(GenerateExportJob::class, 1);
});

test('a queued LIVE export does not block a TEST POST /v1/exports for the same organization, but a second LIVE POST still blocks with 429', function (): void {
    Queue::fake();

    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    TenantContextScope::runFor($org->id, fn () => Export::factory()->create(['mode' => ApiKeyMode::Live]));

    $testResponse = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $testResponse->assertStatus(202);

    $liveResponse = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->postJson('/api/v1/exports', ['scope' => 'interviews', 'format' => 'jsonl']);
    $liveResponse->assertStatus(429)->assertJsonPath('code', 'export_in_progress');

    Queue::assertPushed(GenerateExportJob::class, 1);
});

test('each mode\'s own exports are still visible to a same-mode key', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = exportModeKeyPair();

    $liveExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Live]));
    $testExport = TenantContextScope::runFor($org->id, fn () => Export::factory()->ready()->create(['mode' => ApiKeyMode::Test]));

    $liveShow = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($liveExport));
    $liveShow->assertOk()->assertJsonPath('id', PublicId::encode($liveExport));

    $testShow = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/exports/'.PublicId::encode($testExport));
    $testShow->assertOk()->assertJsonPath('id', PublicId::encode($testExport));
});
