<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews` and `GET /v1/interviews/{id}` carry the calling
 * system's external reference (candidate-external-reference, slice A3a-ii).
 *
 * `external_id` (integer|null) and `source` (string|null) sit at the top level
 * of every Interview the API returns, ALWAYS present and `null` when absent —
 * as a JSON number and a JSON string, never a numeric string. They are the
 * calling system's own identifiers, supplied by it; reading them back adds no
 * BEAI-derived data, and the org and mode scoping of the reads is unchanged.
 *
 * The create response echo arrives with the create request itself (slice A3b).
 *
 * REQ: The Interview Resource Exposes The External Reference
 *      (sdd/candidate-external-reference/spec/public-api)
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;

/**
 * @return array{org: Organization, key: string}
 */
function irOrgWithKey(ApiKeyMode $mode = ApiKeyMode::Live): array
{
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate($mode);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => $mode,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

function irProject(Organization $org): Project
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

function irParticipant(Organization $org, Project $project, ?int $externalId, ?string $source, ApiKeyMode $mode = ApiKeyMode::Live): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org, $project, $externalId, $source, $mode): Participant {
        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id]);
        $participant->forceFill(['external_id' => $externalId, 'source' => $source, 'mode' => $mode])->save();

        return $participant->refresh();
    });
}

/**
 * @return array<string, array{0: int|null, 1: string|null}>
 */
function irCombinations(): array
{
    return [
        'both' => [4471, 'acme-ats'],
        'only external_id' => [4471, null],
        'only source' => [null, 'acme-ats'],
        'neither' => [null, null],
    ];
}

test('GET /v1/interviews/{id} carries external_id and source, always present, null when absent', function (?int $externalId, ?string $source): void {
    ['org' => $org, 'key' => $rawKey] = irOrgWithKey();
    $participant = irParticipant($org, irProject($org), $externalId, $source);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

    $response->assertOk();
    expect($response->json())->toHaveKeys(['external_id', 'source']);
    expect($response->json('external_id'))->toBe($externalId);
    expect($response->json('source'))->toBe($source);
    $this->assertMatchesContract($response, 'GET', '/interviews/{id}');
})->with(fn () => irCombinations());

test('GET /v1/interviews items carry external_id and source, always present, null when absent', function (): void {
    ['org' => $org, 'key' => $rawKey] = irOrgWithKey();
    $project = irProject($org);
    $byRef = [];

    foreach (irCombinations() as [$externalId, $source]) {
        $participant = irParticipant($org, $project, $externalId, $source);
        $byRef[$participant->candidate_ref] = [$externalId, $source];
    }

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews');

    $response->assertOk();
    $items = collect($response->json('data'))->keyBy('candidate_ref');
    expect($items)->toHaveCount(4);

    foreach ($byRef as $candidateRef => [$externalId, $source]) {
        expect($items[$candidateRef])->toHaveKeys(['external_id', 'source']);
        expect($items[$candidateRef]['external_id'])->toBe($externalId);
        expect($items[$candidateRef]['source'])->toBe($source);
    }

    $this->assertMatchesContract($response, 'GET', '/interviews');
});

test('the fields survive ?expand=project on both the detail and the list', function (): void {
    ['org' => $org, 'key' => $rawKey] = irOrgWithKey();
    $participant = irParticipant($org, irProject($org), 4471, 'acme-ats');

    $detail = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant).'?expand=project');
    $list = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?expand=project');

    $detail->assertOk();
    $list->assertOk();
    expect($detail->json('project'))->not->toBeNull();
    expect($detail->json('external_id'))->toBe(4471);
    expect($detail->json('source'))->toBe('acme-ats');
    expect($list->json('data.0.project'))->not->toBeNull();
    expect($list->json('data.0.external_id'))->toBe(4471);
    expect($list->json('data.0.source'))->toBe('acme-ats');
});

test('external_id is a JSON number and source a JSON string in the raw body', function (): void {
    ['org' => $org, 'key' => $rawKey] = irOrgWithKey();
    $participant = irParticipant($org, irProject($org), 9007199254740991, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($participant));

    // The raw body, because a decoded integer would hide a quoted number.
    expect($response->getContent())->toContain('"external_id":9007199254740991');
    expect($response->getContent())->toContain('"source":"acme-ats"');
});

test('another organization\'s reference is never returned, and a cross-org id stays 404', function (): void {
    ['org' => $orgA, 'key' => $keyA] = irOrgWithKey();
    ['org' => $orgB] = irOrgWithKey();

    $own = irParticipant($orgA, irProject($orgA), 4471, 'acme-ats');
    $foreign = irParticipant($orgB, irProject($orgB), 4471, 'acme-ats');

    $list = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/interviews');
    $list->assertOk();
    expect(array_column($list->json('data'), 'id'))->toBe([PublicId::encode($own)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$keyA])
        ->getJson('/api/v1/interviews/'.PublicId::encode($foreign))
        ->assertNotFound();
});

test('a test key never reads the reference of a live interview, and a live key never that of a test interview', function (): void {
    $org = Organization::factory()->create();
    $abilities = ['interviews:write', 'interviews:read'];
    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => ApiKeyMode::Live, 'abilities' => $abilities]);
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => ApiKeyMode::Test, 'abilities' => $abilities]);

    $project = irProject($org);
    $live = irParticipant($org, $project, 4471, 'acme-ats', ApiKeyMode::Live);
    $test = irParticipant($org, $project, 4472, 'acme-ats-sandbox', ApiKeyMode::Test);

    $liveList = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/interviews');
    $testList = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/interviews');

    expect(array_column($liveList->json('data'), 'external_id'))->toBe([4471]);
    expect(array_column($testList->json('data'), 'external_id'))->toBe([4472]);

    $this->withHeaders(['Authorization' => 'Bearer '.$testKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($live))
        ->assertNotFound();
    $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])
        ->getJson('/api/v1/interviews/'.PublicId::encode($test))
        ->assertNotFound();
});
