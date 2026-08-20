<?php

/**
 * RED — 2.1 (corrected): RefreshTokenStore unit matrix
 * (backoffice-session-refresh-hardening D2/D6, storage corrected from Redis
 * to PostgreSQL — see design.md). Refresh tokens are durable authentication
 * state, not cache: `refresh_tokens` rows are the source of truth, hashed at
 * rest, one row per generation, with a real `user_id` foreign key — hence
 * every test mints a real App\Models\User via factory rather than an
 * arbitrary integer.
 */

use App\Models\RefreshToken;
use App\Models\User;
use App\Support\Auth\RefreshRotateStatus;
use App\Support\Auth\RefreshTokenStore;

beforeEach(function (): void {
    $this->store = new RefreshTokenStore;
});

function refreshTokenRowFor(string $secret): ?RefreshToken
{
    return RefreshToken::where('token_hash', hash('sha256', $secret))->first();
}

// ─── issue() ─────────────────────────────────────────────────────────────────

test('issue() mints a family+secret pair and writes a generation-0 row', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    expect($issue->familyId)->not->toBeEmpty();
    expect($issue->secret)->not->toBeEmpty();
    expect($issue->cookieValue())->toBe("{$issue->familyId}.{$issue->secret}");

    $row = refreshTokenRowFor($issue->secret);
    expect($row)->not->toBeNull();
    expect($row->family_id)->toBe($issue->familyId);
    expect($row->user_id)->toBe($user->id);
    expect($row->generation)->toBe(0);
    expect($row->consumed_at)->toBeNull();
    expect($row->revoked_at)->toBeNull();
});

test('issue() stamps absolute_expires_at ~14 days out by default', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $expectedCeiling = now()->addMinutes((int) config('refresh_tokens.absolute_ttl_minutes'))->getTimestamp();

    expect($issue->absoluteExpiresAt)->toBe($expectedCeiling);
});

// ─── rotate() — first use ──────────────────────────────────────────────────────

test('first use consumes atomically: rotation succeeds, old row consumed, new generation live', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Rotated);
    expect($result->userId)->toBe($user->id);
    expect($result->issue->familyId)->toBe($issue->familyId);
    expect($result->issue->secret)->not->toBe($issue->secret);

    // Old row is consumed — a second presentation of the SAME secret must
    // not silently succeed again.
    $oldRow = refreshTokenRowFor($issue->secret);
    expect($oldRow->consumed_at)->not->toBeNull();
    expect($oldRow->revoked_at)->toBeNull();

    // New generation is live.
    $newRow = refreshTokenRowFor($result->issue->secret);
    expect($newRow->generation)->toBe(1);
    expect($newRow->consumed_at)->toBeNull();
    expect($newRow->revoked_at)->toBeNull();
});

test('rotation preserves the SAME absolute_expires_at across generations (D2 ceiling never re-stamped)', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->issue->absoluteExpiresAt)->toBe($issue->absoluteExpiresAt);
});

// ─── rotate() — replay outside the concurrency grace ───────────────────────────

test('replaying an already-rotated (superseded) secret outside the grace window revokes the whole family', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);
    $rotated = $this->store->rotate($issue->familyId, $issue->secret); // legitimate rotation, generation 0 -> 1

    $this->travel(11)->seconds(); // past the default 10s grace window

    $replay = $this->store->rotate($issue->familyId, $issue->secret);

    expect($replay->status)->toBe(RefreshRotateStatus::Reused);

    // The still-live generation (1) was killed by the reuse detection too.
    $liveRow = refreshTokenRowFor($rotated->issue->secret);
    expect($liveRow->revoked_at)->not->toBeNull();
});

test('once a family is revoked by reuse, the NEW (rotated) secret is also rejected', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);
    $rotated = $this->store->rotate($issue->familyId, $issue->secret);

    $this->travel(11)->seconds();
    $this->store->rotate($issue->familyId, $issue->secret); // triggers the reuse kill

    $afterKill = $this->store->rotate($rotated->issue->familyId, $rotated->issue->secret);

    expect($afterKill->status)->toBe(RefreshRotateStatus::Revoked);
});

// ─── rotate() — concurrency grace (D6) ─────────────────────────────────────────

test('two requests presenting the SAME secret within the grace window both succeed with no second rotation', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $first = $this->store->rotate($issue->familyId, $issue->secret);
    $this->travel(3)->seconds();
    $second = $this->store->rotate($issue->familyId, $issue->secret);

    expect($first->status)->toBe(RefreshRotateStatus::Rotated);
    expect($second->status)->toBe(RefreshRotateStatus::ConcurrentDuplicate);
    expect($second->userId)->toBe($user->id);
    expect($second->issue)->toBeNull(); // no Set-Cookie on the duplicate path

    // Only ONE generation-1 row exists — no second rotation happened.
    expect(RefreshToken::where('family_id', $issue->familyId)->where('generation', 1)->count())->toBe(1);
    expect(RefreshToken::where('family_id', $issue->familyId)->count())->toBe(2);
});

// ─── rotate() — cross-family tamper ────────────────────────────────────────────

test('a secret presented with the WRONG family_id is rejected — the hash is the authority, never the attacker-controlled fid', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $issueA = $this->store->issue(userId: $userA->id);
    $issueB = $this->store->issue(userId: $userB->id);

    $tampered = $this->store->rotate($issueB->familyId, $issueA->secret);

    expect($tampered->status)->toBe(RefreshRotateStatus::Invalid);

    // Neither family was touched by the tamper attempt.
    expect(refreshTokenRowFor($issueA->secret)->revoked_at)->toBeNull();
    expect(refreshTokenRowFor($issueB->secret)->revoked_at)->toBeNull();
});

// ─── rotate() — unknown hash ────────────────────────────────────────────────────

test('an unknown/garbage secret revokes NOTHING — an unattributable hash must never kill a family', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $garbage = $this->store->rotate($issue->familyId, 'not-a-real-secret');

    expect($garbage->status)->toBe(RefreshRotateStatus::Invalid);
    expect(refreshTokenRowFor($issue->secret)->revoked_at)->toBeNull();
});

// ─── rotate() — absolute ceiling ────────────────────────────────────────────────

test('past the absolute ceiling, rotation fails and the family is dead — even with a valid, un-replayed secret', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $this->travel((int) config('refresh_tokens.absolute_ttl_minutes') + 1)->minutes();

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Expired);
    expect(refreshTokenRowFor($issue->secret)->revoked_at)->not->toBeNull();

    // A subsequent presentation of the same, now-dead secret is Revoked, not
    // re-evaluated as Expired again.
    $again = $this->store->rotate($issue->familyId, $issue->secret);
    expect($again->status)->toBe(RefreshRotateStatus::Revoked);
});

test('ceiling never extends on rotation: the TTL shrinks as it approaches the ceiling, never resets to a fresh 14 days', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

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

// ─── Cookie-name / encryption-exclusion drift guard ────────────────────────────

test('the cookie name matches the literal excluded from encryption in bootstrap/app.php', function (): void {
    // bootstrap/app.php's encryptCookies(except: [...]) call cannot read
    // config('refresh_tokens.cookie.name') — that closure runs before the
    // 'config' container binding exists — so it hardcodes the literal
    // 'beai_refresh' instead. This test is the drift guard: if the config
    // value ever changes, this fails loudly rather than silently leaving
    // the refresh cookie double-encrypted.
    expect(config('refresh_tokens.cookie.name'))->toBe('beai_refresh');
});

// ─── revokeFamily() ──────────────────────────────────────────────────────────

test('revokeFamily() invalidates every outstanding token in the family immediately', function (): void {
    $user = User::factory()->create();
    $issue = $this->store->issue(userId: $user->id);

    $this->store->revokeFamily($issue->familyId);

    $result = $this->store->rotate($issue->familyId, $issue->secret);

    expect($result->status)->toBe(RefreshRotateStatus::Revoked);
});
