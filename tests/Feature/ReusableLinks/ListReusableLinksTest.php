<?php

declare(strict_types=1);

/**
 * GET /api/projects/{project}/reusable-links (reusable-interview-links, B2).
 *
 * The list describes links; it must never disclose the credential. The key set
 * of an item is pinned to the exported schema so that an added secret-bearing
 * field fails a test, and the body is searched for the token and the hash of a
 * link whose raw token the test knows.
 *
 * REQ: Listing Links Never Discloses The Token Or Its Hash
 *      (sdd/reusable-interview-links/spec/reusable-interview-links)
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\PublicApi\PublicId;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\ReusableLinkFixtures as Fx;

function reusableLinkListUrl(Project $project): string
{
    return "/api/projects/{$project->id}/reusable-links";
}

test('an item carries exactly the keys the exported schema declares, nothing more', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    Fx::link($project, ['label' => 'Stand A']);

    $item = $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->json('data.0');

    $keys = array_keys($item);
    sort($keys);

    expect($keys)->toBe(Fx::exportedKeys('ReusableInterviewLinkResource'));
});

test('the list never carries the token, its hash, a URL, an expiry or an internal id', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $rawToken = ReusableLinkTokenGenerator::generate();
    $link = Fx::link($project, [
        'label' => 'Stand A',
        'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
        'uses_count' => 3,
    ]);

    $response = $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk();

    $body = (string) $response->getContent();
    expect($body)->not->toContain($rawToken);
    expect($body)->not->toContain(ReusableLinkTokenGenerator::hash($rawToken));

    // The prefix identifies the link; it is the only part of the token shown.
    expect($response->json('data.0.token_prefix'))->toBe(substr($rawToken, 0, 16));

    $keys = Fx::keysAtAnyDepth($response->json());
    foreach (['token', 'link_token', 'token_hash', 'entry_url', 'url', 'expires_at', 'ttl', 'expires_in', 'organization_id', 'project_id'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }

    // `id` is the public identifier, never the internal integer.
    expect($response->json('data.0.id'))->toBe(PublicId::encode($link))->toStartWith('rlk_');
    expect($response->json('data.0.uses_count'))->toBe(3);
});

test('active links come first, then newest first, and disabled links are still listed', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $oldActive = Fx::link($project, ['label' => 'old active', 'created_at' => now()->subDays(5)]);
    $newActive = Fx::link($project, ['label' => 'new active', 'created_at' => now()->subDay()]);
    $newDisabled = Fx::link($project, ['label' => 'new disabled', 'created_at' => now(), 'disabled_at' => now()]);
    $oldDisabled = Fx::link($project, ['label' => 'old disabled', 'created_at' => now()->subDays(9), 'disabled_at' => now()]);

    $ids = $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toBe([
        PublicId::encode($newActive),
        PublicId::encode($oldActive),
        PublicId::encode($newDisabled),
        PublicId::encode($oldDisabled),
    ]);
});

test('status, usage and the creator name are reported for each link', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $creator = User::factory()->create(['organization_id' => $org->id, 'name' => 'Grace Hopper']);
    Fx::link($project, [
        'label' => 'Used',
        'created_by' => $creator->id,
        'uses_count' => 7,
        'last_used_at' => now()->subHour(),
    ]);
    Fx::link($project, [
        'label' => 'Orphan',
        'created_by' => null,
        'disabled_at' => now(),
        'created_at' => now()->subDay(),
    ]);

    $data = $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->json('data');

    expect($data[0]['status'])->toBe('active');
    expect($data[0]['uses_count'])->toBe(7);
    expect($data[0]['last_used_at'])->not->toBeNull();
    expect($data[0]['created_by'])->toBe(['name' => 'Grace Hopper']);
    expect($data[0]['disabled_at'])->toBeNull();

    // A link whose creator is gone has no creator; a disabled one says so.
    expect($data[1]['status'])->toBe('disabled');
    expect($data[1]['created_by'])->toBeNull();
    expect($data[1]['disabled_at'])->not->toBeNull();
    expect($data[1]['last_used_at'])->toBeNull();
});

test('only the links of the requested project are listed, never another project or organization', function (): void {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $project = Fx::project($org);
    $siblingProject = Fx::project($org);
    $foreignProject = Fx::project($otherOrg);

    $mine = Fx::link($project, ['label' => 'mine']);
    Fx::link($siblingProject, ['label' => 'sibling']);
    Fx::link($foreignProject, ['label' => 'foreign']);

    $ids = $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toBe([PublicId::encode($mine)]);
});

test('a closed project still lists its links', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org, ['status' => 'inactive', 'deadline_at' => now()->subDay()]);
    Fx::link($project, ['label' => 'still here']);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'still here');
});

test('a project with no links lists an empty collection, not a 404', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->getJson(reusableLinkListUrl($project))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('the number of queries does not grow with the number of links', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $jwt = authTokenForRole($org, 'admin');
    $queryCount = function () use ($jwt, $project): int {
        resetAuthGuardState();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($jwt)->getJson(reusableLinkListUrl($project))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Fx::link($project, ['created_by' => User::factory()->create(['organization_id' => $org->id])->id]);

    // The first request of a process also loads the permission cache; warm it so
    // the two measurements differ only in the number of links.
    $queryCount();
    $withOne = $queryCount();

    foreach (range(1, 9) as $_) {
        Fx::link($project, ['created_by' => User::factory()->create(['organization_id' => $org->id])->id]);
    }
    $withTen = $queryCount();

    // The creator is eager loaded: ten links with ten different creators cost
    // exactly what one link costs.
    expect($withTen)->toBe($withOne);
});
