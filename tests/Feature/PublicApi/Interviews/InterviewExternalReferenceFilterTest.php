<?php

declare(strict_types=1);

/**
 * `GET /v1/interviews?external_id=` and `?source=` filter by the calling
 * system's external reference (candidate-external-reference, slice A3b).
 *
 * Both are exact-match filters, AND-ed with each other and with every other
 * filter, and both sit on top of the organization + live/test-mode base query
 * the list already applies: a filter can only narrow what the key may already
 * read, so a value held only by another organization (or only under the other
 * mode) is indistinguishable from a value nobody holds. `source` is
 * case-sensitive like `candidate_ref`. `external_id` is read from a query
 * string, so it is validated with the NON-strict `integer` rule (every query
 * value is a string); a malformed value answers `400 validation_failed`, the
 * list's existing convention for a bad query parameter (a body would be 422).
 *
 * REQ: The Interview List Filters By External Reference
 *      (sdd/candidate-external-reference/spec/public-api)
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\ApiKeyGenerator;
use App\Support\Participant\ExternalReference;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

/**
 * @return array{org: Organization, key: string}
 */
function ierfOrgWithKey(ApiKeyMode $mode = ApiKeyMode::Live): array
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

function ierfProject(Organization $org): Project
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

function ierfParticipant(Organization $org, Project $project, ?int $externalId, ?string $source, ApiKeyMode $mode = ApiKeyMode::Live, string $status = 'in_attesa'): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org, $project, $externalId, $source, $mode, $status): Participant {
        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id]);
        $participant->forceFill(['external_id' => $externalId, 'source' => $source, 'mode' => $mode, 'status' => $status])->save();

        return $participant->refresh();
    });
}

/**
 * The public ids of a list response, sorted so a test does not depend on the
 * list's own ordering.
 *
 * @param  array<int, array<string, mixed>>  $items
 * @return list<string>
 */
function ierfIds(array $items): array
{
    $ids = array_column($items, 'id');
    sort($ids);

    return array_values($ids);
}

/**
 * @param  list<Participant>  $participants
 * @return list<string>
 */
function ierfExpectedIds(array $participants): array
{
    $ids = array_map(fn (Participant $p): string => PublicId::encode($p), $participants);
    sort($ids);

    return $ids;
}

// ---------------------------------------------------------------------------
// Each filter on its own
// ---------------------------------------------------------------------------

test('?external_id returns only the interviews with exactly that id', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 4471, 'acme-ats');
    ierfParticipant($org, $project, 4472, 'acme-ats');
    ierfParticipant($org, $project, 44710, 'acme-ats');
    ierfParticipant($org, $project, null, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?external_id=4471');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
    expect(array_column($response->json('data'), 'external_id'))->toBe([4471]);
    $this->assertMatchesContract($response, 'GET', '/interviews');
});

test('?source is an exact, case-sensitive match', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 1, 'acme-ats');
    ierfParticipant($org, $project, 2, 'Acme-ATS');
    ierfParticipant($org, $project, 3, 'acme-ats-2');
    ierfParticipant($org, $project, 4, 'acme');
    ierfParticipant($org, $project, 5, null);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?source=acme-ats');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
});

test('a source with a space, an ampersand or a LIKE wildcard is matched literally', function (string $source): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 1, $source);
    ierfParticipant($org, $project, 2, 'decoy-one');
    ierfParticipant($org, $project, 3, 'decoy-two');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?'.http_build_query(['source' => $source]));

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
})->with(['a space' => ['Acme ATS'], 'an ampersand' => ['A&B'], 'a percent' => ['100%'], 'an underscore' => ['_']]);

test('both filters are AND-ed', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 4471, 'acme-ats');
    ierfParticipant($org, $project, 4471, 'other-ats');
    ierfParticipant($org, $project, 9000, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?source=acme-ats&external_id=4471');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
});

test('the filters accept the boundary values', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $lowest = ierfParticipant($org, $project, 1, 'a');
    $highest = ierfParticipant($org, $project, ExternalReference::MAX_EXTERNAL_ID, str_repeat('é', ExternalReference::SOURCE_MAX_LENGTH));

    $byLowest = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?external_id=1');
    $byHighest = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?external_id='.ExternalReference::MAX_EXTERNAL_ID);
    $bySource = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?'.http_build_query(['source' => str_repeat('é', ExternalReference::SOURCE_MAX_LENGTH)]));

    expect(ierfIds($byLowest->assertOk()->json('data')))->toBe(ierfExpectedIds([$lowest]));
    expect(ierfIds($byHighest->assertOk()->json('data')))->toBe(ierfExpectedIds([$highest]));
    expect(ierfIds($bySource->assertOk()->json('data')))->toBe(ierfExpectedIds([$highest]));
});

test('a filter matching nothing answers 200 with an empty page', function (string $query): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    ierfParticipant($org, ierfProject($org), 4471, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?'.$query);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('has_more'))->toBeFalse();
    expect($response->json('next_cursor'))->toBeNull();
})->with(['an id nobody holds' => ['external_id=123'], 'a source nobody holds' => ['source=nobody']]);

// ---------------------------------------------------------------------------
// Composition with the other filters and with pagination
// ---------------------------------------------------------------------------

test('the filters compose with status', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 4471, 'acme-ats', status: 'in_corso');
    ierfParticipant($org, $project, 4471, 'acme-ats', status: 'in_attesa');
    ierfParticipant($org, $project, 4471, 'other-ats', status: 'in_corso');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?source=acme-ats&external_id=4471&status=in_progress');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
});

test('the filters compose with limit and next_cursor: stable pages, only matches, and the last page ends the list', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);

    $matches = [];
    foreach (range(1, 5) as $n) {
        $matches[] = ierfParticipant($org, $project, 4471, 'acme-ats');
        ierfParticipant($org, $project, 4472, 'acme-ats');
        ierfParticipant($org, $project, 4471, 'other-ats');
    }

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $query = 'source=acme-ats&external_id=4471&limit=2'.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?'.$query);
        $response->assertOk();

        foreach ($response->json('data') as $item) {
            expect($item['external_id'])->toBe(4471);
            expect($item['source'])->toBe('acme-ats');
            $seen[] = $item['id'];
        }

        $cursor = $response->json('next_cursor');
        $pages++;

        expect($response->json('has_more'))->toBe($cursor !== null);
    } while ($cursor !== null && $pages < 10);

    expect($pages)->toBe(3);
    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5);
    sort($seen);
    expect($seen)->toBe(ierfExpectedIds($matches));
});

// ---------------------------------------------------------------------------
// Malformed values: 400 validation_failed naming the parameter
// ---------------------------------------------------------------------------

test('a malformed external_id answers 400 validation_failed naming the parameter', function (string $value): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    ierfParticipant($org, ierfProject($org), 4471, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?external_id='.$value);

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('external_id');
    $this->assertProblemMatchesContract($response, 400);
})->with([
    'not a number' => ['abc'],
    'zero' => ['0'],
    'negative' => ['-1'],
    'a fraction' => ['1.5'],
    'one past the cap' => ['9007199254740992'],
    'far past the cap' => ['99999999999999999999999'],
    'a leading zero' => ['04471'],
]);

test('an external_id sent as a list answers 400', function (): void {
    ['key' => $rawKey] = ierfOrgWithKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?external_id[]=1');

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

test('a source over 180 characters answers 400 validation_failed naming the parameter', function (): void {
    ['key' => $rawKey] = ierfOrgWithKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?source='.str_repeat('a', ExternalReference::SOURCE_MAX_LENGTH + 1));

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain('source');
});

test('a source sent as a list answers 400', function (): void {
    ['key' => $rawKey] = ierfOrgWithKey();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/interviews?source[]=acme-ats')
        ->assertStatus(400)
        ->assertJsonPath('code', 'validation_failed');
});

// ---------------------------------------------------------------------------
// Empty values: the filter is not applied
// ---------------------------------------------------------------------------

test('an empty ?source= and an empty ?external_id= are ignored, not refused', function (string $query): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $all = [
        ierfParticipant($org, $project, 4471, 'acme-ats'),
        ierfParticipant($org, $project, null, null),
    ];

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?'.$query);

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds($all));
})->with([
    'empty source' => ['source='],
    'empty external_id' => ['external_id='],
    'both empty' => ['source=&external_id='],
]);

test('an empty filter does not stop the other one from applying', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $match = ierfParticipant($org, $project, 4471, 'acme-ats');
    ierfParticipant($org, $project, 4472, 'acme-ats');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews?source=&external_id=4471');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds([$match]));
});

// ---------------------------------------------------------------------------
// Tenancy and mode: a filter only narrows what the key may already read
// ---------------------------------------------------------------------------

test('another organization\'s value is never matched and is indistinguishable from a value nobody holds', function (): void {
    ['org' => $orgA, 'key' => $keyA] = ierfOrgWithKey();
    ['org' => $orgB] = ierfOrgWithKey();
    $own = ierfParticipant($orgA, ierfProject($orgA), 4471, 'shared-ats');
    $projectB = ierfProject($orgB);
    ierfParticipant($orgB, $projectB, 7777, 'only-b-ats');
    ierfParticipant($orgB, $projectB, 4471, 'shared-ats');

    $sharedById = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/interviews?external_id=4471');
    $sharedBySource = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/interviews?source=shared-ats');
    $heldOnlyByB = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/interviews?external_id=7777&source=only-b-ats');
    $heldByNobody = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/interviews?external_id=8888&source=nobody-ats');

    expect(ierfIds($sharedById->assertOk()->json('data')))->toBe(ierfExpectedIds([$own]));
    expect(ierfIds($sharedBySource->assertOk()->json('data')))->toBe(ierfExpectedIds([$own]));

    // Same status, same body: nothing in the answer tells "held by another
    // organization" from "held by nobody".
    $heldOnlyByB->assertOk();
    $heldByNobody->assertOk();
    expect($heldOnlyByB->json())->toBe($heldByNobody->json());
    expect($heldOnlyByB->json('data'))->toBe([]);
});

test('a test key never matches a live interview by reference, and a live key never a test one', function (): void {
    $org = Organization::factory()->create();
    $abilities = ['interviews:write', 'interviews:read'];
    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create(['organization_id' => $org->id, 'mode' => ApiKeyMode::Live, 'abilities' => $abilities]);
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => ApiKeyMode::Test, 'abilities' => $abilities]);

    $project = ierfProject($org);
    $live = ierfParticipant($org, $project, 4471, 'acme-ats', ApiKeyMode::Live);
    $test = ierfParticipant($org, $project, 4471, 'acme-ats', ApiKeyMode::Test);

    $asLive = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/interviews?external_id=4471&source=acme-ats');
    $asTest = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/interviews?external_id=4471&source=acme-ats');

    expect(ierfIds($asLive->assertOk()->json('data')))->toBe(ierfExpectedIds([$live]));
    expect(ierfIds($asTest->assertOk()->json('data')))->toBe(ierfExpectedIds([$test]));
});

test('the filters do not disturb the plain list: without them every interview of the key is returned', function (): void {
    ['org' => $org, 'key' => $rawKey] = ierfOrgWithKey();
    $project = ierfProject($org);
    $all = [
        ierfParticipant($org, $project, 4471, 'acme-ats'),
        ierfParticipant($org, $project, null, 'acme-ats'),
        ierfParticipant($org, $project, 4471, null),
        ierfParticipant($org, $project, null, null),
    ];

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/interviews');

    $response->assertOk();
    expect(ierfIds($response->json('data')))->toBe(ierfExpectedIds($all));
});

// ---------------------------------------------------------------------------
// Index support
// ---------------------------------------------------------------------------

test('the filters are served by the partial indexes on participants, not a sequential scan', function (string $where, array $bindings, array $indexes): void {
    ['org' => $org] = ierfOrgWithKey();
    ierfParticipant($org, ierfProject($org), 4471, 'acme-ats');

    // Sequential scans are disabled for this transaction only: on a table this
    // small the planner would pick one regardless, so what is being asked is
    // whether the index CAN serve the filter's predicate (the partial
    // `WHERE ... IS NOT NULL` clause has to be implied by the equality).
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = collect(DB::select(
        "EXPLAIN select * from participants where organization_id = ? and mode = 'live' and {$where}",
        [$org->id, ...$bindings],
    ))->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->not->toContain('Seq Scan');
    expect(collect($indexes)->contains(fn (string $index): bool => str_contains($plan, $index)))
        ->toBeTrue('the plan uses none of ['.implode(', ', $indexes)."]:\n{$plan}");
})->with([
    'source alone' => ['source = ?', ['acme-ats'], ['participants_org_source_external_id_index']],
    // Either index can serve the pair; which one the planner prefers is its
    // own business, so both are accepted.
    'source and external_id' => ['source = ? and external_id = ?', ['acme-ats', 4471], ['participants_org_source_external_id_index', 'participants_org_external_id_index']],
    'external_id alone' => ['external_id = ?', [4471], ['participants_org_external_id_index']],
]);
