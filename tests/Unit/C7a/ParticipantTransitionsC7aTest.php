<?php

declare(strict_types=1);

/**
 * Participant transition guard tests for C7a complete $allowedTransitions map.
 *
 * Asserts CRITICAL-1: the full 5-key transition map including:
 * - in_attesa → in_corso (C6 path, still valid)
 * - in_attesa → errore (C7a new edge: hard-fail on first competency /start)
 * - in_corso → in_valutazione (C6 path, still valid)
 * - in_corso → errore (C7a new edge: hard-fail on subsequent competency)
 * - in_valutazione → completato (C9 path, still valid)
 * - in_valutazione → errore (still valid — kept from C6 map)
 * - completato → [] (terminal: explicit key, no fallthrough)
 * - errore → [] (terminal: explicit key, no fallthrough)
 * - Illegal transitions raise ParticipantTransitionException (→ 422)
 *
 * Task 3.2: started_at stamped via direct property assignment (not mass-assign).
 *
 * Tasks: 3.1, 3.2 (RED)
 * REQ: Participant lifecycle guard — complete allowedTransitions map (CRITICAL-1)
 */

use App\Exceptions\ParticipantTransitionException;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeC7aTransitionProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function makeC7aParticipantWithStatus(Organization $org, Project $project, string $status): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'trans-' . uniqid(),
        'display_name'    => 'Transition Test',
        'status'          => $status,
    ]);
    $p->save();
    return $p->fresh();
}

function c7aTransitionParticipant(Participant $p, string $to): void
{
    $p->status = $to;
    $p->save();
}

// ─── ALLOWED transitions ──────────────────────────────────────────────────────

test('in_attesa → in_corso is allowed (C6 path)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    expect(fn () => c7aTransitionParticipant($p, 'in_corso'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('in_corso');
});

test('in_attesa → errore is allowed (C7a new edge: hard-fail on first competency)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    expect(fn () => c7aTransitionParticipant($p, 'errore'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('errore');
});

test('in_corso → in_valutazione is allowed (last question path)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_corso');

    expect(fn () => c7aTransitionParticipant($p, 'in_valutazione'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('in_valutazione');
});

test('in_corso → errore is allowed (C7a new edge: hard-fail on subsequent competency)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_corso');

    expect(fn () => c7aTransitionParticipant($p, 'errore'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('errore');
});

test('in_valutazione → completato is allowed (C9 completion path)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_valutazione');

    expect(fn () => c7aTransitionParticipant($p, 'completato'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('completato');
});

test('in_valutazione → errore is allowed (error on final evaluation)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_valutazione');

    expect(fn () => c7aTransitionParticipant($p, 'errore'))->not->toThrow(ParticipantTransitionException::class);
    expect($p->fresh()->status)->toBe('errore');
});

// ─── TERMINAL states: explicit keys (no ?? [] fallthrough) ────────────────────

test('completato is explicitly terminal — completato → errore is rejected', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'completato');

    expect(fn () => c7aTransitionParticipant($p, 'errore'))->toThrow(ParticipantTransitionException::class);
});

test('completato is explicitly terminal — completato → in_corso is rejected', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'completato');

    expect(fn () => c7aTransitionParticipant($p, 'in_corso'))->toThrow(ParticipantTransitionException::class);
});

test('errore is explicitly terminal — errore → in_corso is rejected', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'errore');

    expect(fn () => c7aTransitionParticipant($p, 'in_corso'))->toThrow(ParticipantTransitionException::class);
});

test('errore is explicitly terminal — errore → in_valutazione is rejected', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'errore');

    expect(fn () => c7aTransitionParticipant($p, 'in_valutazione'))->toThrow(ParticipantTransitionException::class);
});

// ─── ILLEGAL transitions ──────────────────────────────────────────────────────

test('in_valutazione → in_corso is rejected (illegal regression)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_valutazione');

    expect(fn () => c7aTransitionParticipant($p, 'in_corso'))->toThrow(ParticipantTransitionException::class);
});

test('in_attesa → completato is rejected (illegal skip)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    expect(fn () => c7aTransitionParticipant($p, 'completato'))->toThrow(ParticipantTransitionException::class);
});

test('in_attesa → in_valutazione is rejected (illegal skip)', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    expect(fn () => c7aTransitionParticipant($p, 'in_valutazione'))->toThrow(ParticipantTransitionException::class);
});

// ─── Task 3.2: started_at must NOT be mass-assignable ─────────────────────────

test('started_at is NOT in Participant.$fillable (direct property assignment required)', function (): void {
    expect((new Participant)->getFillable())->not->toContain('started_at');
});

test('started_at is NOT set by mass-assign update()', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    // Attempt to mass-assign started_at — must be silently ignored by $fillable guard.
    $p->update(['started_at' => now()->subHour(), 'status' => 'in_corso']);

    // started_at should still be null (mass-assign was guarded).
    expect($p->fresh()->started_at)->toBeNull();
});

test('started_at IS set by direct property assignment', function (): void {
    $org     = Organization::factory()->create();
    $project = makeC7aTransitionProject($org);
    $p       = makeC7aParticipantWithStatus($org, $project, 'in_attesa');

    // Direct property assignment — the correct pattern from the design.
    $now = now();
    $p->started_at = $now;
    $p->status = 'in_corso';
    $p->save();

    expect($p->fresh()->started_at)->not->toBeNull();
});
