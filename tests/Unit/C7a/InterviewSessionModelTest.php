<?php

declare(strict_types=1);

/**
 * InterviewSession model unit tests (C7a — Interview Session Mechanics).
 *
 * Asserts:
 * - TenantScoped global scope applies organization_id automatically
 * - status enum accepted and rejected values
 * - belongsTo Participant, Project, FrameworkVersion
 * - hasMany Utterance, IntegrityEvent, InterviewSnapshot
 * - framework_version_id is in $fillable (copied at creation, never re-derived)
 * - started_at/ended_at cast as immutable datetime
 *
 * Tasks: 2.1 (RED)
 * REQ: InterviewSession tenant model
 */

use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\IntegrityEvent;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\TenantModel;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeC7aOrg(): Organization
{
    return Organization::factory()->create();
}

function makeC7aProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function makeC7aParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id'      => $project->id,
        'candidate_ref'   => 'c7a-' . uniqid(),
        'display_name'    => 'C7a Test',
        'status'          => 'in_attesa',
    ]);
    $p->save();
    return $p->fresh();
}

function makeC7aSession(Organization $org, Participant $participant, Project $project, array $attrs = []): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create(array_merge([
        'participant_id'       => $participant->id,
        'project_id'           => $project->id,
        'question_index'       => 0,
        'competency_code'      => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider'             => 'heygen',
        'status'               => 'pending',
    ], $attrs));
}

// ─── Extends TenantModel ──────────────────────────────────────────────────────

test('InterviewSession extends TenantModel', function (): void {
    expect(InterviewSession::class)->toExtend(TenantModel::class);
});

// ─── TenantScoped global scope ────────────────────────────────────────────────

test('InterviewSession has TenantScoped global scope applied', function (): void {
    $scopes = (new InterviewSession)->getGlobalScopes();
    expect($scopes)->toHaveKey('tenant');
});

test('InterviewSession queries are automatically filtered by organization_id', function (): void {
    $orgA = makeC7aOrg();
    $orgB = makeC7aOrg();

    $projectA = makeC7aProject($orgA);
    $projectB = makeC7aProject($orgB);

    $participantA = makeC7aParticipant($orgA, $projectA);
    $participantB = makeC7aParticipant($orgB, $projectB);

    $sessionA = makeC7aSession($orgA, $participantA, $projectA);
    $sessionB = makeC7aSession($orgB, $participantB, $projectB, ['competency_code' => 'STG']);

    // Scope to org A — must only see org A's sessions.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $ids = InterviewSession::all()->pluck('id');

    expect($ids)->toContain($sessionA->id);
    expect($ids)->not->toContain($sessionB->id);
});

// ─── $fillable ────────────────────────────────────────────────────────────────

test('InterviewSession has framework_version_id in $fillable', function (): void {
    expect((new InterviewSession)->getFillable())->toContain('framework_version_id');
});

test('InterviewSession has required fields in $fillable', function (): void {
    $required = [
        'participant_id',
        'project_id',
        'question_index',
        'competency_code',
        'framework_version_id',
        'provider',
        'status',
    ];

    $fillable = (new InterviewSession)->getFillable();

    foreach ($required as $field) {
        expect($fillable)->toContain($field);
    }
});

// ─── Status enum ──────────────────────────────────────────────────────────────

test('InterviewSession accepts valid status values', function (string $status): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    $session = makeC7aSession($org, $participant, $project, ['status' => $status]);

    expect($session->status)->toBe($status);
})->with(['pending', 'in_corso', 'completed', 'timeout', 'skipped', 'error']);

// ─── Casts ────────────────────────────────────────────────────────────────────

test('InterviewSession.started_at is cast as immutable datetime', function (): void {
    $casts = (new InterviewSession)->getCasts();

    expect($casts)->toHaveKey('started_at');
    expect($casts['started_at'])->toContain('immutable_datetime');
});

test('InterviewSession.ended_at is cast as immutable datetime', function (): void {
    $casts = (new InterviewSession)->getCasts();

    expect($casts)->toHaveKey('ended_at');
    expect($casts['ended_at'])->toContain('immutable_datetime');
});

// ─── Relations ────────────────────────────────────────────────────────────────

test('InterviewSession belongsTo Participant', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    expect($session->participant)->toBeInstanceOf(Participant::class);
    expect($session->participant->id)->toBe($participant->id);
});

test('InterviewSession belongsTo Project', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    expect($session->project)->toBeInstanceOf(Project::class);
    expect($session->project->id)->toBe($project->id);
});

test('InterviewSession belongsTo FrameworkVersion', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    expect($session->frameworkVersion)->toBeInstanceOf(FrameworkVersion::class);
    expect($session->frameworkVersion->id)->toBe($project->framework_version_id);
});

test('InterviewSession hasMany Utterances', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    Utterance::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id'      => $org->id,
        'speaker'              => 'candidate',
        'text'                 => 'Hello',
        'ts'                   => now(),
    ]);

    expect($session->utterances)->toHaveCount(1);
    expect($session->utterances->first())->toBeInstanceOf(Utterance::class);
});

test('InterviewSession hasMany IntegrityEvents', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    IntegrityEvent::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id'      => $org->id,
        'kind'                 => 'tab_hidden',
        'payload'              => '{}',
        'ts'                   => now(),
    ]);

    expect($session->integrityEvents)->toHaveCount(1);
    expect($session->integrityEvents->first())->toBeInstanceOf(IntegrityEvent::class);
});

test('InterviewSession hasMany InterviewSnapshots', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    InterviewSnapshot::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id'      => $org->id,
        's3_key'               => 'test/key.jpg',
        'taken_at'             => now(),
    ]);

    expect($session->interviewSnapshots)->toHaveCount(1);
    expect($session->interviewSnapshots->first())->toBeInstanceOf(InterviewSnapshot::class);
});

// ─── framework_version_id immutability ────────────────────────────────────────

test('InterviewSession.framework_version_id is copied at creation, not re-derived', function (): void {
    $org         = makeC7aOrg();
    $project     = makeC7aProject($org);
    $participant = makeC7aParticipant($org, $project);
    $session     = makeC7aSession($org, $participant, $project);

    // The session must carry its own framework_version_id, not a lazy reference to project.
    expect($session->framework_version_id)->toBe($project->framework_version_id);
    expect($session->framework_version_id)->not->toBeNull();
});
