<?php

declare(strict_types=1);

/**
 * At most one OPEN live period per non-null provider session ref
 * (tavus-single-session-interview, API-01, design N2/D9).
 *
 * Once several competency rows share one provider conversation, "one open
 * period per ref" is the database-level guard that two rows cannot both claim
 * the same live conversation. Closed periods and null refs never collide.
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function refSession(Organization $org): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);

    return InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
    ]);
}

function refPeriod(InterviewSession $session, ?string $ref, bool $closed = false): InterviewSessionLivePeriod
{
    return InterviewSessionLivePeriod::create([
        'interview_session_id' => $session->id,
        'provider_session_ref' => $ref,
        'started_at' => now(),
        'ended_at' => $closed ? now() : null,
        'closed_reason' => $closed ? 'end' : null,
    ]);
}

test('a second open period on one non-null ref is a unique violation', function (): void {
    $org = Organization::factory()->create();
    refPeriod(refSession($org), 'conv-shared');

    // Inside a savepoint: Postgres aborts the whole transaction on an error, and RefreshDatabase keeps one open.
    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => refPeriod(refSession($org), 'conv-shared')),
        '23505',
        'interview_session_live_periods_one_open_per_ref',
    );
});

test('a closed period on the same ref does not collide', function (): void {
    $org = Organization::factory()->create();
    refPeriod(refSession($org), 'conv-shared', closed: true);

    refPeriod(refSession($org), 'conv-shared');
    refPeriod(refSession($org), 'conv-shared', closed: true);

    expect(InterviewSessionLivePeriod::where('provider_session_ref', 'conv-shared')->count())->toBe(3);
});

test('null refs never collide', function (): void {
    $org = Organization::factory()->create();
    refPeriod(refSession($org), null);
    refPeriod(refSession($org), null);

    expect(InterviewSessionLivePeriod::whereNull('provider_session_ref')->count())->toBe(2);
});

test('distinct open refs do not collide', function (): void {
    $org = Organization::factory()->create();
    refPeriod(refSession($org), 'conv-a');
    refPeriod(refSession($org), 'conv-b');

    expect(InterviewSessionLivePeriod::count())->toBe(2);
});
