<?php

declare(strict_types=1);

/**
 * RED — 6.4: ProgressPayloadAssembler (C10, design.md D7).
 *
 * Asserts the two spec scenarios:
 * - New-candidate case: ALL project competencies present, each with empty `answers`.
 * - Advancement case: cumulative state across competencies — a completed session
 *   yields exactly one answer entry (question_index + answered_at); a session with no
 *   `ended_at` yet yields an empty answers array regardless of its live status.
 *
 * LEFT JOIN project_competencies × interview_sessions on (participant_id,
 * competency_code) — design.md D7/S-precedent (`…100002_create_interview_sessions_table.php:47,50,72`).
 */

use App\Enums\ApiKeyMode;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Webhooks\ProgressPayloadAssembler;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function c10ProgressFixtures(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create();
    $participant = Participant::factory()->forProject($project)->create([
        'candidate_ref' => 'candidate-ref-verbatim-progress-1',
    ]);

    $prs = Competency::factory()->create(['code' => 'PRS']);
    $col = Competency::factory()->create(['code' => 'COL']);

    // Attach out of alphabetical order to prove position-based ordering: PRS first.
    $project->competencies()->attach([
        $prs->id => ['position' => 0],
        $col->id => ['position' => 1],
    ]);

    return [$org, $project, $participant];
}

function c10CreateSession(
    Project $project,
    Participant $participant,
    string $competencyCode,
    int $questionIndex,
    string $status,
    ?DateTimeInterface $endedAt = null
): InterviewSession {
    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => $questionIndex,
        'competency_code' => $competencyCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => null,
        'status' => $status,
        'ended_reason' => $endedAt !== null ? 'completed' : null,
        'started_at' => now()->subMinutes(5),
        'ended_at' => $endedAt,
    ]);
}

test('envelope carries version, event=progress, delivery_id, occurred_at, candidate_ref verbatim, project{id,slug}', function (): void {
    [$org, $project, $participant] = c10ProgressFixtures();
    $deliveryId = (string) Str::uuid();

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, $deliveryId);

    expect($payload['version'])->toBe(config('webhooks.payload.version'))
        ->and($payload['event'])->toBe('progress')
        ->and($payload['delivery_id'])->toBe($deliveryId)
        ->and($payload['candidate_ref'])->toBe('candidate-ref-verbatim-progress-1')
        ->and($payload['project'])->toBe(['id' => $project->id, 'slug' => $project->slug]);
});

// ─── livemode (public-api step 9, SPEC.md §3.7) ───────────────────────────
//
// A progress webhook for a test-mode participant must carry livemode:false
// — the calling system's own mock/sandbox integration relies on this field
// to tell a real delivery apart from one produced by a test-mode /v1
// interview.

test('livemode is true for a live-mode participant', function (): void {
    [$org, , $participant] = c10ProgressFixtures();

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, (string) Str::uuid());

    expect($payload['livemode'])->toBeTrue();
});

test('livemode is false for a test-mode participant', function (): void {
    [$org, , $participant] = c10ProgressFixtures();
    $participant->forceFill(['mode' => ApiKeyMode::Test])->save();

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, (string) Str::uuid());

    expect($payload['livemode'])->toBeFalse();
});

test('new-candidate case: all project competencies present with empty answers', function (): void {
    [$org, , $participant] = c10ProgressFixtures();
    // No interview_sessions created — a brand-new candidate.

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, (string) Str::uuid());
    $competencies = $payload['data']['competencies'];

    expect(array_column($competencies, 'code'))->toBe(['PRS', 'COL']);

    foreach ($competencies as $competency) {
        expect($competency['answers'])->toBe([])
            ->and($competency['status'])->toBe('pending');
    }
});

test('advancement case: cumulative state across competencies', function (): void {
    [$org, $project, $participant] = c10ProgressFixtures();

    $endedAt = now()->subMinutes(2);
    c10CreateSession($project, $participant, 'PRS', 0, 'completed', $endedAt);
    c10CreateSession($project, $participant, 'COL', 1, 'in_corso', null);

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, (string) Str::uuid());
    $byCode = collect($payload['data']['competencies'])->keyBy('code');

    expect($byCode['PRS']['status'])->toBe('completed')
        ->and($byCode['PRS']['answers'])->toHaveCount(1)
        ->and($byCode['PRS']['answers'][0]['question_index'])->toBe(0)
        ->and($byCode['PRS']['answers'][0]['answered_at'])->not->toBeEmpty();

    // A live session with no ended_at yet contributes no answer entry, even though
    // its status is not 'pending'.
    expect($byCode['COL']['status'])->toBe('in_corso')
        ->and($byCode['COL']['answers'])->toBe([]);
});

test('deterministic ordering follows project_competencies.position regardless of session creation order', function (): void {
    [$org, $project, $participant] = c10ProgressFixtures();

    // Create the COL session first, PRS session second — insertion order reversed
    // relative to pivot position — to prove ordering is position-driven.
    c10CreateSession($project, $participant, 'COL', 1, 'completed', now());
    c10CreateSession($project, $participant, 'PRS', 0, 'completed', now());

    $payload = (new ProgressPayloadAssembler)->assemble($participant->id, $org->id, (string) Str::uuid());

    expect(array_column($payload['data']['competencies'], 'code'))->toBe(['PRS', 'COL']);
});

// ─── Tenancy guard (pre-commit gate, round 4, finding 3) ───────────────────

test('assemble() refuses to render a participant that belongs to a different organization than the caller-supplied organizationId', function (): void {
    [, , $participant] = c10ProgressFixtures();
    $otherOrg = Organization::factory()->create();

    (new ProgressPayloadAssembler)->assemble($participant->id, $otherOrg->id, (string) Str::uuid());
})->throws(ModelNotFoundException::class);
