<?php

declare(strict_types=1);

/**
 * GET /api/framework/potential-competencies.
 *
 * MTG and LAT belong to NO role — that is what makes them the `potential`
 * set — so `/roles/{code}/competencies` cannot serve them. The backoffice was
 * building the two options locally from hardcoded codes with no `id`, and
 * `CompetencyPicker` refuses to tick a box without one: both rendered, neither
 * responded, and an already-persisted selection rendered unchecked. A
 * `potential` project could not have its competencies chosen at all.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

function potentialCompetenciesToken(): string
{
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    return auth('api')->login($user);
}

test('it returns MTG and LAT, each with the id a picker needs', function (): void {
    $response = $this->withToken(potentialCompetenciesToken())
        ->getJson('/api/framework/potential-competencies');

    $response->assertOk();

    $rows = collect($response->json('data'));

    expect($rows->pluck('code')->sort()->values()->all())->toBe(['LAT', 'MTG']);

    // The `id` is the whole point of this endpoint.
    foreach ($rows as $row) {
        expect($row['id'])->toBeInt()->toBeGreaterThan(0);
        expect($row['name'])->not->toBeEmpty();
    }
});

test('it is driven by TYPE, not by a hardcoded pair of codes', function (): void {
    // A third potential competency must appear the day it is authored, not
    // the day someone remembers to edit the controller.
    $extra = Competency::factory()->create(['code' => 'ZZZ', 'type' => 'potential']);

    $codes = collect(
        $this->withToken(potentialCompetenciesToken())
            ->getJson('/api/framework/potential-competencies')
            ->json('data')
    )->pluck('code');

    expect($codes)->toContain($extra->code);
});

test('it never returns a standard competency', function (): void {
    // The control: an unfiltered listing would satisfy both cases above.
    $codes = collect(
        $this->withToken(potentialCompetenciesToken())
            ->getJson('/api/framework/potential-competencies')
            ->json('data')
    )->pluck('code');

    expect($codes)->not->toContain('PRS');
});

test('it requires authentication, like every other framework route', function (): void {
    $this->getJson('/api/framework/potential-competencies')->assertUnauthorized();
});

/**
 * @return array<string, bool> bars_available keyed by competency code
 */
function potentialBarsAvailable(object $test, string $query = ''): array
{
    return collect(
        $test->withToken(potentialCompetenciesToken())
            ->getJson('/api/framework/potential-competencies'.$query)
            ->assertOk()
            ->json('data')
    )->pluck('bars_available', 'code')->all();
}

test('MTG and LAT report bars_available=true, so the picker lets them be selected', function (): void {
    // The owner's report: both boxes rendered disabled because every row said
    // false, and CompetencyPicker disables a competency with no anchors.
    // MTG and LAT DO carry role-less BARS indicators, so they are scoreable.
    expect(potentialBarsAvailable($this))->toBe(['LAT' => true, 'MTG' => true]);
});

test('a potential competency with no role-less indicator reports bars_available=false', function (): void {
    Competency::factory()->create(['code' => 'ZZZ', 'type' => 'potential']);

    $flags = potentialBarsAvailable($this);

    expect($flags['ZZZ'])->toBeFalse()
        ->and($flags['MTG'])->toBeTrue();
});

test('a role-bound indicator does not count as role-less coverage', function (): void {
    $extra = Competency::factory()->create(['code' => 'ZZZ', 'type' => 'potential']);
    $role = Role::factory()->create(['revision_id' => $extra->revision_id]);
    BarsIndicator::factory()->create([
        'revision_id' => $extra->revision_id,
        'role_id' => $role->id,
        'competency_id' => $extra->id,
    ]);

    expect(potentialBarsAvailable($this)['ZZZ'])->toBeFalse();

    BarsIndicator::factory()->roleLess()->create([
        'revision_id' => $extra->revision_id,
        'competency_id' => $extra->id,
    ]);

    expect(potentialBarsAvailable($this)['ZZZ'])->toBeTrue();
});

test('coverage is evaluated in the pinned revision, not in latest published', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    // A newer published revision whose MTG clone has NO indicators.
    $newer = FrameworkCatalogRevision::factory()->draft()->create();
    Competency::factory()->potential()->create(['revision_id' => $newer->id, 'code' => 'MTG']);
    DB::table('framework_catalog_revisions')->where('id', $newer->id)->update([
        'state' => 'published',
        'published_at' => now()->addMinute(),
    ]);

    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);
    app(TenantResolver::class)->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id, 'revision_id' => $baseline->id]);

    $pinned = collect($this->withToken($token)
        ->getJson("/api/framework/potential-competencies?framework_version_id={$fv->id}")
        ->assertOk()->json('data'))->pluck('bars_available', 'code')->all();
    $latest = collect($this->withToken($token)
        ->getJson('/api/framework/potential-competencies')
        ->assertOk()->json('data'))->pluck('bars_available', 'code')->all();

    // Baseline MTG is covered; the newer revision's MTG is a different row
    // with no indicators and must not borrow the baseline's coverage.
    expect($pinned)->toBe(['LAT' => true, 'MTG' => true])
        ->and($latest)->toBe(['MTG' => false]);
});

test('coverage adds no per-row queries', function (): void {
    $token = potentialCompetenciesToken();

    $count = function () use ($token): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/framework/potential-competencies')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // warm any per-process caches
    $few = $count();

    Competency::factory()->count(5)->create(['type' => 'potential']);

    expect($count())->toBe($few);
});
