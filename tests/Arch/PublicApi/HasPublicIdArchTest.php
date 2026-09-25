<?php

declare(strict_types=1);

/**
 * Pins the pairing `App\Support\PublicApi\PubliclyIdentifiable`'s own
 * docblock documents: `App\Models\Concerns\HasPublicId` only enforces its
 * OWN abstract `publicIdPrefix()` method at compile time — it does NOT, and
 * cannot, force a using model to also `implements PubliclyIdentifiable`.
 * Forgetting the `implements` clause compiles and runs fine; it only breaks
 * `App\Support\PublicApi\PublicId::encode()`'s `Model&PubliclyIdentifiable`
 * parameter type at static-analysis time (public-api step 5, Part A item 4).
 *
 * Scans every concrete class under `app/Models` rather than a hand-maintained
 * list — a future model that adds `use HasPublicId` without also adding
 * `implements PubliclyIdentifiable` fails THIS test immediately, instead of
 * silently compiling and only surfacing as a PHPStan error wherever it is
 * first passed to `PublicId::encode()`.
 */

use App\Models\Concerns\HasPublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

test('every model using HasPublicId implements PubliclyIdentifiable', function (): void {
    $modelsPath = app_path('Models');
    $finder = (new Finder)->files()->in($modelsPath)->name('*.php');

    $offenders = [];
    $usingCount = 0;

    foreach ($finder as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Models\\'.$relative;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $traits = class_uses_recursive($class);

        if (! in_array(HasPublicId::class, $traits, true)) {
            continue;
        }

        $usingCount++;

        if (! is_a($class, PubliclyIdentifiable::class, true)) {
            $offenders[] = $class;
        }
    }

    expect($usingCount)->toBeGreaterThan(0, 'precondition: at least one model uses HasPublicId');
    expect($offenders)->toBe([], 'model(s) using HasPublicId but not implementing PubliclyIdentifiable: '.implode(', ', $offenders));
});
