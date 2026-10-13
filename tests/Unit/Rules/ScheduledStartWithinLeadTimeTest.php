<?php

declare(strict_types=1);

/**
 * `ScheduledStartWithinLeadTime` rejects, in order: a timestamp without an
 * explicit UTC offset, one that is not a real date, one in the past, and one
 * closer than the minimum lead. Each failure carries its own message so a
 * caller can tell them apart.
 */

use App\Rules\ScheduledStartWithinLeadTime;
use App\Support\Scheduling\ScheduledInterviewWindow;
use Illuminate\Support\Carbon;

/**
 * @return list<string> the failure messages the rule produced
 */
function scheduledStartFailures(mixed $value): array
{
    $failures = [];
    (new ScheduledStartWithinLeadTime)->validate('scheduled_at', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-01T10:00:00Z');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a timestamp far enough ahead with an explicit offset passes', function (string $value): void {
    expect(scheduledStartFailures($value))->toBe([]);
})->with([
    'zulu' => '2026-10-01T12:00:00Z',
    'positive offset' => '2026-10-01T14:00:00+02:00',
    'fractional seconds' => '2026-10-02T09:30:00.250-05:00',
]);

test('a value without an explicit UTC offset is refused as ambiguous', function (mixed $value): void {
    expect(scheduledStartFailures($value))->toHaveCount(1)->and(scheduledStartFailures($value)[0])->toContain('explicit UTC offset');
})->with([
    'bare local datetime' => '2026-10-01T12:00:00',
    'date only' => '2026-10-02',
    'not a string' => 1790000000,
    'null' => null,
]);

test('a well-shaped but impossible date is refused as not a valid date', function (): void {
    expect(scheduledStartFailures('2026-13-45T10:00:00Z'))->toBe(['The :attribute is not a valid date.']);
});

test('a time in the past, or exactly now, is refused as not in the future', function (string $value): void {
    expect(scheduledStartFailures($value))->toBe(['The :attribute must be a time in the future.']);
})->with([
    'past' => '2026-10-01T09:00:00Z',
    'now' => '2026-10-01T10:00:00Z',
]);

test('a future time inside the minimum lead is refused with the lead in the message', function (): void {
    $lead = ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES;

    expect(scheduledStartFailures('2026-10-01T10:05:00Z'))
        ->toBe(["The :attribute must be at least {$lead} minutes from now."]);
});

test('the lead boundary itself is accepted', function (): void {
    $boundary = Carbon::now()->addMinutes(ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES)->toIso8601String();

    expect(scheduledStartFailures($boundary))->toBe([]);
});
