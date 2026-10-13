<?php

declare(strict_types=1);

/**
 * `SettleParticipantCompletion::settleAbandoned()` writes a participant's status.
 * It derives the organization from the project and uses it as a predicate, so a
 * project that no longer resolves must make it do nothing at all: no status
 * change, no scoring job, and a log line rather than a silent stranding.
 */

use App\Actions\Interview\SettleParticipantCompletion;
use App\Jobs\FinalizeInterview;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('an abandoned participant whose project no longer resolves is left untouched and the problem is logged', function (): void {
    Log::spy();
    Queue::fake();
    $org = Organization::factory()->create();
    [$project, $participant] = TenantContextScope::runFor($org->id, function () use ($org): array {
        $project = Project::factory()->create(['organization_id' => $org->id]);
        $participant = Participant::factory()->forProject($project)->create(['status' => 'in_corso']);

        return [$project, $participant];
    });
    TenantContextScope::runFor($org->id, fn () => $project->delete());

    $settled = app(SettleParticipantCompletion::class)->settleAbandoned($participant->id, $project->id);

    expect($settled)->toBeNull()
        ->and($participant->fresh()->status)->toBe('in_corso');
    Queue::assertNotPushed(FinalizeInterview::class);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, 'project did not resolve'));
});
