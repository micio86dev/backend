<?php

declare(strict_types=1);

/**
 * N+1 guard for GET /api/evaluations (backoffice-missing-pages D6).
 *
 * Two queries per request, never per row: the paginated page, plus ONE
 * grouped query over `competency_results` for the page's ids — guards
 * against an accidental per-row lazy-load (e.g. a naive `$evaluation->
 * competencyResults` access inside the resource's toArray()).
 *
 * REQ: EvaluationIndexQuery two-query budget (design D6)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function nPlusOneFixtureRows(Organization $org, int $count): void
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    for ($i = 0; $i < $count; $i++) {
        $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
        $evaluation = Evaluation::factory()->create([
            'participant_id' => $participant->id,
            'status' => 'completed',
            'evaluated_at' => now(),
        ]);
        CompetencyResult::factory()->create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => 'COL',
            'score' => 4.0,
            'reliability' => 0.9,
        ]);
    }
}

function countQueriesFor(callable $callback): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    $callback();

    // DB::listen registers a persistent listener with no unlisten API —
    // acceptable here because each call site uses a fresh test (RefreshDatabase
    // resets the connection/listener state between tests).
    return $count;
}

test('query count for the evaluations index does not grow with the number of rows', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    nPlusOneFixtureRows($org, 3);
    app(TenantResolver::class)->setOrgId($org->id);

    // Prime Spatie's permission/role cache with a throwaway request BEFORE
    // measuring either count — hasRole() lazy-loads `permissions`/`roles`
    // on its FIRST check per test and caches them afterward; measuring the
    // very first authenticated call would count that one-time warm-up
    // against the "small" batch and produce a false N+1 signal that has
    // nothing to do with row count.
    $this->withToken($token)->getJson('/api/evaluations')->assertOk();

    $smallCount = countQueriesFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/evaluations')->assertOk();
    });

    nPlusOneFixtureRows($org, 12);
    app(TenantResolver::class)->setOrgId($org->id);
    $largeCount = countQueriesFor(function () use ($token): void {
        $this->withToken($token)->getJson('/api/evaluations')->assertOk();
    });

    expect($largeCount)->toBe($smallCount);
    // Pin the expected budget explicitly (design D6: "two queries per
    // request, never per row") — not just "equal to each other", which
    // would also pass if both leaked to some other constant like 10.
    expect($smallCount)->toBe(2);
});
