<?php

declare(strict_types=1);

/**
 * A candidate is invited by email, and the email is who they are.
 *
 * CLAUDE.md ruling 8, reversed 2026-09-01. The product held no candidate
 * contact data at all, on the assumption that every candidate arrives through
 * an SSO ingress the calling system owns. Operators also create candidates
 * directly in the backoffice, and for those there was no calling system to send
 * anything: the operator pressed "invite", got a link, and the candidate was
 * never told.
 *
 * The address is also the IDENTITY. `participants` stays the per-project
 * enrolment, so one person invited to two projects — or by two organizations —
 * is two rows carrying one address, and that is the requirement rather than a
 * duplicate.
 */

use App\Jobs\SendCandidateInvitationJob;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Notifications\CandidateInvitationNotification;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{token: string, project: Project, org: Organization}
 */
function invitableProject(): array
{
    // The composer refuses to build a link without this and fails loud rather
    // than returning a 201 carrying a malformed URL — correct behaviour, and
    // it means every test that mints one has to configure it.
    config(['interview.candidate_app_url' => 'https://interview.example.test']);

    $org = Organization::factory()->create(['name' => 'Acme Assessments']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        'name' => 'Sales Team 2026',
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'it',
        'goes_live_at' => null,
        'deadline_at' => null,
    ]);

    return ['token' => auth('api')->login($user), 'project' => $project, 'org' => $org];
}

test('inviting a candidate sends them the link by default', function (): void {
    // Default TRUE on purpose. The operator pressed "invite a candidate";
    // producing a link and silently not sending it is the behaviour that made
    // this feature necessary.
    Queue::fake();
    ['token' => $token, 'project' => $project] = invitableProject();

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'cand-invite-1',
        'display_name' => 'Giulia Ferrari',
        'email' => 'giulia@example.test',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('email_sent', true);

    Queue::assertPushed(SendCandidateInvitationJob::class);
});

test('an operator can opt out and deliver the link themselves', function (): void {
    Queue::fake();
    ['token' => $token, 'project' => $project] = invitableProject();

    $response = $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'cand-invite-2',
        'display_name' => 'Giulia Ferrari',
        'email' => 'giulia2@example.test',
        'send_email' => false,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('email_sent', false);
    // The link is still returned — opting out of the email is not opting out
    // of the invitation.
    expect($response->json('entry_url'))->toBeString()->not->toBeEmpty();

    Queue::assertNotPushed(SendCandidateInvitationJob::class);
});

test('the email is REQUIRED — there is no anonymous candidate any more', function (): void {
    ['token' => $token, 'project' => $project] = invitableProject();

    $this->withToken($token)->postJson('/api/entry-links', [
        'project_id' => $project->id,
        'candidate_ref' => 'cand-invite-3',
        'display_name' => 'Giulia Ferrari',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('the invitation is written in the PROJECT\'s language, not the operator\'s', function (): void {
    // The interview is conducted in the project's language. An invitation in a
    // different one is a promise the product breaks in the first thirty
    // seconds.
    Notification::fake();
    app()->setLocale('en');

    (new SendCandidateInvitationJob(
        'giulia@example.test',
        'https://candidate.test/interview/token-123',
        'Giulia Ferrari',
        'Acme Assessments',
        'Sales Team 2026',
        '1 ottobre 2026 10:00',
        'it',
    ))->handle();

    Notification::assertSentOnDemand(
        CandidateInvitationNotification::class,
        function ($notification, $channels, $notifiable): bool {
            expect($notification->locale)->toBe('it')
                ->and($notifiable->routes['mail'])->toBe('giulia@example.test');

            return true;
        }
    );
});

test('it tells the candidate what will happen BEFORE handing them the button', function (): void {
    // A candidate is being asked to talk to a camera and be scored on it,
    // usually for a job they want. The browser requirement in particular has
    // to arrive before the link: the product refuses mobile and Firefox
    // outright (SA-11), so a candidate who reads it afterwards reads it having
    // already been turned away.
    Notification::fake();

    (new SendCandidateInvitationJob(
        'giulia@example.test',
        'https://candidate.test/interview/token-123',
        'Giulia Ferrari',
        'Acme Assessments',
        'Sales Team 2026',
        '1 October 2026 10:00',
        'en',
    ))->handle();

    Notification::assertSentOnDemand(
        CandidateInvitationNotification::class,
        function ($notification, $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);
            $intro = implode(' ', $mail->introLines);

            expect($intro)->toContain('Acme Assessments')
                ->and($intro)->toContain('Sales Team 2026')
                ->and($intro)->toContain('Chrome')
                ->and($mail->actionUrl)->toBe('https://candidate.test/interview/token-123')
                // The URL again as plain text: a link that exists only as an
                // anchor is unusable in a client that mangles anchors.
                ->and(implode(' ', $mail->outroLines))
                ->toContain('https://candidate.test/interview/token-123');

            return true;
        }
    );
});

test('it REFUSES to mail a backfilled placeholder address', function (): void {
    // Rows predating the mandatory column carry a synthesised
    // `@invalid.beai.local` address. `.local` is reserved by RFC 6762 and
    // resolves nowhere, so sending produces a guaranteed bounce and a
    // candidate who is never told anything. Refusing loudly is what puts the
    // operator in a position to fix the row.
    Notification::fake();

    (new SendCandidateInvitationJob(
        'beai-demo-c-001@invalid.beai.local',
        'https://candidate.test/interview/token-123',
        'Giulia Ferrari',
        'Acme',
        'Sales',
        '1 October 2026',
        'en',
    ))->handle();

    Notification::assertNothingSent();
});

// ─── Identity ─────────────────────────────────────────────────────────────────

/*
 * "The same person, two organizations" is NOT asserted here, and the reason is
 * worth writing down rather than leaving as a gap.
 *
 * `POST /api/entry-links` mints a token; it does not write the enrolment. The
 * participant row appears when the CANDIDATE exchanges that link, so a test
 * living here could only assert that two mints succeed — which proves nothing
 * about identity. The behaviour is covered where the row is actually created:
 * `tests/Feature/C6/CrossTenantIsolationTest.php` for the isolation half, and
 * the unique index below for the "same project" half.
 */

test('inviting the same person twice to ONE project is refused at the database', function (): void {
    // A mistake worth refusing. Inviting them to a second project, or to
    // another organization, is the requirement — which is why the unique index
    // is `(project_id, email)` and not `email` alone.
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
    ]);

    Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'email' => 'giulia@example.test',
    ]);

    expect(fn () => Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'email' => 'giulia@example.test',
    ]))->toThrow(QueryException::class);
});
