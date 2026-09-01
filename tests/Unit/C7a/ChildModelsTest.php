<?php

declare(strict_types=1);

/**
 * Unit tests for Utterance, IntegrityEvent, InterviewSnapshot (C7a).
 *
 * Asserts:
 * - Each extends TenantModel (TenantScoped global scope applied)
 * - Each has a belongsTo InterviewSession relation
 * - Correct casts on datetime fields
 * - organization_id NOT in $fillable
 *
 * Tasks: 2.3 (RED)
 * REQ: Utterance, IntegrityEvent, InterviewSnapshot tenant models
 */

use App\Models\IntegrityEvent;
use App\Models\InterviewSession;
use App\Models\InterviewSnapshot;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\TenantModel;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeChildOrg(): Organization
{
    return Organization::factory()->create();
}

function makeChildProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function makeChildParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'child-'.uniqid(),
        'display_name' => 'Child Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_attesa',
    ]);
    $p->save();

    return $p->fresh();
}

function makeChildSession(Organization $org, Participant $participant, Project $project): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => 'PRS',
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'status' => 'pending',
    ]);
}

// ─── Utterance ────────────────────────────────────────────────────────────────

test('Utterance extends TenantModel', function (): void {
    expect(Utterance::class)->toExtend(TenantModel::class);
});

test('Utterance has TenantScoped global scope', function (): void {
    expect((new Utterance)->getGlobalScopes())->toHaveKey('tenant');
});

test('Utterance organization_id is NOT in $fillable', function (): void {
    expect((new Utterance)->getFillable())->not->toContain('organization_id');
});

test('Utterance.ts is cast as immutable datetime', function (): void {
    $casts = (new Utterance)->getCasts();
    expect($casts)->toHaveKey('ts');
    expect($casts['ts'])->toContain('immutable_datetime');
});

test('Utterance belongsTo InterviewSession', function (): void {
    $org = makeChildOrg();
    $project = makeChildProject($org);
    $participant = makeChildParticipant($org, $project);
    $session = makeChildSession($org, $participant, $project);

    $utterance = Utterance::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        'speaker' => 'candidate',
        'text' => 'Test utterance',
        'ts' => now(),
    ]);

    expect($utterance->interviewSession)->toBeInstanceOf(InterviewSession::class);
    expect($utterance->interviewSession->id)->toBe($session->id);
});

test('Utterance is scoped by organization_id (TenantScoped filters correctly)', function (): void {
    $orgA = makeChildOrg();
    $orgB = makeChildOrg();

    $resolver = app(TenantResolver::class);

    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    $projectA = makeChildProject($orgA);
    $partA = makeChildParticipant($orgA, $projectA);
    $sessionA = makeChildSession($orgA, $partA, $projectA);

    $resolver->setOrgId($orgB->id);
    $projectB = makeChildProject($orgB);
    $partB = makeChildParticipant($orgB, $projectB);
    $sessionB = makeChildSession($orgB, $partB, $projectB);

    // Use forceCreate for utterances (skips TenantScoped.creating) and set org_id explicitly.
    $resolver->setOrgId($orgA->id);
    Utterance::forceCreate([
        'interview_session_id' => $sessionA->id,
        'organization_id' => $orgA->id,
        'speaker' => 'candidate',
        'text' => 'Utterance A',
        'ts' => now(),
    ]);

    $resolver->setOrgId($orgB->id);
    Utterance::forceCreate([
        'interview_session_id' => $sessionB->id,
        'organization_id' => $orgB->id,
        'speaker' => 'candidate',
        'text' => 'Utterance B',
        'ts' => now(),
    ]);

    // Query scoped to orgA only — must see only orgA utterance.
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $utterances = Utterance::all();
    expect($utterances)->toHaveCount(1);
    expect($utterances->first()->text)->toBe('Utterance A');
});

// ─── IntegrityEvent ──────────────────────────────────────────────────────────

test('IntegrityEvent extends TenantModel', function (): void {
    expect(IntegrityEvent::class)->toExtend(TenantModel::class);
});

test('IntegrityEvent has TenantScoped global scope', function (): void {
    expect((new IntegrityEvent)->getGlobalScopes())->toHaveKey('tenant');
});

test('IntegrityEvent organization_id is NOT in $fillable', function (): void {
    expect((new IntegrityEvent)->getFillable())->not->toContain('organization_id');
});

test('IntegrityEvent.ts is cast as immutable datetime', function (): void {
    $casts = (new IntegrityEvent)->getCasts();
    expect($casts)->toHaveKey('ts');
    expect($casts['ts'])->toContain('immutable_datetime');
});

test('IntegrityEvent belongsTo InterviewSession', function (): void {
    $org = makeChildOrg();
    $project = makeChildProject($org);
    $participant = makeChildParticipant($org, $project);
    $session = makeChildSession($org, $participant, $project);

    $event = IntegrityEvent::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        'kind' => 'tab_hidden',
        'payload' => '{}',
        'ts' => now(),
    ]);

    expect($event->interviewSession)->toBeInstanceOf(InterviewSession::class);
    expect($event->interviewSession->id)->toBe($session->id);
});

// ─── InterviewSnapshot ────────────────────────────────────────────────────────

test('InterviewSnapshot extends TenantModel', function (): void {
    expect(InterviewSnapshot::class)->toExtend(TenantModel::class);
});

test('InterviewSnapshot has TenantScoped global scope', function (): void {
    expect((new InterviewSnapshot)->getGlobalScopes())->toHaveKey('tenant');
});

test('InterviewSnapshot organization_id is NOT in $fillable', function (): void {
    expect((new InterviewSnapshot)->getFillable())->not->toContain('organization_id');
});

test('InterviewSnapshot.taken_at is cast as immutable datetime', function (): void {
    $casts = (new InterviewSnapshot)->getCasts();
    expect($casts)->toHaveKey('taken_at');
    expect($casts['taken_at'])->toContain('immutable_datetime');
});

test('InterviewSnapshot belongsTo InterviewSession', function (): void {
    $org = makeChildOrg();
    $project = makeChildProject($org);
    $participant = makeChildParticipant($org, $project);
    $session = makeChildSession($org, $participant, $project);

    $snapshot = InterviewSnapshot::forceCreate([
        'interview_session_id' => $session->id,
        'organization_id' => $org->id,
        's3_key' => 'test/path.jpg',
        'taken_at' => now(),
    ]);

    expect($snapshot->interviewSession)->toBeInstanceOf(InterviewSession::class);
    expect($snapshot->interviewSession->id)->toBe($session->id);
});
