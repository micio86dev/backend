<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for InterviewSnapshot (C7a proctoring).
 *
 * @extends Factory<InterviewSnapshot>
 */
final class InterviewSnapshotFactory extends Factory
{
    protected $model = InterviewSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            's3_key' => 'snapshots/'.$this->faker->uuid().'.jpg',
            'taken_at' => now(),
        ];
    }
}
