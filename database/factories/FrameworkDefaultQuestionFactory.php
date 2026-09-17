<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competency;
use App\Models\FrameworkDefaultQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for FrameworkDefaultQuestion (framework-catalogue-authoring PR1).
 *
 * Exercises the two constraints the model's migration authors:
 * `framework_default_questions_rev_competency_position_unique` and
 * `framework_default_questions_competency_revision_fk`.
 *
 * `text` is a locale map because the model is `HasTranslations`: the catalogue
 * is authored in `{en, it}` per CLAUDE.md's i18n mandate, and a factory that
 * produced a bare string would hide the one shape callers actually have to get
 * right.
 *
 * @extends Factory<FrameworkDefaultQuestion>
 */
class FrameworkDefaultQuestionFactory extends Factory
{
    protected $model = FrameworkDefaultQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competency_id' => Competency::factory(),
            // Resolved from the competency actually assigned above, rather
            // than defaulted independently to the baseline: a default
            // question belongs to the SAME revision as its competency, and
            // letting the two drift is precisely what the composite foreign
            // key exists to refuse. Reads `$attributes['competency_id']`
            // AFTER Eloquent resolves the sibling `Competency::factory()`
            // definition, so this also works when the caller overrides
            // `competency_id` with an existing model's id.
            'revision_id' => fn (array $attributes) => Competency::query()
                ->whereKey($attributes['competency_id'])
                ->value('revision_id'),
            'text' => [
                'en' => $this->faker->sentence().'?',
                'it' => $this->faker->sentence().'?',
            ],
            'position' => 1,
        ];
    }
}
