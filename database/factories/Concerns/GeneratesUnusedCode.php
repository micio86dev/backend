<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use OverflowException;

/**
 * Draws a three-letter catalogue code that no existing row already uses.
 *
 * Why this exists: `$faker->unique()` only avoids repeating values the FAKER
 * has already produced. It is blind to rows that are already in the database
 * (the baseline catalogue seeds real codes such as TMG, ICO, FLL ...), so a
 * random `???` code collided with a seeded row about once in 200 full runs and
 * failed `(revision_id, code)` uniqueness. Here every draw is checked against
 * the table, across ALL revisions (stricter than the constraint), and drawn
 * again while taken.
 *
 * Expects to be used in a `Illuminate\Database\Eloquent\Factories\Factory`
 * (it reads `$this->faker`).
 */
trait GeneratesUnusedCode
{
    /**
     * Upper bound on draws, so an exhausted pool fails loudly instead of
     * looping forever.
     */
    private const MAX_CODE_ATTEMPTS = 100;

    /**
     * @param  class-string<Model>  $model
     */
    protected function unusedCode(string $model): string
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            try {
                $code = strtoupper($this->faker->unique()->lexify('???'));
            } catch (OverflowException $e) {
                throw new LogicException(
                    "Faker's unique pool is exhausted while generating a {$model} code.",
                    previous: $e,
                );
            }

            if (! $model::query()->withoutGlobalScopes()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new LogicException(
            "No unused {$model} code found in ".self::MAX_CODE_ATTEMPTS.' attempts.',
        );
    }
}
