<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewSession;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory for InterviewSession (C7a, interview-session-started-at D8).
 *
 * The DEFAULT state is production's own INSERT shape
 * (`InterviewController.php:642-650`): `status = 'pending'`, no
 * `provider_session_ref`, no `started_at`, no `ended_at`, no `ended_reason`.
 * A `pending` session never reached the provider — it MUST NOT carry a
 * recorded start, or a fixture asserts a state production never produces.
 * This is the third appearance of that defect class on this project (after
 * `question_index`'s 1-based fixtures) — see
 * `openspec/changes/interview-session-started-at/proposal.md`.
 *
 * Three explicit states replace the old always-finished default:
 *   - `->live()`     — `in_corso` + one OPEN live period.
 *   - `->ended()`    — `completed` + one CLOSED live period +
 *                       `started_at`/`ended_at` set consistently.
 *   - `->resumed()`  — two CLOSED periods with a gap between them, the
 *                       fixture shape of the crux scenario the real
 *                       endpoints exercise end to end.
 *
 * Every foreign key is REQUIRED from the caller: a session belongs to a
 * participant, a project and a framework version that must agree on one
 * organization, and a factory that invented them independently would produce
 * rows no real flow can create.
 *
 * `organization_id` is not fillable on tenant-scoped models, so it is applied
 * with forceFill after creation.
 *
 * @extends Factory<InterviewSession>
 */
final class InterviewSessionFactory extends Factory
{
    protected $model = InterviewSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_index' => 0,
            'competency_code' => 'COL',
            'provider' => 'heygen',
            'status' => 'pending',
        ];
    }

    /**
     * `in_corso`, with exactly one OPEN live period — a session mid-flight,
     * still being interviewed.
     */
    public function live(): static
    {
        return $this->state(function (): array {
            $ref = 'sess_'.$this->faker->uuid();

            return [
                'status' => 'in_corso',
                'provider_session_ref' => $ref,
                'started_at' => now()->subMinute(),
            ];
        })->afterCreating(function (InterviewSession $session): void {
            $session->livePeriods()->create([
                'provider_session_ref' => $session->provider_session_ref,
                'started_at' => $session->started_at,
                'ended_at' => null,
                'closed_reason' => null,
            ]);
        });
    }

    /**
     * `completed`, with exactly one CLOSED live period of `$seconds` length.
     * `started_at`/`ended_at` on the session row are set consistently with
     * the period (they are a span for the FIRST live moment and the end,
     * per D2 — not read as the duration, but kept coherent for any test
     * asserting them directly).
     */
    public function ended(int $seconds = 600): static
    {
        return $this->state(function () use ($seconds): array {
            $started = now()->subSeconds($seconds + 5);
            $ended = $started->copy()->addSeconds($seconds);

            return [
                'status' => 'completed',
                'ended_reason' => 'completed',
                'provider_session_ref' => 'sess_'.$this->faker->uuid(),
                'started_at' => $started,
                'ended_at' => $ended,
            ];
        })->afterCreating(function (InterviewSession $session): void {
            $session->livePeriods()->create([
                'provider_session_ref' => $session->provider_session_ref,
                'started_at' => $session->started_at,
                'ended_at' => $session->ended_at,
                'closed_reason' => 'end',
            ]);
        });
    }

    /**
     * `completed`, with TWO CLOSED periods separated by a gap — the fixture
     * shape of a resumed interview: `$first` seconds live, then `$gap`
     * seconds with no live provider session, then `$second` more seconds
     * live before ending. `liveSeconds()` on a `->resumed()` session sums to
     * `$first + $second`, never the span across `$gap`.
     */
    public function resumed(int $first = 240, int $gap = 3600, int $second = 360): static
    {
        return $this->state(function () use ($first, $gap, $second): array {
            $firstStart = now()->subSeconds($first + $gap + $second);
            $secondEnd = $firstStart->copy()->addSeconds($first + $gap + $second);

            return [
                'status' => 'completed',
                'ended_reason' => 'completed',
                'provider_session_ref' => 'sess_'.$this->faker->uuid(),
                // (D2) write-once: the FIRST live moment, never reset by the resume.
                'started_at' => $firstStart,
                'ended_at' => $secondEnd,
            ];
        })->afterCreating(function (InterviewSession $session) use ($first, $gap, $second): void {
            $firstStart = $session->started_at;
            $firstEnd = $firstStart->copy()->addSeconds($first);
            $secondStart = $firstEnd->copy()->addSeconds($gap);
            $secondEnd = $secondStart->copy()->addSeconds($second);

            $session->livePeriods()->create([
                'provider_session_ref' => 'sess_'.Str::random(10),
                'started_at' => $firstStart,
                'ended_at' => $firstEnd,
                'closed_reason' => 'resume',
            ]);
            $session->livePeriods()->create([
                'provider_session_ref' => $session->provider_session_ref,
                'started_at' => $secondStart,
                'ended_at' => $secondEnd,
                'closed_reason' => 'end',
            ]);
        });
    }

    /**
     * Derives `organization_id` from the participant when the caller did not
     * set it, mirroring ParticipantFactory: it is not fillable on a
     * tenant-scoped model, so it has to be forced on after making.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (InterviewSession $session): void {
            if ($session->participant_id && ! $session->organization_id) {
                $participant = Participant::find($session->participant_id);

                if ($participant !== null) {
                    $session->forceFill(['organization_id' => $participant->organization_id]);
                }
            }
        });
    }
}
