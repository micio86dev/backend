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

    // Step 6 review follow-up, finding 11: the `recording` sub-resource
    // case was previously VACUOUS — with no `InterviewRecording` row at
    // all, the OWNER org's own legitimate key would ALSO get
    // `404 recording_not_ready`, so the foreign-org key's 404 proved
    // nothing about cross-tenant isolation specifically. A real row for
    // the OWNER's participant makes this a genuine test: if isolation
    // were broken, the foreign-org request below would find and return
    // it (200), not 404.
    if ($path === 'recording') {
        TenantContextScope::runFor($ownerOrg->id, fn () => InterviewRecording::factory()->create([
            'participant_id' => $participant->id,
            'object_key' => 'recordings/'.$ownerOrg->id.'/'.$participant->id.'/interview.ogg',
        ]));
    }

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
