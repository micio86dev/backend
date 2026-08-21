<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for InterviewSessionLivePeriod (interview-session-started-at, D1).
 *
 * No default implies a duration on its own — the default state is a CLOSED
 * one-minute period, and callers name `->open()` explicitly when they want
 * a still-live stretch. `organization_id` is derived from the session, the
 * same forceFill pattern InterviewSessionFactory uses.
 *
 * @extends Factory<InterviewSessionLivePeriod>
 */
final class InterviewSessionLivePeriodFactory extends Factory
{
    protected $model = InterviewSessionLivePeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = now()->subMinute();

        return [
            'provider_session_ref' => 'period_'.$this->faker->uuid(),
            'started_at' => $started,
            'ended_at' => now(),
            'closed_reason' => 'end',
        ];
    }

    /** A still-live stretch — `ended_at` and `closed_reason` are both null. */
    public function open(): static
    {
        return $this->state(fn (): array => [
            'ended_at' => null,
            'closed_reason' => null,
        ]);
    }

    /** An explicitly closed stretch of `$seconds` length. */
    public function closed(int $seconds = 60, string $reason = 'end'): static
    {
        return $this->state(function (array $attributes) use ($seconds, $reason): array {
            $started = $attributes['started_at'] ?? now()->subSeconds($seconds);

            return [
                'started_at' => $started,
                'ended_at' => Carbon::parse($started)->addSeconds($seconds),
                'closed_reason' => $reason,
            ];
        });
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InterviewSessionLivePeriod $period): void {
            if ($period->interview_session_id && ! $period->organization_id) {
                $session = InterviewSession::find($period->interview_session_id);

                if ($session !== null) {
                    $period->forceFill(['organization_id' => $session->organization_id]);
                }
            }
        });
    }
}
