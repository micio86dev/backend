<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntegrityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for IntegrityEvent (C7a proctoring).
 *
 * Defaults to a weightless kind so a test that only needs "an event exists"
 * does not accidentally move a risk score it never meant to assert on.
 *
 * @extends Factory<IntegrityEvent>
 */
final class IntegrityEventFactory extends Factory
{
    protected $model = IntegrityEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'looking_down',
            'payload' => [],
            'ts' => now(),
        ];
    }
}
