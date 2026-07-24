<?php

declare(strict_types=1);

/**
 * Deferred @ai integration tests — Avatar Behavioral Compliance (C8 Phase 7).
 *
 * These tests verify that the avatar LLM behaves according to the system prompt
 * injected at /start. They require a LIVE provider session and MUST NOT run on PR.
 *
 * Run on: workflow_dispatch / release/* only.
 * Skip condition: always (markTestSkipped) in the standard Pest run.
 *
 * DO NOT add @group feature or @group unit. These tests are isolated to the @ai group.
 *
 * REQ: Testability Split — Provider-Delegated scenarios (C8 Phase 7 — task 7.1)
 * Spec: REQ Adaptive Standard Follow-Up Questioning (SA-02) · REQ Nudge Enforcement (SA-03).
 *
 * @group ai
 */

// Behavioral compliance: ≤N follow-ups trigger end_phrase
test('avatar speaks end_phrase after at most N follow-up questions (budget exhaustion)', function (): void {
    $this->markTestSkipped(
        'Deferred @ai — requires live provider session (HeyGen/Tavus). '
        .'Run on workflow_dispatch/release/* only, never on PR. '
        .'Spec: SA-02 budget exhaustion → end_phrase (provider integration ONLY).'
    );
});

// Coverage before budget fires early
test('avatar speaks end_phrase early when all BARS indicators are covered before budget', function (): void {
    $this->markTestSkipped(
        'Deferred @ai — requires live provider session. '
        .'Run on workflow_dispatch/release/* only. '
        .'Spec: SA-02 coverage achieved before budget → end_phrase fires early (provider integration ONLY).'
    );
});

// Nudge does not consume a follow-up slot
test('avatar nudge re-prompt does not consume a follow-up budget slot', function (): void {
    $this->markTestSkipped(
        'Deferred @ai — requires live provider session. '
        .'Run on workflow_dispatch/release/* only. '
        .'Spec: SA-03 nudge does NOT consume a follow-up slot (OQ-3 provisional, provider integration ONLY).'
    );
});
