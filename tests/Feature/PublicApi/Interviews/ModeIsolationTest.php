<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews`, `GET /v1/interviews/{id}` and its
 * `/transcript`/`/answers`/`/scoring`/`/events` sub-resources — BEAI Public
 * API (public-api step 9), SPEC.md §3.7 "Test mode": "a beai_test_ key's
 * requests must never read or write live data" (G-51).
 *
 * Every OTHER `/v1` read (`ExportController`, `UsageController`) already
 * scopes by the requesting key's own mode; `InterviewController` did not —
 * a test-mode key could list and read a LIVE interview's transcript,
 * answers, scoring and events, and vice versa. Mirrors
 * `tests/Feature/PublicApi/Exports/ExportTest.php`'s own "Mode isolation"
 * section and its local `exportModeKeyPair()` helper exactly.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\InterviewRecording;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\PublicApi\Step6Fixtures;

/**
 * Same-organization live/test key pair, both scoped with every ability this
 * test file needs (`interviews:write`/`interviews:read`/`recordings:read`).
 *
 * @return array{org: Organization, liveKey: string, testKey: string}
 */
function interviewModeKeyPair(): array
{
    $org = Organization::factory()->create();
    $abilities = ['interviews:write', 'interviews:read', 'recordings:read'];

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

test('GET /v1/interviews never lists a live interview to a test key, and never lists a test interview to a live key', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = interviewModeKeyPair();
    $project = Step6Fixtures::project($org);

    $liveParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    $testParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    TenantContextScope::runFor($org->id, function () use ($testParticipant): void {
        $testParticipant->forceFill(['mode' => ApiKeyMode::Test])->save();
    });

    $liveList = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/interviews');
    $liveList->assertOk();
    $liveIds = array_column($liveList->json('data'), 'id');
    expect($liveIds)->toContain(PublicId::encode($liveParticipant));
    expect($liveIds)->not->toContain(PublicId::encode($testParticipant));

    $testList = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/interviews');
    $testList->assertOk();
    $testIds = array_column($testList->json('data'), 'id');
    expect($testIds)->toContain(PublicId::encode($testParticipant));
    expect($testIds)->not->toContain(PublicId::encode($liveParticipant));
});

test('GET /v1/interviews/{id} answers 404 for a cross-mode id, never leaking existence', function (): void {
    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = interviewModeKeyPair();
    $project = Step6Fixtures::project($org);

    $liveParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    $testParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    TenantContextScope::runFor($org->id, function () use ($testParticipant): void {
        $testParticipant->forceFill(['mode' => ApiKeyMode::Test])->save();
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($liveParticipant))
        ->assertStatus(404);

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($testParticipant))
        ->assertStatus(404);

    // Same-mode still reads its own interview.
    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($liveParticipant))
        ->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($testParticipant))
        ->assertOk();
});

test('transcript, answers, scoring and events all answer 404 for a cross-mode interview id', function (): void {
    ['org' => $org, 'testKey' => $testKey] = interviewModeKeyPair();
    $project = Step6Fixtures::project($org);

    // A fully scored LIVE interview — every one of the four sub-resource
    // gates is open, so a 404 below can only be the mode filter, never a
    // readiness gate.
    $liveParticipant = Step6Fixtures::buildCompletedScoredParticipant($org, $project);
    TenantContextScope::runFor($org->id, function () use ($liveParticipant): void {
        $liveParticipant->forceFill(['mode' => ApiKeyMode::Live])->save();
    });

    $liveId = PublicId::encode($liveParticipant);

    foreach (['transcript', 'answers', 'scoring', 'events'] as $subResource) {
        $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
            ->getJson('/api/v1/interviews/'.$liveId.'/'.$subResource)
            ->assertStatus(404);
    }
});

test('a recording answers 404 for a cross-mode interview id even though a recording genuinely exists, and vice versa', function (): void {
    Storage::fake();

    ['org' => $org, 'liveKey' => $liveKey, 'testKey' => $testKey] = interviewModeKeyPair();
    $project = Step6Fixtures::project($org);

    $liveParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');
    $testParticipant = Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    TenantContextScope::runFor($org->id, function () use ($org, $liveParticipant, $testParticipant): void {
        $testParticipant->forceFill(['mode' => ApiKeyMode::Test])->save();

        // A REAL recording row for each participant — without one, both
        // requests below would already 404 with `recording_not_ready`
        // regardless of mode scoping, which would mask the exact bug this
        // test exists to catch.
        InterviewRecording::factory()->create([
            'participant_id' => $liveParticipant->id,
            'object_key' => 'recordings/'.$org->id.'/'.$liveParticipant->id.'/live.ogg',
        ]);
        InterviewRecording::factory()->create([
            'participant_id' => $testParticipant->id,
            'object_key' => 'recordings/'.$org->id.'/'.$testParticipant->id.'/test.ogg',
        ]);
    });

    // A plain 404 (the participant itself does not resolve under the
    // wrong mode — `resolveParticipant()` returns null and `show()`
    // `abort(404)`s before ever reaching the `recording_not_ready` Problem
    // response) — never `recording_not_ready`, which would leak that a
    // participant WAS found but merely had no recording yet.
    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($liveParticipant).'/recording')
        ->assertStatus(404);

    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($testParticipant).'/recording')
        ->assertStatus(404);

    // Same-mode still reads its own recording.
    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($liveParticipant).'/recording')
        ->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($testParticipant).'/recording')
        ->assertOk();
});
