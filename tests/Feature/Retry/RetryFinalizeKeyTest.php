<?php

declare(strict_types=1);

/**
 * FinalizeInterview dedup key is attempt-scoped (scoring-retry-rt-b, design D7, slice PR2a).
 *
 * The first finalization holds `finalize:{participant_id}` for two hours. A
 * re-interview finished inside that window must still emit the scoring trigger,
 * under `finalize:{participant_id}:retry`, exactly once. The flag is read from
 * the persisted evaluation row, org-filtered; the authorization action never
 * touches the cache.
 *
 * REQ: Retry - Single Re-Interview of a `pending` Evaluation (RT-B),
 *      scenario "A fast re-interview within two hours still scores"
 *      (openspec/changes/scoring-retry-rt-b/specs/scoring-engine/spec.md)
 */

use App\Events\ScoringRequested;
use App\Jobs\FinalizeInterview;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * An `in_valutazione` participant, optionally with an evaluation carrying the given retry flag.
 *
 * @return array{0: Organization, 1: Participant, 2: Evaluation|null}
 */
function retryFinalizeWorld(?bool $retryAttempt): array
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'rf-'.uniqid(),
        'display_name' => 'Retry Finalize Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $evaluation = $retryAttempt === null ? null : Evaluation::factory()->pending()->create([
        'participant_id' => $participant->id,
        'framework_version_id' => $project->framework_version_id,
        'retry_attempt' => $retryAttempt,
    ]);

    Cache::forget('finalize:'.$participant->id);
    Cache::forget('finalize:'.$participant->id.':retry');

    return [$org, $participant, $evaluation];
}

test('a re-interview finished while the first finalization key is held still emits the trigger once, under the retry key', function (): void {
    Event::fake([ScoringRequested::class]);
    [$org, $participant] = retryFinalizeWorld(true);
    // The first attempt finalized less than two hours ago.
    Cache::add('finalize:'.$participant->id, true, 7200);

    (new FinalizeInterview($participant->id, $org->id))->handle();

    Event::assertDispatchedTimes(ScoringRequested::class, 1);
    Event::assertDispatched(ScoringRequested::class, fn (ScoringRequested $e): bool => $e->participantId === $participant->id
        && $e->organizationId === $org->id);
    expect(Cache::has('finalize:'.$participant->id.':retry'))->toBeTrue();
});

test('a queue retry of the finalize job inside the retry run emits once, not twice', function (): void {
    Event::fake([ScoringRequested::class]);
    [$org, $participant] = retryFinalizeWorld(true);
    Cache::add('finalize:'.$participant->id, true, 7200);

    (new FinalizeInterview($participant->id, $org->id))->handle();
    (new FinalizeInterview($participant->id, $org->id))->handle();

    Event::assertDispatchedTimes(ScoringRequested::class, 1);
});

test('the first-attempt key is unchanged: no evaluation row uses finalize:{id} and dedups on it', function (): void {
    Event::fake([ScoringRequested::class]);
    [$org, $participant] = retryFinalizeWorld(null);

    (new FinalizeInterview($participant->id, $org->id))->handle();
    expect(Cache::has('finalize:'.$participant->id))->toBeTrue()
        ->and(Cache::has('finalize:'.$participant->id.':retry'))->toBeFalse();

    (new FinalizeInterview($participant->id, $org->id))->handle();

    Event::assertDispatchedTimes(ScoringRequested::class, 1);
});

test('an evaluation without a retry authorization keeps the first-attempt key and is deduplicated by it', function (): void {
    Event::fake([ScoringRequested::class]);
    [$org, $participant] = retryFinalizeWorld(false);
    Cache::add('finalize:'.$participant->id, true, 7200);

    (new FinalizeInterview($participant->id, $org->id))->handle();

    Event::assertNotDispatched(ScoringRequested::class);
    expect(Cache::has('finalize:'.$participant->id.':retry'))->toBeFalse();
});

test('a retry flag on an evaluation of another organization does not switch the key', function (): void {
    Event::fake([ScoringRequested::class]);
    [$org, $participant, $evaluation] = retryFinalizeWorld(true);
    $otherOrg = Organization::factory()->create();
    DB::table('evaluations')->where('id', $evaluation->id)->update(['organization_id' => $otherOrg->id]);
    Cache::add('finalize:'.$participant->id, true, 7200);

    (new FinalizeInterview($participant->id, $org->id))->handle();

    // The foreign-org row is invisible to the org-filtered read: first-attempt key, held, so a no-op.
    Event::assertNotDispatched(ScoringRequested::class);
    expect(Cache::has('finalize:'.$participant->id.':retry'))->toBeFalse();
});
