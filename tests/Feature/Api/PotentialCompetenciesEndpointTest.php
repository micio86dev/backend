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

use App\Models\Competency;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

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
