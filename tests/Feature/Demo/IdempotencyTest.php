<?php

declare(strict_types=1);

/**
 * `beai:demo-seed` — the census gate (design D3; spec: "Seeding Is
 * Idempotent, Including Repair of a Prior Partial Run").
 *
 * The gate counts the FOUR marked roots (`projects`, `participants`,
 * `framework_versions`, `avatar_templates`) against the fixture's expected
 * counts:
 *   - all zero            → seed everything
 *   - exact match         → the (idempotent, per-row) writer runs and
 *                            writes nothing new — "already provisioned"
 *   - anything else       → REFUSE, print the diff, instruct teardown
 *
 * `Participant::booted()` only guards `updating`, so every participant is
 * written at its intended FINAL status in one INSERT — no transition is
 * ever attempted. A run interrupted between "participant committed" and
 * "its evaluation committed" leaves the shallow root census untouched
 * (Evaluation is not one of the four marked roots), so the gate lets a
 * second run through and DemoWriter's per-row idempotency completes the
 * missing evaluation — without ever touching the participant's `status`
 * column or creating a duplicate `candidate_ref`.
 */

use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\Demo\DemoMarker;
use App\Support\Tenancy\TenantContextScope;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
    $this->org = Organization::factory()->create(['slug' => 'acme']);
});

test('a second run on a fully-seeded organization writes nothing and exits 0', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $participantsBefore = Participant::where('organization_id', $this->org->id)->count();
    $evaluationsBefore = TenantContextScope::runFor($this->org->id, fn () => Evaluation::count());

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    expect(Participant::where('organization_id', $this->org->id)->count())->toBe($participantsBefore);
    expect(TenantContextScope::runFor($this->org->id, fn () => Evaluation::count()))->toBe($evaluationsBefore);
});

test('deleting one demo participant then re-running REFUSES, exits 1, writes nothing, and prints the census diff', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    $participant = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-006')
        ->firstOrFail();
    $participant->delete();

    $countAfterDelete = Participant::where('organization_id', $this->org->id)->count();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])
        ->expectsOutputToContain('artial')
        ->assertExitCode(1);

    // Refused — the deleted participant is NOT recreated, and nothing else changed.
    expect(Participant::where('organization_id', $this->org->id)->count())->toBe($countAfterDelete);
});

test('a participant whose evaluation never finished is completed by a second run, with no duplicate candidate_ref', function (): void {
    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    // Simulate a first run interrupted between "participant committed" and
    // "its evaluation committed" — the participant row (and its sessions)
    // survive; only the evaluation aggregate is missing. None of the four
    // marked roots change, so the shallow census still matches exactly.
    $c001 = Participant::where('organization_id', $this->org->id)
        ->where('candidate_ref', DemoMarker::PREFIX.'c-001')
        ->firstOrFail();

    TenantContextScope::runFor($this->org->id, function () use ($c001): void {
        Evaluation::where('participant_id', $c001->id)->delete();
    });

    expect(TenantContextScope::runFor($this->org->id, fn () => Evaluation::where('participant_id', $c001->id)->exists()))->toBeFalse();

    $this->artisan('beai:demo-seed', ['--org' => 'acme'])->assertExitCode(0);

    // Completed, not duplicated: exactly one participant row for this ref,
    // and its evaluation now exists again.
    expect(Participant::where('organization_id', $this->org->id)->where('candidate_ref', DemoMarker::PREFIX.'c-001')->count())->toBe(1);
    expect(TenantContextScope::runFor($this->org->id, fn () => Evaluation::where('participant_id', $c001->id)->exists()))->toBeTrue();
    expect($c001->fresh()->status)->toBe('completato');
});
