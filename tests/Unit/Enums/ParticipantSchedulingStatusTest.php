<?php

declare(strict_types=1);

/**
 * RED — PR-A/T-A2: ParticipantSchedulingStatus backed enum (interview-scheduling,
 * design AD-1). Tracks a scheduled participant's notification-milestone state,
 * paired with `participants.scheduled_at`. `null` on both columns means "never
 * scheduled" (today's immediate path) — this enum has no case for that; the
 * column itself being null is what carries that meaning (see the model cast
 * in T-A3 and the DB CHECK constraint in T-A1).
 */

use App\Enums\ParticipantSchedulingStatus;

test('ParticipantSchedulingStatus has the four expected cases with their string values', function (): void {
    expect(ParticipantSchedulingStatus::Pending->value)->toBe('pending')
        ->and(ParticipantSchedulingStatus::NoticeSent->value)->toBe('notice_sent')
        ->and(ParticipantSchedulingStatus::Started->value)->toBe('started')
        ->and(ParticipantSchedulingStatus::Cancelled->value)->toBe('cancelled');
});

test('ParticipantSchedulingStatus has exactly four cases', function (): void {
    expect(ParticipantSchedulingStatus::cases())->toHaveCount(4);
});

test('ParticipantSchedulingStatus can be constructed from its wire value', function (): void {
    expect(ParticipantSchedulingStatus::from('pending'))->toBe(ParticipantSchedulingStatus::Pending)
        ->and(ParticipantSchedulingStatus::from('notice_sent'))->toBe(ParticipantSchedulingStatus::NoticeSent)
        ->and(ParticipantSchedulingStatus::from('started'))->toBe(ParticipantSchedulingStatus::Started)
        ->and(ParticipantSchedulingStatus::from('cancelled'))->toBe(ParticipantSchedulingStatus::Cancelled);
});
