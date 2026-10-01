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
function elfOrgWithKey(): array
{
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
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
 * @return array{key: string, ids: list<string>, foreign: list<string>}
 */
function elfInterviewWorld(): array
{
    ['org' => $org, 'key' => $key] = elfOrgWithKey();
    ['org' => $other] = elfOrgWithKey();

    $project = elfProject($org);
    $second = elfProject($org, 'FLL');
    $otherProject = elfProject($other);

    $own = [
        elfParticipant($org, $project, ['status' => 'in_attesa', 'candidate_ref' => 'elf-a', 'email' => 'elf-a@example.test', 'metadata' => ['ats_application_id' => 'A-1'], 'external_id' => 11, 'source' => 'acme-ats']),
        elfParticipant($org, $project, ['status' => 'in_corso', 'candidate_ref' => 'elf-b', 'email' => 'elf-b@example.test', 'metadata' => ['ats_application_id' => 'B-2']]),
        elfParticipant($org, $second, ['status' => 'errore', 'candidate_ref' => 'elf-c', 'email' => 'elf-c@example.test']),
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

    return ['key' => $key, 'ids' => $ids($own), 'foreign' => $ids($foreign)];
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

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$world['key']])
        ->getJson('/api/v1/interviews?status=&email=&candidate_ref=&project_id=&external_id=&source=');

    $response->assertOk();
    expect(array_intersect(elfSortedIds($response->json('data')), $world['foreign']))->toBe([]);
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
