<?php

declare(strict_types=1);

/**
 * RED — Task 1.1: config/conversation.php (C8 Phase 1).
 *
 * Asserts:
 * (a) config('conversation.prompt_version') returns a non-empty string.
 * (b) config('conversation.followup_budget') returns an int.
 *
 * REQ: config/conversation.php (C8 RV-4)
 */
test('(a) conversation.prompt_version is a non-empty string', function (): void {
    $version = config('conversation.prompt_version');

    expect($version)->toBeString()->not->toBeEmpty();
});

test('(b) conversation.followup_budget is an integer', function (): void {
    $budget = config('conversation.followup_budget');

    expect($budget)->toBeInt();
});
