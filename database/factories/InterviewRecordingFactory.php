<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewRecording;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for InterviewRecording (public-api step 6, G-01).
 *
 * NOTE: organization_id is stamped by `App\Models\Concerns\TenantScoped`'s
 * creating listener from the ambient tenant resolver — same discipline as
 * `Database\Factories\InterviewEventFactory`. A caller outside an org-scoped
 * context must wrap creation in `App\Support\Tenancy\TenantContextScope::runFor()`.
 *
 * @extends Factory<InterviewRecording>
 */
class InterviewRecordingFactory extends Factory
{
    protected $model = InterviewRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'object_key' => 'recordings/'.$this->faker->numberBetween(1, 999).'/'.$this->faker->uuid().'.ogg',
            'format' => 'audio/ogg',
            'duration_seconds' => $this->faker->numberBetween(30, 900),
            'size_bytes' => $this->faker->numberBetween(10_000, 5_000_000),
        ];
    }
}
