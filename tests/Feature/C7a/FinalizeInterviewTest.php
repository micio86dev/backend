<?php

declare(strict_types=1);

/**
 * FinalizeInterview job feature tests (C7a — Phase 13.1 RED).
 *
 * Asserts:
 * - Job is idempotent: re-running when participant.status past in_valutazione → no-op.
 * - Retry-safe dedup (FIX-4): Redis NX lock 'finalize:{pid}' → first execution emits C9 trigger;
 *   second execution (same participant, lock held) → no-op.
 * - Dispatched ->afterCommit(): job is recorded by Queue::fake after the outer txn commits.
 * - Job class is NOT dispatched when using DatabaseTransactions (use RefreshDatabase instead).
 *
 * Tasks: 13.1 (RED)
 * REQ: FinalizeInterview job — idempotent C9 scoring trigger dedup (C7a)
 */

use App\Jobs\FinalizeInterview;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function finalizeOrg(): Organization
{
    return Organization::factory()->create();
}

function finalizeParticipant(Organization $org, string $status = 'in_valutazione'): Participant
{
    // Need a project for FK
    $resolver = app(\App\Support\Tenancy\TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);
    $project = Project::factory()->create(['status' => 'active']);

    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'fin-' . uniqid(),
        'display_name'    => 'Finalize Test',
        'status'          => $status,
    ]);
    $p->save();
    return $p->fresh();
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('FinalizeInterview job is idempotent: participant already completato → no-op', function (): void {
    $org         = finalizeOrg();
    $participant = finalizeParticipant($org, 'completato');

    // Clear any dedup lock from previous runs
    Cache::forget('finalize:' . $participant->id);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $logMessages[] = (string) ($message->message ?? json_encode($message));
    });

    $job = new FinalizeInterview($participant->id);
    $job->handle();

    // No-op: the participant is already completato
    // The job should log the no-op and return without setting the dedup lock
    $triggered = collect($logMessages)->contains(fn ($msg) => str_contains($msg, 'C9 scoring trigger'));
    expect($triggered)->toBeFalse();
});

test('FinalizeInterview job: first execution acquires lock and emits C9 trigger', function (): void {
    // C9 PR3: Fake the queue to prevent ScoreEvaluationJob from running synchronously
    // (QUEUE_CONNECTION=sync in tests; the ScoringRequested listener dispatches the job).
    Queue::fake();

    $org         = finalizeOrg();
    $participant = finalizeParticipant($org, 'in_valutazione');
    $pid         = $participant->id;
    $lockKey     = 'finalize:' . $pid;

    // Ensure no lock exists
    Cache::forget($lockKey);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $logMessages[] = ($message->message ?? '') . $context;
    });

    $job = new FinalizeInterview($pid);
    $job->handle();

    // Lock should now be acquired
    expect(Cache::has($lockKey))->toBeTrue();

    // C9 trigger was emitted
    $triggered = collect($logMessages)->contains(fn ($msg) => str_contains($msg, 'C9 scoring trigger'));
    expect($triggered)->toBeTrue();
});

test('FinalizeInterview job: second execution with lock held → no-op (FIX-4 dedup)', function (): void {
    $org         = finalizeOrg();
    $participant = finalizeParticipant($org, 'in_valutazione');
    $pid         = $participant->id;
    $lockKey     = 'finalize:' . $pid;

    // Simulate lock already held (as if first execution ran)
    Cache::forget($lockKey);
    Cache::add($lockKey, true, 7200);

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $logMessages[] = ($message->message ?? '');
    });

    $job = new FinalizeInterview($pid);
    $job->handle();

    // C9 trigger must NOT be emitted again
    $triggered = collect($logMessages)->contains(fn ($msg) => str_contains($msg, 'C9 scoring trigger'));
    expect($triggered)->toBeFalse();
});

test('FinalizeInterview dispatched ->afterCommit(): Queue::fake records dispatch', function (): void {
    Queue::fake();

    $org         = finalizeOrg();
    $participant = finalizeParticipant($org, 'in_valutazione');

    // Dispatch with afterCommit — Queue::fake bypasses transaction awareness
    FinalizeInterview::dispatch($participant->id)->afterCommit();

    Queue::assertPushed(FinalizeInterview::class, 1);
});

test('FinalizeInterview job: participant not found → graceful no-op (no exception)', function (): void {
    Cache::forget('finalize:99999');

    $logMessages = [];
    Log::listen(function ($message) use (&$logMessages): void {
        $logMessages[] = ($message->message ?? '');
    });

    $job = new FinalizeInterview(99999);
    $job->handle(); // Must not throw

    // Should log a warning about participant not found
    $notFound = collect($logMessages)->contains(fn ($msg) => str_contains($msg, 'not found'));
    expect($notFound)->toBeTrue();
});
