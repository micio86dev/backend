<?php

declare(strict_types=1);

/**
 * SessionLiveClock — the only writer of `interview_session_live_periods` rows
 * (interview-session-started-at, D1/D4/D5).
 *
 * REQ: interview-session "Recorded duration is accumulated live time, not
 *      wall-clock span"; "InterviewSession tenant model — LOCKED status enum"
 */

use App\Models\AvatarTemplate;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\Interview\SessionLiveClock;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function clockOrg(): Organization
{
    return Organization::factory()->create();
}

function clockSession(Organization $org): InterviewSession
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

test('a period belongsTo its InterviewSession', function (): void {
    $org = clockOrg();
    $session = clockSession($org);

    (new SessionLiveClock)->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    expect($period->interviewSession)->toBeInstanceOf(InterviewSession::class);
    expect($period->interviewSession->id)->toBe($session->id);
});

test('open() creates an open period with the given provider ref', function (): void {
    $org = clockOrg();
    $session = clockSession($org);

    (new SessionLiveClock)->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    expect($period->provider_session_ref)->toBe('ref-1');
    expect($period->ended_at)->toBeNull();
    expect($period->closed_reason)->toBeNull();
});

test('close() sets ended_at and closed_reason on the open period', function (): void {
    $org = clockOrg();
    $session = clockSession($org);
    $clock = new SessionLiveClock;

    $clock->open($session, 'ref-1');
    $clock->close($session, 'end');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    expect($period->ended_at)->not->toBeNull();
    expect($period->closed_reason)->toBe('end');
});

test('close() is a no-op when nothing is open — pending -> error costs nothing', function (): void {
    $org = clockOrg();
    $session = clockSession($org);

    // No open() call — this session never went live.
    (new SessionLiveClock)->close($session, 'error');

    expect(InterviewSessionLivePeriod::where('interview_session_id', $session->id)->count())->toBe(0);
});

// ── D5 invariant: enforced by the PostgreSQL partial unique index ─────────

test('a second open period for the same session is rejected by the partial unique index, not merely avoided by the clock', function (): void {
    $org = clockOrg();
    $session = clockSession($org);

    // Bypasses SessionLiveClock entirely — a raw double-open, proving the
    // DATABASE rejects it rather than trusting discipline in one class.
    DB::table('interview_session_live_periods')->insert([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'provider_session_ref' => 'ref-a',
        'started_at' => now(),
        'ended_at' => null,
        'closed_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('interview_session_live_periods')->insert([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'provider_session_ref' => 'ref-b',
        'started_at' => now(),
        'ended_at' => null,
        'closed_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a second open period is accepted once the first is closed — the invariant is "at most one open", not "at most one ever"', function (): void {
    $org = clockOrg();
    $session = clockSession($org);
    $clock = new SessionLiveClock;

    $clock->open($session, 'ref-a');
    $clock->close($session, 'resume');
    $clock->open($session, 'ref-b');

    expect(InterviewSessionLivePeriod::where('interview_session_id', $session->id)->count())->toBe(2);
    expect(InterviewSessionLivePeriod::where('interview_session_id', $session->id)->whereNull('ended_at')->count())->toBe(1);
});

// ── D4a: cap resolution ─────────────────────────────────────────────────

test('close() caps at the platform HeyGen ceiling when no active avatar template exists', function (): void {
    $org = clockOrg();
    $session = clockSession($org);
    $clock = new SessionLiveClock;

    $clock->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    // Backdate the opened period far past the ceiling — simulating a
    // discovery that arrives long after the abandonment.
    $period->started_at = now()->subHours(5);
    $period->save();

    $clock->close($session, 'resume');

    $period->refresh();
    $seconds = $period->ended_at->getTimestamp() - $period->started_at->getTimestamp();
    expect($seconds)->toBe(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
});

test('close() caps at the platform Tavus ceiling for a tavus session, distinct from HeyGen\'s', function (): void {
    $org = clockOrg();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['organization_id' => $org->id, 'framework_version_id' => $fv->id]);
    $participant = Participant::factory()->create(['organization_id' => $org->id, 'project_id' => $project->id]);
    $session = InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'provider' => 'tavus',
    ]);

    $clock = new SessionLiveClock;
    $clock->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    $period->started_at = now()->subHours(5);
    $period->save();

    $clock->close($session, 'resume');

    $period->refresh();
    $seconds = $period->ended_at->getTimestamp() - $period->started_at->getTimestamp();
    expect($seconds)->toBe(ProviderFieldSpecs::TAVUS_MAX_SECONDS)
        ->not->toBe(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
});

test('the template a project PINS, configuring a LOWER ceiling, wins over the platform default', function (): void {
    $org = clockOrg();
    $session = clockSession($org); // provider = heygen (factory default)

    $template = new AvatarTemplate;
    $template->forceFill([
        'organization_id' => $org->id,
        'name' => 'org-heygen-template',
        'provider' => 'heygen',
        'config' => ['avatarId' => 'a', 'voiceId' => 'v', 'maxSessionDurationSec' => 300],
        'is_active' => true,
    ])->save();

    // The session's PROJECT must pin this template, and that is the behaviour
    // change rather than test bookkeeping. `projects.avatar_template_id` is now
    // required, so `ActiveTemplateResolver` returns what the project pinned —
    // and the factory pins a bare template of its own. Merely making this one
    // `is_active` no longer reaches the clock, because the organization-wide
    // active template is only the answer for callers that name no project.
    //
    // Which is the point of the column: the ceiling a session runs under is now
    // the one ITS project chose, not whichever template the organization
    // happened to have activated.
    $session->project->forceFill(['avatar_template_id' => $template->id])->save();

    $clock = new SessionLiveClock;
    $clock->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    $period->started_at = now()->subHours(5);
    $period->save();

    $clock->close($session, 'resume');

    $period->refresh();
    $seconds = $period->ended_at->getTimestamp() - $period->started_at->getTimestamp();
    expect($seconds)->toBe(300)
        ->not->toBe(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
});

test('an active avatar template for a DIFFERENT provider than the session does not apply — platform default wins', function (): void {
    $org = clockOrg();
    $session = clockSession($org); // provider = heygen

    $template = new AvatarTemplate;
    $template->forceFill([
        'organization_id' => $org->id,
        'name' => 'org-tavus-template',
        'provider' => 'tavus',
        'config' => ['faceId' => 'f', 'palId' => 'p', 'maxCallDurationSec' => 60],
        'is_active' => true,
    ])->save();

    $clock = new SessionLiveClock;
    $clock->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    $period->started_at = now()->subHours(5);
    $period->save();

    $clock->close($session, 'resume');

    $period->refresh();
    $seconds = $period->ended_at->getTimestamp() - $period->started_at->getTimestamp();
    expect($seconds)->toBe(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
});

test('the cap is a CEILING, never a FLOOR — a stretch well inside it records its real uncapped duration', function (): void {
    $org = clockOrg();
    $session = clockSession($org);
    $clock = new SessionLiveClock;

    $clock->open($session, 'ref-1');

    $period = InterviewSessionLivePeriod::where('interview_session_id', $session->id)->firstOrFail();
    $period->started_at = now()->subMinutes(5); // well under the 1200s ceiling
    $period->save();

    $clock->close($session, 'end');

    $period->refresh();
    $seconds = $period->ended_at->getTimestamp() - $period->started_at->getTimestamp();
    expect($seconds)->toBeLessThan(ProviderFieldSpecs::HEYGEN_MAX_SECONDS)
        ->toBeGreaterThanOrEqual(300 - 2) // ~5 minutes, allowing for test execution jitter
        ->toBeLessThanOrEqual(300 + 2);
});
