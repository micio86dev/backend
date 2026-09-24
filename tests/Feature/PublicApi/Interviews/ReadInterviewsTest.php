<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews` and `GET /v1/interviews/{id}` — BEAI Public API
 * (public-api step 5), SPEC.md §3.3. T-INT-011, T-INT-014, T-INT-015,
 * T-INT-016.
 */

use App\Enums\ApiKeyMode;
use App\Http\Middleware\PublicApi\IdempotencyKey;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function rdOrgWithScopedKey(): array
{
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function rdCreateProject(Organization $org): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);
    });
}

// ─── T-INT-011: lifecycle mapping for all five stored statuses ──────────────

test('T-INT-011: every stored status maps to its public name in list and detail', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $pairs = [
        'in_attesa' => 'pending',
        'in_corso' => 'in_progress',
        'in_valutazione' => 'under_evaluation',
        'completato' => 'completed',
        'errore' => 'error',
    ];

    foreach ($pairs as $stored => $public) {
        TenantContextScope::runFor($org->id, function () use ($org, $project, $stored): void {
            Participant::factory()->forProject($project)->create([
                'organization_id' => $org->id,
                'status' => $stored,
                'candidate_ref' => 'status-'.$stored,
            ])->refresh();
        });
    }

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?limit=100');

    $response->assertOk();
    $statuses = collect($response->json('data'))->pluck('status', 'candidate_ref');

    foreach ($pairs as $stored => $public) {
        expect($statuses['status-'.$stored])->toBe($public);
    }

    foreach (array_keys($pairs) as $stored) {
        $participant = Participant::where('organization_id', $org->id)->where('candidate_ref', 'status-'.$stored)->first();

        $detail = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
            ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

        $detail->assertOk()->assertJsonPath('status', $pairs[$stored]);
        $this->assertMatchesContract($detail, 'GET', '/interviews/{id}');
    }
});

test('gga finding 6: an invalid ?status= filter value → 400 validation_failed, not 422', function (): void {
    ['key' => $rawKey] = rdOrgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?status=not-a-real-status');

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    $this->assertProblemMatchesContract($response, 400);
});

// ─── T-INT-014: email filter never crosses tenants ───────────────────────────

test('T-INT-014: the email filter never returns another organization\'s rows', function (): void {
    ['org' => $orgA, 'key' => $rawKeyA] = rdOrgWithScopedKey();
    ['org' => $orgB] = rdOrgWithScopedKey();

    $projectA = rdCreateProject($orgA);
    $projectB = rdCreateProject($orgB);

    TenantContextScope::runFor($orgA->id, fn () => Participant::factory()->forProject($projectA)->create([
        'organization_id' => $orgA->id,
        'email' => 'shared@example.com',
    ]));
    TenantContextScope::runFor($orgB->id, fn () => Participant::factory()->forProject($projectB)->create([
        'organization_id' => $orgB->id,
        'email' => 'shared@example.com',
    ]));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyA])
        ->getJson('/api/v1/interviews?email=shared@example.com');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.email'))->toBe('shared@example.com');
});

// ─── T-INT-015: filters + metadata filter + expand=project + pagination ─────

test('T-INT-015: status/project_id filters, metadata filter, expand=project and pagination all compose', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    TenantContextScope::runFor($org->id, function () use ($org, $project): void {
        Participant::factory()->forProject($project)->create([
            'organization_id' => $org->id,
            'status' => 'in_attesa',
            'metadata' => ['ats_application_id' => 'A-4471'],
        ])->refresh();
        Participant::factory()->forProject($project)->create([
            'organization_id' => $org->id,
            'status' => 'completato',
            'metadata' => ['ats_application_id' => 'OTHER'],
        ])->refresh();
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?status=pending&project_id='.PublicId::encode($project).'&metadata[ats_application_id]=A-4471&expand=project&limit=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('pending');
    expect($response->json('data.0.project.id'))->toBe(PublicId::encode($project));
    $this->assertMatchesContract($response, 'GET', '/interviews');
});

// ─── T-INT-016: idempotent replay of create ──────────────────────────────────

test('T-INT-016: an Idempotency-Key replay with the same body returns the same 201 body; a different body 409s', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $payload = [
        'project_id' => PublicId::encode($project),
        'candidate' => [
            'candidate_ref' => 'idem-ref',
            'email' => 'idem@example.com',
            'display_name' => 'Idem Candidate',
        ],
    ];

    $first = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'replay-key-1'])
        ->postJson('/api/v1/interviews', $payload);
    $first->assertCreated();

    $second = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'replay-key-1'])
        ->postJson('/api/v1/interviews', $payload);

    $second->assertCreated();
    $second->assertHeader('Idempotent-Replayed', 'true');
    expect($second->json())->toBe($first->json());

    $conflicting = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'replay-key-1'])
        ->postJson('/api/v1/interviews', array_replace_recursive($payload, ['candidate' => ['candidate_ref' => 'a-different-ref']]));

    $conflicting->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
});

// ─── gga round 4 finding 2: idempotency record is encrypted at rest ─────────

test('gga finding 2: the cached idempotency record is encrypted (not plaintext), and replay still returns the ORIGINAL session_token/hosted_url', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $payload = [
        'project_id' => PublicId::encode($project),
        'candidate' => [
            'candidate_ref' => 'gga2-ref',
            'email' => 'gga2@example.com',
            'display_name' => 'GGA Two',
        ],
    ];

    $first = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'gga-2-key'])
        ->postJson('/api/v1/interviews', $payload);
    $first->assertCreated();

    $client = ApiClient::where('organization_id', $org->id)->firstOrFail();
    $probeRequest = Request::create('/api/v1/interviews', 'POST');
    $scope = IdempotencyKey::scopeFor($client, $probeRequest, 'gga-2-key');
    $raw = Cache::get('idempotency:'.$scope);

    // Ciphertext, not the plain {fingerprint, status, headers, body} array
    // App\Http\Middleware\PublicApi\IdempotencyKey used to store directly —
    // that array's own `body` value carries the live session_token/
    // hosted_url in cleartext for up to 24h (record_ttl_seconds).
    expect($raw)->toBeString();
    expect($raw)->not->toContain('session_token');
    expect($raw)->not->toContain($first->json('session_token'));
    expect($raw)->not->toContain('hosted_url');
    expect($raw)->not->toContain((string) $first->json('interview.id'));

    // Behaviour UNCHANGED: replay still returns the SAME body — SPEC.md
    // §3.5 + G-16 — the client must re-mint via
    // POST /interviews/{id}/session-tokens if that token is by then
    // expired/consumed, never get a silently different one from a replay.
    $second = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey, 'Idempotency-Key' => 'gga-2-key'])
        ->postJson('/api/v1/interviews', $payload);

    $second->assertCreated();
    $second->assertHeader('Idempotent-Replayed', 'true');
    expect($second->json())->toBe($first->json());
    expect($second->json('session_token'))->toBe($first->json('session_token'));
    expect($second->json('hosted_url'))->toBe($first->json('hosted_url'));
});

// ─── gga finding 4: GET /v1/interviews must not N+1 per row ─────────────────

test('gga finding 4: GET /v1/interviews query count is bounded and independent of page size', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    TenantContextScope::runFor($org->id, function () use ($project): void {
        $competency = Competency::query()->where('code', 'COM')->first()
            ?? Competency::factory()->create(['code' => 'COM']);
        $project->competencies()->attach($competency->id, ['position' => 1]);
    });

    TenantContextScope::runFor($org->id, function () use ($org, $project): void {
        Participant::factory()->count(9)->forProject($project)->create(['organization_id' => $org->id]);
    });

    // Warm-up call: some auth/rate-limit reads cache after the first hit
    // (e.g. ApiKeyResolver, RateLimitPublicApi's per-org override), so
    // comparing a COLD first call against a warm second call would report a
    // false "fewer queries" delta that has nothing to do with row count.
    // Both measured calls below run warm.
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?limit=1')->assertOk();

    DB::enableQueryLog();
    $smallPage = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?limit=3&expand=project');
    $smallPage->assertOk();
    $smallPageQueries = count(DB::getQueryLog());
    DB::flushQueryLog();

    $bigPage = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?limit=9&expand=project');
    $bigPage->assertOk();
    $bigPageQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($smallPage->json('data'))->toHaveCount(3);
    expect($bigPage->json('data'))->toHaveCount(9);

    // Bounded: auth (api_clients read + last_used_at write), the per-org
    // rate-limit read, this controller's own organization read, ONE
    // participants page query, and — with `expand=project` — ONE batch
    // query each for projects/frameworkVersions/avatarTemplates/
    // competencies, plus the two `progressForMany()` batch queries. None of
    // that scales with row count, so a generous constant ceiling still
    // catches a real regression while tolerating legitimate per-request
    // (never per-row) overhead.
    expect($smallPageQueries)->toBeLessThanOrEqual(15);
    expect($bigPageQueries)->toBeLessThanOrEqual(15);

    // The decisive signal: identical query count for 3x as many rows — a
    // per-row query pattern (the N+1 this test exists to catch) would make
    // $bigPageQueries scale with row count instead of matching exactly.
    expect($bigPageQueries)->toBe($smallPageQueries);
});

// ─── gga round 3 finding 1: a soft-deleted project must not break reads ─────
//
// The enrolment is the CALLING SYSTEM's data — a project an admin later
// archives/soft-deletes must not make that client's already-created
// interviews vanish or 500. `Project` uses SoftDeletes; every public-API
// READ of an interview must resolve the project `withTrashed()`.

test('GET /v1/interviews lists a participant whose project was later soft-deleted, with project_id populated', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.project_id'))->toBe(PublicId::encode($project));
    expect($response->json('data.0.id'))->toBe(PublicId::encode($participant));
});

test('GET /v1/interviews/{id} still 200s when its project was later soft-deleted', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

    $response->assertOk();
    $response->assertJsonPath('project_id', PublicId::encode($project));
});

test('GET /v1/interviews/{id}?expand=project still serializes the project when it was later soft-deleted', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'?expand=project');

    $response->assertOk();
    $response->assertJsonPath('project.id', PublicId::encode($project));
});

test('GET /v1/interviews?expand=project also serializes a soft-deleted project on the list', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ]));

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?expand=project');

    $response->assertOk();
    expect($response->json('data.0.project.id'))->toBe(PublicId::encode($project));
});

// ─── step 5 review follow-up, Part B item 4: Interview.hosted_url is string|null ──

test('GET /v1/interviews/{id} hosted_url is null by default', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

    $response->assertOk()->assertJsonPath('hosted_url', null);
});

test('GET /v1/interviews/{id} hosted_url reflects public_api.interview_hosted_url_override when configured', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    config(['public_api.interview_hosted_url_override' => 'https://override.example/i/token']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

    $response->assertOk()->assertJsonPath('hosted_url', 'https://override.example/i/token');
});

// ─── step 5 review follow-up, item 9: one code path computes progress ───────

test('GET /v1/interviews and GET /v1/interviews/{id} report IDENTICAL progress for the same participant', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, function () use ($org, $project): Participant {
        $prs = Competency::factory()->create(['code' => 'PRS']);
        $col = Competency::factory()->create(['code' => 'COL']);

        $project->competencies()->attach([
            $prs->id => ['position' => 0],
            $col->id => ['position' => 1],
        ]);

        $participant = Participant::factory()->forProject($project)->create([
            'organization_id' => $org->id,
        ])->refresh();

        // One competency answered (COL), the other untouched (PRS) — the
        // shape `InterviewSerializer::progressForMany()` and its now-
        // delegating single-row `progress()` must agree on identically:
        // a competency with no session still appears, empty; one with a
        // completed session carries exactly one answer.
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 2,
            'competency_code' => 'COL',
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => null,
            'status' => 'completed',
            'ended_reason' => 'completed',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
        ]);

        return $participant;
    });

    $listResponse = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews');
    $listResponse->assertOk();

    $showResponse = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));
    $showResponse->assertOk();

    $listProgress = $listResponse->json('data.0.progress');
    $showProgress = $showResponse->json('progress');

    expect($listProgress)->not->toBeEmpty();
    expect($showProgress)->toBe($listProgress);
});

// ─── step 5 review follow-up, item 6: strict ISO 8601 created_after/created_before ──

test('GET /v1/interviews?created_after= rejects a bare date with no time component with 400', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();

    // The plain `'date'` rule this replaces (step 5 review follow-up,
    // item 6) ACCEPTS this — `date_parse('2026-01-01')` resolves real
    // Y/M/D components, so `checkdate()` passes even though there is no
    // time component at all. Strict ISO 8601 requires one.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?created_after='.urlencode('2026-01-01'));

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

test('GET /v1/interviews?created_before= rejects an ISO-shaped date-time with no timezone with 400', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();

    // Also accepted by the plain `'date'` rule this replaces — every
    // component `checkdate()` needs is present — but SPEC.md §3.2 requires
    // UTC with an explicit `Z` or numeric offset, and this string has
    // neither.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?created_before='.urlencode('2026-01-01T00:00:00'));

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

test('GET /v1/interviews?created_after= rejects a relative phrase with 400, never a silently-parsed date', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?created_after='.urlencode('next monday'));

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

test('GET /v1/interviews?created_after= accepts strict ISO 8601 (Z and numeric-offset forms) and filters by it', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $older = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
        'created_at' => '2026-01-01T00:00:00Z',
    ]));

    $newer = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
        'created_at' => '2026-01-03T00:00:00Z',
    ]));

    // Numeric-offset form (+00:00), same instant as the Z-form cutoff the
    // filter is proving — both are ISO 8601 (item 6's second accepted
    // shape).
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?created_after='.urlencode('2026-01-02T00:00:00+00:00'));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe(PublicId::encode($newer));
});

test('GET /v1/interviews?project_id= still matches a soft-deleted project (step 5 review follow-up, item 5)', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    $participant = TenantContextScope::runFor($org->id, fn () => Participant::factory()->forProject($project)->create([
        'organization_id' => $org->id,
    ])->refresh());

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    // Resolving `project_id` withTrashed() (like every other read on this
    // controller) — before the fix, the filter itself resolved the trashed
    // project's public id to NO internal id (Project's default
    // SoftDeletingScope hides it), so it silently matched NOTHING instead
    // of the participant that is genuinely there.
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?project_id='.PublicId::encode($project));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe(PublicId::encode($participant));
});

test('POST /v1/interviews for a soft-deleted project_id → 404 (create stays gated on a LIVE project)', function (): void {
    ['org' => $org, 'key' => $rawKey] = rdOrgWithScopedKey();
    $project = rdCreateProject($org);

    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->postJson('/api/v1/interviews', [
            'project_id' => PublicId::encode($project),
            'candidate' => ['candidate_ref' => 'x', 'email' => 'trashed-project@example.com', 'display_name' => 'A'],
        ]);

    $response->assertNotFound();
});
