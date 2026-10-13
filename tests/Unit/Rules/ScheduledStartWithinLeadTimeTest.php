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

const SCHEDULED_START_TEST_NOW = '2026-10-01T10:00:00Z';

/**
 * An ISO-8601 UTC instant the given number of minutes after the frozen "now".
 */
function scheduledStartMinutesFromNow(int $minutes): string
{
    return Carbon::parse(SCHEDULED_START_TEST_NOW)->addMinutes($minutes)->utc()->format('Y-m-d\TH:i:s\Z');
}

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
    Carbon::setTestNow(SCHEDULED_START_TEST_NOW);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a timestamp far enough ahead with an explicit offset passes', function (string $suffix, int $minutesAhead): void {
    $lead = ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES;
    $instant = Carbon::parse(SCHEDULED_START_TEST_NOW)->addMinutes($lead + $minutesAhead);

    $value = match ($suffix) {
        'zulu' => $instant->utc()->format('Y-m-d\TH:i:s\Z'),
        'positive offset' => $instant->setTimezone('+02:00')->format('Y-m-d\TH:i:sP'),
        'fractional seconds' => $instant->setTimezone('-05:00')->format('Y-m-d\TH:i:s.vP'),
    };

    expect(scheduledStartFailures($value))->toBe([]);
})->with([
    'zulu' => ['zulu', 1],
    'positive offset' => ['positive offset', 1],
    'fractional seconds' => ['fractional seconds', 24 * 60],
]);

test('a value without an explicit UTC offset is refused as ambiguous', function (mixed $value): void {
    $failures = scheduledStartFailures($value);

    expect($failures)->toHaveCount(1)->and($failures[0])->toContain('explicit UTC offset');
})->with([
    'bare local datetime' => '2026-10-01T12:00:00',
    'date only' => '2026-10-02',
    'not a string' => 1790000000,
    'null' => null,
]);

test('a well-shaped but impossible date is refused as not a valid date', function (): void {
    expect(scheduledStartFailures('2026-13-45T10:00:00Z'))->toBe(['The :attribute is not a valid date.']);
});

test('a time in the past, or exactly now, is refused as not in the future', function (int $minutes): void {
    expect(scheduledStartFailures(scheduledStartMinutesFromNow($minutes)))->toBe(['The :attribute must be a time in the future.']);
})->with([
    'past' => -60,
    'now' => 0,
]);

test('a future time inside the minimum lead is refused with the lead in the message', function (): void {
    $lead = ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES;

    expect(scheduledStartFailures(scheduledStartMinutesFromNow(1)))
        ->toBe(["The :attribute must be at least {$lead} minutes from now."]);
});

test('the lead boundary is accepted and one minute short of it is refused', function (): void {
    $lead = ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES;

    expect(scheduledStartFailures(scheduledStartMinutesFromNow($lead)))->toBe([])
        ->and(scheduledStartFailures(scheduledStartMinutesFromNow($lead - 1)))
        ->toBe(["The :attribute must be at least {$lead} minutes from now."]);
});
