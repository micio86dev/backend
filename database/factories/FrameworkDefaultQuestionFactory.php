<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for FrameworkDefaultQuestion (framework-catalogue-authoring PR1).
 *
 * Written because the review gate pointed out that the model shipped with
 * neither a factory nor a behavioural test, so the two constraints its
 * migration went out of its way to author —
 * `framework_default_questions_rev_competency_position_unique` and
 * `framework_default_questions_competency_revision_fk` — had never been seen
 * to refuse anything. Every other table in this change has a test that watches
 * its constraints fire.
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
            // Resolved rather than defaulted to the baseline: a default
            // question belongs to the SAME revision as its competency, and
            // letting the two drift is precisely what the composite foreign
            // key exists to refuse.
            'revision_id' => FrameworkCatalogRevision::query()
                ->where('is_baseline', true)
                ->value('id'),
            'competency_id' => Competency::factory(),
            'text' => [
                'en' => $this->faker->sentence().'?',
                'it' => $this->faker->sentence().'?',
            ],
            'position' => 1,
        ];
    }
}
