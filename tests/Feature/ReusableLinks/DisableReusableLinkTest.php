<?php

declare(strict_types=1);

/**
 * DELETE /api/projects/{project}/reusable-links/{link} (reusable-interview-links, B2).
 *
 * Disabling is the only revocation verb: soft, immediate, irreversible and
 * idempotent. The row is kept (the list still shows it, with its usage), the
 * visitors it produced are untouched, and a second disable answers 204 without
 * changing the first disable time and without a second audit row. The admin
 * surface of a link is exactly create, list and disable: the route table is
 * asserted so a re-enable, an edit or a "show again" cannot appear unnoticed.
 *
 * REQ: Disabling A Link Is Idempotent, Immediate And Irreversible
 *      (sdd/reusable-interview-links/spec/reusable-interview-links)
 *      Reusable Link Mutations Are Audited (sdd/reusable-interview-links/spec/audit-log)
 */

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Helpers\ReusableLinkFixtures as Fx;

function reusableLinkDisableUrl(Project $project, ReusableInterviewLink|string $link): string
{
    $id = is_string($link) ? $link : PublicId::encode($link);

    return "/api/projects/{$project->id}/reusable-links/{$id}";
}

/**
 * The stored row, read past the tenant scope.
 */
function reusableLinkDisableRow(ReusableInterviewLink $link): ReusableInterviewLink
{
    return ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id);
}

test('an admin and an operator disable a link: 204, the time and the actor are recorded, the row stays', function (string $role): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project, ['label' => 'Stand', 'uses_count' => 4, 'last_used_at' => now()->subHour()]);
    ['user' => $user, 'token' => $jwt] = authUserAndTokenForRole($org, $role);

    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();

    $row = reusableLinkDisableRow($link);
    expect($row->disabled_at)->not->toBeNull();
    expect($row->disabled_by)->toBe($user->id);
    // Nothing else about the link is lost.
    expect($row->uses_count)->toBe(4);
    expect($row->last_used_at)->not->toBeNull();
    expect($row->label)->toBe('Stand');
    expect($row->token_hash)->toBe($link->token_hash);
})->with(['admin', 'operator']);

test('a disabled link is listed as disabled with its usage unchanged', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project, ['uses_count' => 3]);
    $jwt = authTokenForRole($org, 'admin');

    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();

    $item = $this->withToken($jwt)->getJson("/api/projects/{$project->id}/reusable-links")->assertOk()->json('data.0');

    expect($item['status'])->toBe('disabled');
    expect($item['uses_count'])->toBe(3);
    expect($item['disabled_at'])->not->toBeNull();
});

test('disabling twice is idempotent: 204 again, the first time kept, exactly one audit row', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project);
    $jwt = authTokenForRole($org, 'admin');

    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();
    $firstDisabledAt = reusableLinkDisableRow($link)->disabled_at;

    $this->travel(5)->minutes();
    resetAuthGuardState();
    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();

    $row = reusableLinkDisableRow($link);
    expect($row->disabled_at?->toIso8601String())->toBe($firstDisabledAt?->toIso8601String());

    expect(AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.disabled')->count())->toBe(1);
});

test('the disable is audited with the actor, the link, and the time, and never the token or the hash', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $rawToken = ReusableLinkTokenGenerator::generate();
    $link = Fx::link($project, [
        'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
        'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
    ]);
    ['user' => $user, 'token' => $jwt] = authUserAndTokenForRole($org, 'operator');

    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();

    $entry = AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.disabled')->sole();

    expect($entry->actor_id)->toBe($user->id);
    expect($entry->organization_id)->toBe($org->id);
    expect($entry->subject_type)->toBe('reusable_interview_link');
    expect($entry->subject_id)->toBe($link->id);
    expect($entry->before)->toBe(['disabled_at' => null]);
    expect(array_keys((array) $entry->after))->toBe(['disabled_at']);
    expect($entry->after['disabled_at'])->toBe(reusableLinkDisableRow($link)->disabled_at?->toIso8601String());

    $encoded = (string) json_encode($entry->getAttributes());
    expect($encoded)->not->toContain($rawToken);
    expect($encoded)->not->toContain($link->token_hash);
});

test('a malformed id, a link of another project, a link of another organization and an unknown id are 404 and change nothing', function (string $case): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $mine = Fx::link($project);
    $siblingLink = Fx::link(Fx::project($org));
    $foreignLink = Fx::link(Fx::project(Organization::factory()->create()));

    $target = match ($case) {
        'malformed' => 'not-a-link-id',
        'wrong prefix' => 'prj_'.substr(PublicId::encode($mine), 4),
        'lowercase ulid' => 'rlk_'.strtolower(substr(PublicId::encode($mine), 4)),
        'unknown' => 'rlk_01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'another project' => PublicId::encode($siblingLink),
        'another organization' => PublicId::encode($foreignLink),
    };

    $this->withToken(authTokenForRole($org, 'admin'))
        ->deleteJson(reusableLinkDisableUrl($project, $target))
        ->assertNotFound();

    foreach ([$mine, $siblingLink, $foreignLink] as $link) {
        expect(reusableLinkDisableRow($link)->disabled_at)->toBeNull();
    }
    expect(AuditLog::withoutGlobalScopes()->where('action', 'reusable_link.disabled')->count())->toBe(0);
})->with(['malformed', 'wrong prefix', 'lowercase ulid', 'unknown', 'another project', 'another organization']);

test('a link of a closed project can still be disabled', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org, ['status' => 'inactive', 'deadline_at' => now()->subDay()]);
    $link = Fx::link($project);

    $this->withToken(authTokenForRole($org, 'admin'))
        ->deleteJson(reusableLinkDisableUrl($project, $link))
        ->assertNoContent();

    expect(reusableLinkDisableRow($link)->disabled_at)->not->toBeNull();
});

test('the visitors a link produced survive its disabling with their marker intact', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project);

    TenantContextScope::runFor($org->id, function () use ($link): void {
        Participant::factory()->count(3)->fromReusableLink($link)->create();
    });

    $this->withToken(authTokenForRole($org, 'admin'))
        ->deleteJson(reusableLinkDisableUrl($project, $link))
        ->assertNoContent();

    $visitors = Participant::withoutGlobalScopes()->where('reusable_interview_link_id', $link->id)->count();
    expect($visitors)->toBe(3);
});

test('exactly three operations exist under the project reusable-links path: create, list and disable', function (): void {
    $operations = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/projects/{project}/reusable-links'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->map(fn (string $method): string => $method.' '.$route->uri()))
        ->sort()
        ->values()
        ->all();

    // No PUT, PATCH, GET of a single link, re-enable, rotate or "show again".
    expect($operations)->toBe([
        'DELETE api/projects/{project}/reusable-links/{link}',
        'GET api/projects/{project}/reusable-links',
        'POST api/projects/{project}/reusable-links',
    ]);
});

test('a failing audit write still yields the 204 and logs the failure', function (): void {
    $org = Organization::factory()->create();
    $project = Fx::project($org);
    $link = Fx::link($project);
    $jwt = authTokenForRole($org, 'admin');

    Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
        throw new RuntimeException('audit store is down');
    });
    Log::spy();

    $this->withToken($jwt)->deleteJson(reusableLinkDisableUrl($project, $link))->assertNoContent();

    expect(reusableLinkDisableRow($link)->disabled_at)->not->toBeNull();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'audit.record.failed')->once();
});
