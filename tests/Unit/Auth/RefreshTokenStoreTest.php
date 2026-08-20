<?php

/**
 * RED — 2.1: RefreshTokenStore unit matrix (backoffice-session-refresh-hardening
 * D2/D6). No DB — all state lives behind the Cache facade (array store in
 * testing, Redis in production).
 */

use App\Support\Auth\RefreshRotateStatus;
use App\Support\Auth\RefreshTokenStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->store = new RefreshTokenStore;
});

// ─── issue() ─────────────────────────────────────────────────────────────────

test('issue() mints a family+secret pair and writes the family/tok records', function (): void {
    $issue = $this->store->issue(userId: 42);

    expect($issue->familyId)->not->toBeEmpty();
    expect($issue->secret)->not->toBeEmpty();
    expect($issue->cookieValue())->toBe("{$issue->familyId}.{$issue->secret}");

    $fam = Cache::get("refresh_family:{$issue->familyId}");
    expect($fam)->not->toBeNull();
    expect($fam['user_id'])->toBe(42);
    expect($fam['generation'])->toBe(0);

    $tok = Cache::get('refresh_tok:'.hash('sha256', $issue->secret));
    expect($tok)->not->toBeNull();
    expect($tok['family_id'])->toBe($issue->familyId);
    expect($tok['generation'])->toBe(0);
});

test('issue() stamps absolute_expires_at ~14 days out by default', function (): void {
    $issue = $this->store->issue(userId: 1);

    $expectedCeiling = now()->addMinutes((int) config('refresh_tokens.absolute_ttl_minutes'))->getTimestamp();

    expect($issue->absoluteExpiresAt)->toBe($expectedCeiling);
});

// ─── rotate() — first use ──────────────────────────────────────────────────────

test('first use consumes atomically: rotation succeeds, old tok gone, new generation live', function (): void {
    $issue = $this->store->issue(userId: 7);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Rotated);
    expect($result->userId)->toBe(7);
    expect($result->issue->familyId)->toBe($issue->familyId);
    expect($result->issue->secret)->not->toBe($issue->secret);

    // Old token is gone — a second presentation of the SAME secret must not
    // silently succeed again.
    expect(Cache::get('refresh_tok:'.hash('sha256', $issue->secret)))->toBeNull();

    // New generation is live.
    $newTok = Cache::get('refresh_tok:'.hash('sha256', $result->issue->secret));
    expect($newTok['generation'])->toBe(1);

    $fam = Cache::get("refresh_family:{$issue->familyId}");
    expect($fam['generation'])->toBe(1);
});

test('rotation preserves the SAME absolute_expires_at across generations (D2 ceiling never re-stamped)', function (): void {
    $issue = $this->store->issue(userId: 7);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->issue->absoluteExpiresAt)->toBe($issue->absoluteExpiresAt);
});

// ─── rotate() — replay outside the concurrency grace ───────────────────────────

test('replaying an already-rotated (superseded) secret outside the grace window revokes the whole family', function (): void {
    $issue = $this->store->issue(userId: 3);
    $this->store->rotate($issue->familyId, $issue->secret); // legitimate rotation, generation 0 -> 1

    $this->travel(11)->seconds(); // past the default 10s grace window

    $replay = $this->store->rotate($issue->familyId, $issue->secret);

    expect($replay->status)->toBe(RefreshRotateStatus::Reused);
    expect(Cache::get("refresh_family:{$issue->familyId}"))->toBeNull();
});

test('once a family is revoked by reuse, the NEW (rotated) secret is also rejected', function (): void {
    $issue = $this->store->issue(userId: 3);
    $rotated = $this->store->rotate($issue->familyId, $issue->secret);

    $this->travel(11)->seconds();
    $this->store->rotate($issue->familyId, $issue->secret); // triggers the reuse kill

    $afterKill = $this->store->rotate($rotated->issue->familyId, $rotated->issue->secret);

    expect($afterKill->status)->toBe(RefreshRotateStatus::Revoked);
});

// ─── rotate() — concurrency grace (D6) ─────────────────────────────────────────

test('two requests presenting the SAME secret within the grace window both succeed with no second rotation', function (): void {
    $issue = $this->store->issue(userId: 9);

    $first = $this->store->rotate($issue->familyId, $issue->secret);
    $this->travel(3)->seconds();
    $second = $this->store->rotate($issue->familyId, $issue->secret);

    expect($first->status)->toBe(RefreshRotateStatus::Rotated);
    expect($second->status)->toBe(RefreshRotateStatus::ConcurrentDuplicate);
    expect($second->userId)->toBe(9);
    expect($second->issue)->toBeNull(); // no Set-Cookie on the duplicate path

    // Family generation only advanced ONCE.
    $fam = Cache::get("refresh_family:{$issue->familyId}");
    expect($fam['generation'])->toBe(1);
});

// ─── rotate() — cross-family tamper ────────────────────────────────────────────

test('a secret presented with the WRONG family_id is rejected — the hash is the authority, never the attacker-controlled fid', function (): void {
    $issueA = $this->store->issue(userId: 1);
    $issueB = $this->store->issue(userId: 2);

    $tampered = $this->store->rotate($issueB->familyId, $issueA->secret);

    expect($tampered->status)->toBe(RefreshRotateStatus::Invalid);

    // Neither family was touched by the tamper attempt.
    expect(Cache::get("refresh_family:{$issueA->familyId}"))->not->toBeNull();
    expect(Cache::get("refresh_family:{$issueB->familyId}"))->not->toBeNull();
});

// ─── rotate() — unknown hash ────────────────────────────────────────────────────

test('an unknown/garbage secret revokes NOTHING — an unattributable hash must never kill a family', function (): void {
    $issue = $this->store->issue(userId: 5);

    $garbage = $this->store->rotate($issue->familyId, 'not-a-real-secret');

    expect($garbage->status)->toBe(RefreshRotateStatus::Invalid);
    expect(Cache::get("refresh_family:{$issue->familyId}"))->not->toBeNull();
});

// ─── rotate() — absolute ceiling ────────────────────────────────────────────────

test('past the absolute ceiling, rotation fails and the family is forgotten — even with a valid, un-replayed secret', function (): void {
    $issue = $this->store->issue(userId: 4);

    $this->travel((int) config('refresh_tokens.absolute_ttl_minutes') + 1)->minutes();

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Expired);
    expect(Cache::get("refresh_family:{$issue->familyId}"))->toBeNull();
});

test('ceiling never extends on rotation: the TTL shrinks as it approaches the ceiling, never resets to a fresh 14 days', function (): void {
    $issue = $this->store->issue(userId: 4);

    $this->travel(5)->days();

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    // absolute_expires_at is copied UNCHANGED — five days closer than at issue time.
    expect($result->issue->absoluteExpiresAt)->toBe($issue->absoluteExpiresAt);

    $secondsRemaining = $result->issue->absoluteExpiresAt - now()->getTimestamp();
    $fullCeilingSeconds = (int) config('refresh_tokens.absolute_ttl_minutes') * 60;

    // Materially less than a fresh full ceiling — proves the TTL was
    // computed as `absolute_expires_at - now`, never re-asserted as a
    // constant.
    expect($secondsRemaining)->toBeLessThan($fullCeilingSeconds);
    expect($secondsRemaining)->toBeGreaterThan(0);
});

// ─── revokeFamily() ──────────────────────────────────────────────────────────

test('revokeFamily() invalidates every outstanding token in the family immediately', function (): void {
    $issue = $this->store->issue(userId: 8);

    $this->store->revokeFamily($issue->familyId);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Revoked);
});
