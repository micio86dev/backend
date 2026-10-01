<?php

declare(strict_types=1);

/**
 * An EMPTY list filter on the public `/v1` surface means "the filter was not
 * provided", never an error.
 *
 * A browser form submits every field it has, so `?status=` (a present key with
 * no value) is the ordinary way an unfiltered form arrives. Laravel's
 * `TrimStrings` and `ConvertEmptyStringsToNull` run before the controller and
 * turn it into a `null` query value; a bare `string` or `in:` rule refuses a
 * `null`, so every list filter used to answer `400 validation_failed` for an
 * empty value, and the `!== ''` guards in the controllers were unreachable.
 *
 * Contract held here, for EVERY filter of every `/v1` list endpoint:
 *  - an empty value, or one that is only whitespace, answers 200 with exactly
 *    the list the request without the parameter returns;
 *  - a NON-empty invalid value still answers `400 validation_failed`;
 *  - an empty filter never widens what the key may read: tenant and mode
 *    scoping is untouched.
 */

use App\Enums\ApiKeyMode;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Tests\Helpers\PublicApi\Step6Fixtures;

/**
 * @return array{org: Organization, key: string}
 */
function elfOrgWithKey(ApiKeyMode $mode = ApiKeyMode::Live, ?Organization $org = null): array
{
    $org ??= Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate($mode);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => $mode,
        'abilities' => ['interviews:read', 'projects:read', 'webhooks:read', 'usage:read', 'exports:read'],
    ]);

    return ['org' => $org, 'key' => $rawKey];
}

/**
 * A project under a find-or-create avatar template: the template name is unique
 * per organization, so `Step6Fixtures::project()` cannot be called twice for one.
 */
function elfProject(Organization $org, string $roleCode = 'ICO', string $status = 'active'): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $roleCode, $status): Project {
        $avatarTemplate = AvatarTemplate::query()->where('organization_id', $org->id)->first()
            ?? AvatarTemplate::create(['name' => 'Ada', 'provider' => 'heygen', 'config' => []]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => $status,
            'role_code' => $roleCode,
        ]);
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function elfParticipant(Organization $org, Project $project, array $attributes = []): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org, $project, $attributes): Participant {
        $participant = Participant::factory()->forProject($project)->create(['organization_id' => $org->id]);
        $participant->forceFill(array_merge(['mode' => ApiKeyMode::Live, 'status' => 'in_attesa'], $attributes))->save();

        return $participant->refresh();
    });
}

/**
 * Two organizations, each with interviews of every filterable shape. Returns
 * the caller's key and the public ids of what the caller may read.
 *
 * The same organization also holds Test-mode interviews, which a Live key must
 * never see (and a Test key must never see the Live ones).
 *
 * @return array{key: string, testKey: string, ids: list<string>, testIds: list<string>, foreign: list<string>}
 */
function elfInterviewWorld(): array
{
    ['org' => $org, 'key' => $key] = elfOrgWithKey();
    ['key' => $testKey] = elfOrgWithKey(ApiKeyMode::Test, $org);
    ['org' => $other] = elfOrgWithKey();

    $project = elfProject($org);
    $second = elfProject($org, 'FLL');
    $otherProject = elfProject($other);

    $own = [
        elfParticipant($org, $project, ['status' => 'in_attesa', 'candidate_ref' => 'elf-a', 'email' => 'elf-a@example.test', 'metadata' => ['ats_application_id' => 'A-1'], 'external_id' => 11, 'source' => 'acme-ats']),
        elfParticipant($org, $project, ['status' => 'in_corso', 'candidate_ref' => 'elf-b', 'email' => 'elf-b@example.test', 'metadata' => ['ats_application_id' => 'B-2']]),
        elfParticipant($org, $second, ['status' => 'errore', 'candidate_ref' => 'elf-c', 'email' => 'elf-c@example.test']),
    ];

    // Same organization, other mode: shapes that match the live ones.
    $test = [
        elfParticipant($org, $project, ['mode' => ApiKeyMode::Test, 'status' => 'in_attesa', 'candidate_ref' => 'elf-t1', 'email' => 'elf-t1@example.test', 'metadata' => ['ats_application_id' => 'A-1']]),
        elfParticipant($org, $second, ['mode' => ApiKeyMode::Test, 'status' => 'in_corso', 'candidate_ref' => 'elf-t2', 'email' => 'elf-t2@example.test']),
    ];

    // Same shapes in another organization: an empty filter must never reach them.
    $foreign = [
        elfParticipant($other, $otherProject, ['status' => 'in_attesa', 'candidate_ref' => 'elf-a', 'email' => 'elf-a@example.test', 'metadata' => ['ats_application_id' => 'A-1'], 'external_id' => 11, 'source' => 'acme-ats']),
        elfParticipant($other, $otherProject, ['status' => 'in_corso', 'candidate_ref' => 'elf-x', 'email' => 'elf-x@example.test']),
    ];

    $ids = static function (array $participants): array {
        $ids = array_map(fn (Participant $p): string => PublicId::encode($p), $participants);
        sort($ids);

        return $ids;
    };

    return ['key' => $key, 'testKey' => $testKey, 'ids' => $ids($own), 'testIds' => $ids($test), 'foreign' => $ids($foreign)];
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @return list<string>
 */
function elfSortedIds(array $items): array
{
    $ids = array_column($items, 'id');
    sort($ids);

    return array_values($ids);
}

// ---------------------------------------------------------------------------
// GET /v1/interviews
// ---------------------------------------------------------------------------

const ELF_INTERVIEW_FILTERS = [
    'status',
    'project_id',
    'email',
    'candidate_ref',
    'created_after',
    'created_before',
    'external_id',
    'source',
    'metadata',
    'expand',
    'limit',
    'cursor',
];

// One world per test, looped over the filters: each world inserts rows that the
// transaction then rolls back, and the dead tuples they leave bloat the
// `participants` heap for every test that runs after. A dataset of one case per
// filter multiplied that by the number of filters for no extra proof.
test('an empty interview filter answers 200 with the list the request without it returns', function (): void {
    $world = elfInterviewWorld();
    $headers = ['Authorization' => 'Bearer '.$world['key']];

    $baseline = $this->withHeaders($headers)->getJson('/api/v1/interviews');
    $baseline->assertOk();

    foreach (ELF_INTERVIEW_FILTERS as $filter) {
        $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?'.$filter.'=');

        expect($response->status())->toBe(200, "?{$filter}= answered {$response->status()}");
        expect($response->json())->toBe($baseline->json(), "?{$filter}= changed the list");
        expect(elfSortedIds($response->json('data')))->toBe($world['ids']);
    }

    $this->assertMatchesContract($this->withHeaders($headers)->getJson('/api/v1/interviews?status='), 'GET', '/interviews');
});

test('a whitespace-only interview filter behaves like an empty one', function (): void {
    $world = elfInterviewWorld();
    $headers = ['Authorization' => 'Bearer '.$world['key']];

    $baseline = $this->withHeaders($headers)->getJson('/api/v1/interviews');

    foreach (ELF_INTERVIEW_FILTERS as $filter) {
        foreach (['%20%20%20' => 'spaces', '%09%0A' => 'a tab and a newline'] as $whitespace => $name) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?'.$filter.'='.$whitespace);

            expect($response->status())->toBe(200, "?{$filter}= ({$name}) answered {$response->status()}");
            expect($response->json())->toBe($baseline->json(), "?{$filter}= ({$name}) changed the list");
        }
    }
});

test('every interview filter empty at once is the unfiltered list', function (): void {
    $world = elfInterviewWorld();
    $headers = ['Authorization' => 'Bearer '.$world['key']];

    $query = implode('&', array_map(fn (string $filter): string => $filter.'=', ELF_INTERVIEW_FILTERS));

    $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?'.$query);

    $response->assertOk();
    expect(elfSortedIds($response->json('data')))->toBe($world['ids']);
});

test('an empty metadata entry is not provided; the key beside it still applies', function (): void {
    $world = elfInterviewWorld();
    $headers = ['Authorization' => 'Bearer '.$world['key']];

    $empty = $this->withHeaders($headers)->getJson('/api/v1/interviews?metadata[ats_application_id]=');
    $empty->assertOk();
    expect(elfSortedIds($empty->json('data')))->toBe($world['ids']);

    // Four empty entries do not trip the three-key cap: they are not filters.
    $many = $this->withHeaders($headers)->getJson('/api/v1/interviews?metadata[a]=&metadata[b]=&metadata[c]=&metadata[d]=');
    $many->assertOk();
    expect(elfSortedIds($many->json('data')))->toBe($world['ids']);

    $mixed = $this->withHeaders($headers)->getJson('/api/v1/interviews?metadata[ats_application_id]=A-1&metadata[other]=');
    $mixed->assertOk();
    expect($mixed->json('data'))->toHaveCount(1);
});

test('an empty filter does not stop the others from applying', function (): void {
    $world = elfInterviewWorld();
    $headers = ['Authorization' => 'Bearer '.$world['key']];

    $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?status=&email=&candidate_ref=elf-b&project_id=&created_after=');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.candidate_ref'))->toBe('elf-b');
});

test('a non-empty invalid interview filter still answers 400 validation_failed', function (string $query, string $field): void {
    $world = elfInterviewWorld();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$world['key']])->getJson('/api/v1/interviews?'.$query);

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('field')->all())->toContain($field);
    $this->assertProblemMatchesContract($response, 400);
})->with([
    'an unknown status' => ['status=not-a-status', 'status'],
    'a malformed email' => ['email=not-an-email', 'email'],
    'a candidate_ref sent as a list' => ['candidate_ref[]=1', 'candidate_ref'],
    'a relative created_after' => ['created_after=yesterday', 'created_after'],
    'a bare-date created_before' => ['created_before=2026-01-01', 'created_before'],
    'a non-integer external_id' => ['external_id=abc', 'external_id'],
    'a metadata that is not a map' => ['metadata=plain', 'metadata'],
    'a metadata value that is a list' => ['metadata[a][]=1', 'metadata.a'],
    'more than three metadata filters' => ['metadata[a]=1&metadata[b]=2&metadata[c]=3&metadata[d]=4', 'metadata'],
]);

test('an empty interview filter never reaches another organization or the other mode', function (): void {
    $world = elfInterviewWorld();
    $query = '?status=&email=&candidate_ref=&project_id=&external_id=&source=&created_after=&created_before=&metadata=';

    // A Live key: its own Live interviews, and not one Test-mode interview of the
    // SAME organization, nor anything of another organization.
    $live = $this->withHeaders(['Authorization' => 'Bearer '.$world['key']])->getJson('/api/v1/interviews'.$query);

    $live->assertOk();
    $liveIds = elfSortedIds($live->json('data'));
    expect($liveIds)->toBe($world['ids'])
        ->and(array_intersect($liveIds, $world['testIds']))->toBe([])
        ->and(array_intersect($liveIds, $world['foreign']))->toBe([]);

    // And the reverse: a Test key sees only the Test-mode interviews.
    $test = $this->withHeaders(['Authorization' => 'Bearer '.$world['testKey']])->getJson('/api/v1/interviews'.$query);

    $test->assertOk();
    $testIds = elfSortedIds($test->json('data'));
    expect($testIds)->toBe($world['testIds'])
        ->and(array_intersect($testIds, $world['ids']))->toBe([])
        ->and(array_intersect($testIds, $world['foreign']))->toBe([]);
});

// ---------------------------------------------------------------------------
// GET /v1/projects
// ---------------------------------------------------------------------------

test('an empty project filter answers 200 with the list the request without it returns', function (): void {
    ['org' => $org, 'key' => $key] = elfOrgWithKey();
    ['org' => $other] = elfOrgWithKey();
    elfProject($org, 'ICO', 'active');
    elfProject($org, 'FLL', 'draft');
    elfProject($other, 'SRX');
    $headers = ['Authorization' => 'Bearer '.$key];

    $baseline = $this->withHeaders($headers)->getJson('/api/v1/projects');
    $baseline->assertOk();
    expect($baseline->json('data'))->toHaveCount(2);

    foreach (['status', 'role_code', 'assessment_type', 'limit', 'cursor'] as $filter) {
        foreach (['', '%20%20', '%09%0A'] as $value) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/projects?'.$filter.'='.$value);

            expect($response->status())->toBe(200, "?{$filter}={$value} answered {$response->status()}");
            expect($response->json())->toBe($baseline->json(), "?{$filter}={$value} changed the list");
        }
    }
});

test('a non-empty invalid project filter still answers 400 validation_failed', function (string $query): void {
    ['key' => $key] = elfOrgWithKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/api/v1/projects?'.$query);

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
})->with([
    'an unknown status' => ['status=nope'],
    'an unknown role_code' => ['role_code=XYZ'],
    'an unknown assessment_type' => ['assessment_type=nope'],
    'an empty status beside an invalid role_code' => ['status=&role_code=XYZ'],
]);

// ---------------------------------------------------------------------------
// GET /v1/webhooks/deliveries
// ---------------------------------------------------------------------------

test('an empty webhook delivery filter answers 200 with the list the request without it returns', function (): void {
    ['org' => $org, 'key' => $key] = elfOrgWithKey();
    ['org' => $other] = elfOrgWithKey();
    $project = Step6Fixtures::project($org);
    $participant = Step6Fixtures::participantWithTranscript($org, $project, 'completato');
    $otherParticipant = Step6Fixtures::participantWithTranscript($other, Step6Fixtures::project($other), 'completato');

    TenantContextScope::runFor($org->id, function () use ($participant): void {
        WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Delivered, 'delivered_at' => now(), 'event_type' => WebhookEventType::Evaluation]);
        WebhookDelivery::factory()->forParticipant($participant)->create(['status' => WebhookDeliveryStatus::Dead, 'event_type' => WebhookEventType::Progress]);
    });
    TenantContextScope::runFor($other->id, function () use ($otherParticipant): void {
        WebhookDelivery::factory()->forParticipant($otherParticipant)->create(['status' => WebhookDeliveryStatus::Dead]);
    });
    $headers = ['Authorization' => 'Bearer '.$key];

    $baseline = $this->withHeaders($headers)->getJson('/api/v1/webhooks/deliveries');
    $baseline->assertOk();
    expect($baseline->json('data'))->toHaveCount(2);

    foreach (['status', 'event_type', 'interview_id', 'limit', 'cursor'] as $filter) {
        foreach (['', '%20%20', '%09%0A'] as $value) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/webhooks/deliveries?'.$filter.'='.$value);

            expect($response->status())->toBe(200, "?{$filter}={$value} answered {$response->status()}");
            expect($response->json())->toBe($baseline->json(), "?{$filter}={$value} changed the list");
        }
    }
});

test('a non-empty invalid webhook delivery filter still answers 400 validation_failed', function (string $query): void {
    ['key' => $key] = elfOrgWithKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/api/v1/webhooks/deliveries?'.$query);

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
})->with([
    'an unknown status' => ['status=nope'],
    'an unknown event_type' => ['event_type=nope'],
]);

// ---------------------------------------------------------------------------
// GET /v1/exports, GET /v1/interviews/{id}/events
// ---------------------------------------------------------------------------

test('empty pagination parameters on the other list endpoints are not provided', function (): void {
    ['org' => $org, 'key' => $key] = elfOrgWithKey();
    $participant = Step6Fixtures::participantWithTranscript($org, Step6Fixtures::project($org), 'completato');
    $headers = ['Authorization' => 'Bearer '.$key];

    foreach (['/api/v1/exports', '/api/v1/interviews/'.PublicId::encode($participant).'/events'] as $path) {
        $baseline = $this->withHeaders($headers)->getJson($path);

        foreach (['limit', 'cursor'] as $filter) {
            $response = $this->withHeaders($headers)->getJson($path.'?'.$filter.'=');

            expect($response->status())->toBe($baseline->status(), $path.' ?'.$filter.'=');
            expect($response->json())->toBe($baseline->json());
        }
    }
});

// ---------------------------------------------------------------------------
// GET /v1/usage (a window, not a list, but the same query-parameter contract)
// ---------------------------------------------------------------------------

test('an empty usage window bound is not provided', function (string $query): void {
    ['key' => $key] = elfOrgWithKey();
    $headers = ['Authorization' => 'Bearer '.$key];

    $baseline = $this->withHeaders($headers)->getJson('/api/v1/usage');
    $baseline->assertOk();

    $response = $this->withHeaders($headers)->getJson('/api/v1/usage?'.$query);

    $response->assertOk();
    expect($response->json('interviews'))->toBe($baseline->json('interviews'));
})->with([
    'an empty from' => ['from='],
    'an empty to' => ['to='],
    'both empty' => ['from=&to='],
    'whitespace' => ['from=%20&to=%20'],
]);

test('a non-empty invalid usage window bound still answers 400 validation_failed', function (): void {
    ['key' => $key] = elfOrgWithKey();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/api/v1/usage?from=yesterday&to=');

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
});

// ---------------------------------------------------------------------------
// An array is an invalid shape for a scalar-only filter, empty or not
// ---------------------------------------------------------------------------
//
// `?field=` is "not provided", but `?field[]=` is not an empty value: it is an
// array, and a scalar-only filter refuses an array exactly as it refuses
// `?field[]=1`. Treating the first as "not provided" while the second answers
// 400 would make the same wrong shape succeed or fail on the value inside it.

test('an array sent to a scalar-only filter answers 400 validation_failed, empty or not', function (): void {
    ['key' => $key] = elfOrgWithKey();
    $headers = ['Authorization' => 'Bearer '.$key];

    $endpoints = [
        '/api/v1/interviews' => ['status', 'project_id', 'email', 'candidate_ref', 'created_after', 'created_before', 'external_id', 'source'],
        '/api/v1/projects' => ['status', 'role_code', 'assessment_type'],
        '/api/v1/webhooks/deliveries' => ['status', 'event_type', 'interview_id'],
        '/api/v1/usage' => ['from', 'to'],
    ];

    // Collected, not asserted one by one, so a failure names EVERY filter that
    // lets an array through instead of stopping at the first.
    $accepted = [];

    foreach ($endpoints as $path => $filters) {
        foreach ($filters as $filter) {
            foreach (['[]=' => 'an empty element', '[]=1' => 'a value', '[]=%20' => 'whitespace'] as $suffix => $name) {
                $response = $this->withHeaders($headers)->getJson($path.'?'.$filter.$suffix);

                if ($response->status() !== 400 || $response->json('code') !== 'validation_failed') {
                    $accepted[] = "{$path}?{$filter}{$suffix} ({$name}) answered {$response->status()}";
                }
            }
        }
    }

    expect($accepted)->toBe([]);
});

test('an array sent to a pagination or expand parameter answers 400, empty or not', function (): void {
    ['key' => $key] = elfOrgWithKey();
    $headers = ['Authorization' => 'Bearer '.$key];

    foreach (['limit', 'cursor', 'expand'] as $parameter) {
        foreach (['[]=', '[]=1'] as $suffix) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?'.$parameter.$suffix);

            expect($response->status())->toBe(400, "?{$parameter}{$suffix} answered {$response->status()}");
        }
    }
});

test('a metadata entry that is an array answers 400, empty or not; an empty scalar entry is not provided', function (): void {
    ['key' => $key] = elfOrgWithKey();
    $headers = ['Authorization' => 'Bearer '.$key];

    foreach (['metadata[a][]=', 'metadata[a][]=1', 'metadata[a][b]='] as $query) {
        $response = $this->withHeaders($headers)->getJson('/api/v1/interviews?'.$query);

        expect($response->status())->toBe(400, "?{$query} answered {$response->status()}");
    }

    // The map itself, and an empty scalar entry inside it, stay "not provided".
    foreach (['metadata=', 'metadata[a]=', 'metadata[a]=&metadata[b]='] as $query) {
        expect($this->withHeaders($headers)->getJson('/api/v1/interviews?'.$query)->status())->toBe(200, "?{$query}");
    }
});
