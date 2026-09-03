<?php

declare(strict_types=1);

/**
 * A date range for the dashboard.
 *
 * The five tiles and the recent-activity list had no filter at all, so the
 * numbers were always all-time and there was no way to ask "how did last month
 * go". The range applies to BOTH endpoints from one parser: two filters
 * derived separately would eventually disagree, and a dashboard whose tiles
 * and list describe different periods is worse than one with no filter, since
 * nothing on screen says they differ.
 *
 * Filtered on `created_at` — when the thing HAPPENED. `activity` orders by
 * `updated_at` because the operator wants the most recently touched first, but
 * a participant created in March and edited in June belongs to March when you
 * ask what March looked like.
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashOrgAndToken(): array
{
    $org = Organization::factory()->create();

    return ['org' => $org, 'token' => authTokenForRole($org, 'admin')];
}

function dashParticipant(Organization $org, string $createdAt): Participant
{
    return TenantContextScope::runFor($org->id, function () use ($org, $createdAt): Participant {
        $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'framework_version_id' => $fv->id,
        ]);

        $participant = Participant::factory()->create([
            'organization_id' => $org->id,
            'project_id' => $project->id,
        ]);

        // Forced after creation: `created_at` is set by the timestamps, and a
        // factory state would be overwritten.
        $participant->forceFill(['created_at' => $createdAt])->save();

        return $participant;
    });
}

test('metrics count only the requested range', function (): void {
    ['org' => $org, 'token' => $token] = dashOrgAndToken();
    dashParticipant($org, '2026-03-10 09:00:00');
    dashParticipant($org, '2026-06-10 09:00:00');

    $all = $this->withToken($token)->getJson('/api/dashboard/metrics');
    $march = $this->withToken($token)
        ->getJson('/api/dashboard/metrics?from=2026-03-01&to=2026-03-31');

    $all->assertOk();
    $march->assertOk();

    expect(array_sum($all->json('data.participants_by_status')))->toBe(2)
        ->and(array_sum($march->json('data.participants_by_status')))->toBe(1);
});

test('activity honours the SAME range', function (): void {
    // The one property that matters most here: tiles and list must describe
    // the same period, or the dashboard contradicts itself silently.
    ['org' => $org, 'token' => $token] = dashOrgAndToken();
    dashParticipant($org, '2026-03-10 09:00:00');
    dashParticipant($org, '2026-06-10 09:00:00');

    $march = $this->withToken($token)
        ->getJson('/api/dashboard/activity?from=2026-03-01&to=2026-03-31');

    $march->assertOk();
    expect($march->json('data'))->toHaveCount(1);
});

test('the range is INCLUSIVE of the last day', function (): void {
    // `to=2026-03-31` must include everything that happened ON the 31st. A
    // naive `<= 2026-03-31` compares against midnight and silently drops the
    // whole final day — the classic off-by-one that makes a month look short.
    ['org' => $org, 'token' => $token] = dashOrgAndToken();
    dashParticipant($org, '2026-03-31 23:45:00');

    $response = $this->withToken($token)
        ->getJson('/api/dashboard/metrics?from=2026-03-01&to=2026-03-31');

    expect(array_sum($response->json('data.participants_by_status')))->toBe(1);
});

test('no range means all time, exactly as before', function (): void {
    ['org' => $org, 'token' => $token] = dashOrgAndToken();
    dashParticipant($org, '2020-01-01 00:00:00');

    expect(array_sum($this->withToken($token)->getJson('/api/dashboard/metrics')->json('data.participants_by_status')))
        ->toBe(1);
});

test('a malformed date is refused, not silently ignored', function (): void {
    // Ignoring it would answer a different question than the one asked, and
    // the operator would read all-time numbers believing they were March.
    ['token' => $token] = dashOrgAndToken();

    $this->withToken($token)
        ->getJson('/api/dashboard/metrics?from=not-a-date')
        ->assertStatus(422);
});

test('a range that ends before it starts is refused', function (): void {
    ['token' => $token] = dashOrgAndToken();

    $this->withToken($token)
        ->getJson('/api/dashboard/metrics?from=2026-06-01&to=2026-03-01')
        ->assertStatus(422);
});
