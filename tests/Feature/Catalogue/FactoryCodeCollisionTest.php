<?php

declare(strict_types=1);

/**
 * RED/GREEN — `CompetencyFactory` / `RoleFactory` codes never collide with a
 * row that is already in the database.
 *
 * `faker->unique()` only remembers values the FAKER itself produced; it is
 * blind to rows the baseline migration/seeder (TMG, ICO, ...) or an earlier
 * test already inserted. A random `???` code therefore collided with a real
 * row now and then, failing `framework_competencies_revision_code_unique` in
 * an otherwise green full run. The first tests here are deterministic: they
 * seed faker, compute the code the factory WOULD draw, occupy it, replay the
 * same seed and require a different code.
 */

use App\Models\Competency;
use App\Models\Role;
use Faker\Generator;

/**
 * Codes the factory's first draw yields for a given seed, without persisting.
 *
 * @param  class-string<Competency|Role>  $model
 */
function predictedFactoryCode(string $model, int $seed): string
{
    app(Generator::class)->seed($seed);
    app(Generator::class)->unique(true);

    return (string) $model::factory()->make()->code;
}

function replaySeed(int $seed): void
{
    app(Generator::class)->seed($seed);
    app(Generator::class)->unique(true);
}

it('does not reuse the code of an existing competency', function (): void {
    $taken = predictedFactoryCode(Competency::class, 1234);
    Competency::factory()->create(['code' => $taken]);

    replaySeed(1234);
    $created = Competency::factory()->create();

    expect($created->code)->not->toBe($taken);
});

it('does not reuse the code of an existing role', function (): void {
    $taken = predictedFactoryCode(Role::class, 1234);
    Role::factory()->create(['code' => $taken]);

    replaySeed(1234);
    $created = Role::factory()->create();

    expect($created->code)->not->toBe($taken);
});

it('creates many competencies and roles in one test without a collision', function (): void {
    Competency::factory()->count(200)->create();
    Role::factory()->count(50)->create();

    expect(true)->toBeTrue();
});
