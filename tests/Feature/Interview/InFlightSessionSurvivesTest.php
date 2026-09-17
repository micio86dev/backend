<?php

declare(strict_types=1);

/**
 * RED — 22.3 (framework-catalogue-authoring PR6, D6): an already-running
 * `in_corso` competency session is protected, never severed, by a LATER
 * configuration change that makes the project non-interviewable. The
 * predicate gates only the START of a NEW attempt — a fresh `/start` for a
 * competency with no prior session — never a session already in progress.
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`,
 * autoloaded): `casProject($org, 2)` gives each of its two competencies one
 * live `project_questions` row already (framework-catalogue-authoring PR6).
 */

use App\Models\InterviewSession;
use App\Models\ProjectQuestion;
use App\Support\Project\ProjectInterviewability;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('an already in_corso competency session continues uninterrupted when a DIFFERENT competency becomes non-interviewable', function (): void {
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 2);
    $participant = casParticipant($org, $project, 'in_corso');
    $bearer = casBearer($participant);

    // First /start — resolves competency 0 (lowest position, no prior
    // session), which IS interviewable (its own live question exists).
    $first = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');
    $first->assertStatus(201);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    $session = InterviewSession::where('participant_id', $participant->id)
        ->where('competency_code', $comps[0]->code)
        ->firstOrFail();
    expect($session->status)->toBe('in_corso');

    // The operator now empties the OTHER (not yet started) competency's
    // only live question — the project as a WHOLE is now non-interviewable
    // (D5 — every currently-selected competency must have ≥1 live question).
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[1]->id)
        ->delete();

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();

    // Calling /start AGAIN resolves the SAME competency 0 — a session
    // already exists for (participant, competency_code), so the predicate
    // is never re-evaluated for it. The candidate's in-progress conversation
    // is not severed by a configuration mistake on an unrelated competency.
    $second = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $second->assertStatus(201);

    $session->refresh();
    expect($session->status)->toBe('in_corso');
});

test('a NEW competency start is not blocked by an UNRELATED, not-yet-reached competency losing its question mid-interview', function (): void {
    // gga review finding on the first cut of this gate: re-evaluating the
    // WHOLE project on every competency transition would strand a candidate
    // who finished competency 0 and is moving to competency 1, solely
    // because competency 2 — not yet reached — lost its only question in
    // the meantime. Only THIS competency's own live-question state may gate
    // ITS OWN fresh start once the interview is already underway.
    Http::fake(heygenOkFake());
    Queue::fake();

    $org = casOrg();
    [$project, $comps] = casProject($org, 3);
    $participant = casParticipant($org, $project, 'in_corso');
    $bearer = casBearer($participant);

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    // Competency 0 already finished.
    InterviewSession::factory()->ended()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'participant_id' => $participant->id,
        'competency_code' => $comps[0]->code,
    ]);

    // The operator empties competency 2's (not yet reached) only question.
    ProjectQuestion::where('project_id', $project->id)
        ->where('competency_id', $comps[2]->id)
        ->delete();

    // /start now resolves competency 1 (lowest position not yet terminal) —
    // its own live question is untouched, and the project as a WHOLE is
    // non-interviewable (competency 2 has zero live questions), but that
    // must not block THIS transition.
    $response = test()->withHeaders(['Authorization' => 'Bearer '.$bearer])
        ->postJson('/api/candidate/interview/start');

    $response->assertStatus(201);
});
