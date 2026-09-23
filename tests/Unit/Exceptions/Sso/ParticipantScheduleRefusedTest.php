<?php

declare(strict_types=1);

/**
 * RED — PR-E/T-E1: ParticipantScheduleRefused + ParticipantScheduleRefusalReason
 * (interview-scheduling, design AD-7).
 *
 * Mirrors EntryLinkRefused/EntryLinkRefusalReason exactly: the exception
 * carries a closed-set `reason`, never an HTTP shape — each caller
 * (ParticipantScheduleController, M2m\ParticipantController) maps the reason
 * onto its own response literal.
 */

use App\Exceptions\Sso\ParticipantScheduleRefusalReason;
use App\Exceptions\Sso\ParticipantScheduleRefused;

test('ParticipantScheduleRefusalReason has exactly the three expected cases', function (): void {
    expect(ParticipantScheduleRefusalReason::Terminal->value)->toBe('terminal')
        ->and(ParticipantScheduleRefusalReason::LeadTimeTooShort->value)->toBe('lead_time_too_short')
        ->and(ParticipantScheduleRefusalReason::NotScheduled->value)->toBe('not_scheduled');

    expect(ParticipantScheduleRefusalReason::cases())->toHaveCount(3);
});

test('ParticipantScheduleRefused carries its reason and defaults its message to the reason value', function (): void {
    $exception = new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::Terminal);

    expect($exception->reason)->toBe(ParticipantScheduleRefusalReason::Terminal)
        ->and($exception->getMessage())->toBe('terminal');
});

test('ParticipantScheduleRefused accepts an explicit message overriding the reason value', function (): void {
    $exception = new ParticipantScheduleRefused(
        ParticipantScheduleRefusalReason::LeadTimeTooShort,
        'custom message',
    );

    expect($exception->reason)->toBe(ParticipantScheduleRefusalReason::LeadTimeTooShort)
        ->and($exception->getMessage())->toBe('custom message');
});
