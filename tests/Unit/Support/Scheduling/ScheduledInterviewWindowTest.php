<?php

declare(strict_types=1);

/**
 * RED — PR-A/T-A4: ScheduledInterviewWindow's two named lead-time constants
 * (interview-scheduling, design AD-3). A single source of truth a validation
 * rule derives from in later PRs — never re-typed as a magic number. This test
 * pins the two literal values so a future accidental edit does not silently
 * change the lead-time contract.
 */

use App\Support\Scheduling\ScheduledInterviewWindow;

test('NOTICE_LEAD_MINUTES is 15', function (): void {
    expect(ScheduledInterviewWindow::NOTICE_LEAD_MINUTES)->toBe(15);
});

test('MINIMUM_SCHEDULING_LEAD_MINUTES is 16, one minute over the notice window', function (): void {
    expect(ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES)->toBe(16);
});
