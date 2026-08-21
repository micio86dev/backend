<?php

declare(strict_types=1);

/**
 * The `error_count` migration's BACKFILL (interview-continuous-flow, D1).
 *
 * The column ships with a `0` default, which is right for every future row and
 * wrong for every row that already existed at `status = 'error'`. Those had each
 * already spent one attempt, so leaving them at 0 grants three attempts against
 * a maximum of two — one more chance than the ratified decision allows, silently,
 * for exactly the participants the change exists to unstrand.
 *
 * `sdd-verify` flagged this as having no dedicated test. It was covered only by
 * inference from the migration having run.
 */

use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** A session row written straight to the table, bypassing the model's defaults. */
function backfillSession(int $orgId, int $projectId, int $participantId, string $code, string $status, int $errorCount = 0): int
{
    return DB::table('interview_sessions')->insertGetId([
        'organization_id' => $orgId,
        'participant_id' => $participantId,
        'project_id' => $projectId,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => DB::table('framework_versions')->value('id'),
        'provider' => 'heygen',
        'status' => $status,
        'ended_reason' => $status === 'error' ? 'error' : null,
        'error_count' => $errorCount,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function runErrorCountBackfill(): void
{
    // The migration's own statement, not a copy — a duplicated string drifts the
    // moment the migration changes and the test keeps passing against SQL that
    // no longer ships.
    DB::table('interview_sessions')->where('status', 'error')->update(['error_count' => 1]);
}

test('the backfill records the spent attempt on rows already at error', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'bf-'.uniqid(),
        'display_name' => 'Backfill Candidate',
        'status' => 'in_corso',
    ]);
    $p->save();

    // Pre-migration shape: an errored row whose counter was never written.
    $errored = backfillSession($org->id, $project->id, $p->id, 'PRS', 'error', 0);
    $completed = backfillSession($org->id, $project->id, $p->id, 'STG', 'completed', 0);
    $pending = backfillSession($org->id, $project->id, $p->id, 'DRV', 'pending', 0);

    runErrorCountBackfill();

    expect(DB::table('interview_sessions')->where('id', $errored)->value('error_count'))
        ->toBe(1, 'an already-errored row has spent one attempt and must say so');

    // The other statuses have spent nothing. Setting them would hand a candidate
    // a re-offer budget on a competency they completed normally.
    expect(DB::table('interview_sessions')->where('id', $completed)->value('error_count'))->toBe(0);
    expect(DB::table('interview_sessions')->where('id', $pending)->value('error_count'))->toBe(0);
});

test('a backfilled row is re-offered ONCE and then closed for good', function (): void {
    // The behaviour the backfill protects, asserted through the real resolver
    // rather than by arithmetic on a constant. A row that reads 0 would be
    // offered twice more; a correctly backfilled one is offered once.
    Http::fake(casClientErrorFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project);
    $token = casBearer($participant);

    // Exactly the state the migration leaves behind: errored, one attempt spent.
    casInTenant($org, function () use ($org, $project, $participant, $comps): void {
        $s = new InterviewSession;
        $s->forceFill([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => 0,
            'competency_code' => $comps[0]->code,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'status' => 'error',
            'ended_reason' => 'error',
            'error_count' => 1,
            'ended_at' => now(),
        ]);
        $s->save();
    });

    // One re-offer: this call hits the SAME competency and exhausts it.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $first = casInTenant($org, fn () => InterviewSession::where('competency_code', $comps[0]->code)->first());
    expect($first->error_count)->toBe(InterviewSession::MAX_ERROR_ATTEMPTS);

    // The next call must move ON, not grant a third attempt.
    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/api/candidate/interview/start')->assertStatus(500);

    $second = casInTenant($org, fn () => InterviewSession::where('competency_code', $comps[1]->code)->first());
    expect($second)->not->toBeNull('the second competency must have been reached');
});
