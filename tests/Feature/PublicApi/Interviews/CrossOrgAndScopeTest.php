<?php

declare(strict_types=1);

/**
 * T-INT-025 — BEAI Public API (public-api step 6): cross-org 404 and
 * missing-scope 403 on every new `/v1/interviews/{id}/*` endpoint
 * (transcript, answers, scoring, recording, events).
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\InterviewRecording;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\PublicApi\Step6Fixtures;

dataset('step6 sub-resource endpoints', [
    'transcript' => ['transcript', 'in_valutazione'],
    'answers' => ['answers', 'in_valutazione'],
    'scoring' => ['scoring', 'completato'],
    'events' => ['events', 'in_valutazione'],
    'recording' => ['recording', 'in_valutazione'],
]);

test('T-INT-025: a foreign organization\'s key never finds another org\'s interview — 404 on every sub-resource', function (string $path, string $status): void {
    ['org' => $ownerOrg] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($ownerOrg);
    $participant = Step6Fixtures::participantWithTranscript($ownerOrg, $project, $status);

    ['key' => $foreignKey] = Step6Fixtures::orgWithScopedKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$foreignKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/'.$path);

    $response->assertStatus(404);
})->with('step6 sub-resource endpoints');

test('T-INT-025: a key missing the required scope is refused with 403 on every sub-resource', function (string $path, string $status): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, $status);

    // A key with NO abilities at all — every sub-resource requires either
    // interviews:read or recordings:read, and this key has neither.
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => [],
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/'.$path);

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
    $this->assertProblemMatchesContract($response, 403);
})->with('step6 sub-resource endpoints');

test('T-INT-025: recording specifically requires recordings:read, not interviews:read', function (): void {
    ['org' => $org] = Step6Fixtures::orgWithScopedKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'in_valutazione');

    TenantContextScope::runFor($org->id, fn () => InterviewRecording::factory()->create([
        'participant_id' => $participant->id,
        'object_key' => 'recordings/'.$org->id.'/'.$participant->id.'/interview.ogg',
    ]));

    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:read'],
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'/recording');

    $response->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
});
