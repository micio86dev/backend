<?php

declare(strict_types=1);

/**
 * Regression suite for role-scoped BARS indicator resolution in scoring.
 *
 * `ScoreEvaluationJob` loaded BARS indicators scoped by competency ONLY (no
 * `role_id` filter), justified by a comment claiming "the indicators are
 * associated by competency across roles". That premise is false:
 * `framework_bars_indicators` is UNIQUE (role_id, competency_id, position), so
 * one competency carries 3 rows PER ROLE. A standard project pinned to one
 * role was therefore scored against every OTHER role's rows for the same
 * competency too — the competency mean was computed over foreign anchors, and
 * `reliability` (assessed/total) came out with a denominator of 3 x roles
 * instead of 3, pushing nearly everything under the ratified T=0.5 validity
 * threshold.
 *
 * Revision scoping is NOT the subject here and is already safe by
 * construction: `project_competencies.competency_id` is a numeric id already
 * bound to one revision, and the composite FK
 * `framework_bars_indicators (competency_id, revision_id)` guarantees a row's
 * competency and its own revision always agree. The ROLE side had no such
 * guarantee, because `project.role_code` is a CODE and was never resolved at
 * all.
 *
 * Verifies:
 * (a) Per role: a project pinned to role X scores its competency against
 *     EXACTLY the 3 indicators of role X — no foreign-role `indicator_text` is
 *     ever persisted.
 * (b) `potential` project (`role_code` = null): the role-less MTG/LAT rows
 *     reach the parser via `whereNull('role_id')`, run against real PostgreSQL
 *     (`where('role_id', null)` emits `role_id = NULL`, which evaluates to
 *     UNKNOWN and returns nothing — unprovable against a double).
 * (c) Reliability domain: `competency_results.reliability` is only ever
 *     assessed/3, never assessed/(3 x roles).
 * (d) `role_no_bars` is reachable for a genuinely unanchored (role,
 *     competency) pair — no cross-role rows keep `$indicators` non-empty.
 * (e) An unresolvable `role_code` ends the participant instead of stranding
 *     them in `in_valutazione` forever.
 *
 * REQ: ScoreEvaluationJob role resolution (scoring-role-scoped-indicators)
 */

use App\Contracts\LLMProvider;
use App\Enums\UnscorableReason;
use App\Events\EvaluationFailed;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Role;
use App\Models\Utterance;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Create the tenant and pin the resolver to it.
 */
function roleScopedOrg(): Organization
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function roleScopedProject(array $overrides = []): Project
{
    return Project::factory()->create(array_merge([
        'status' => 'active',
        'language' => 'en',
    ], $overrides));
}

function roleScopedParticipant(Organization $org, Project $project): Participant
{
    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'rsi-'.uniqid(),
        'display_name' => 'Role Scoped Test',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();

    return $participant->fresh();
}

function roleScopedSession(Organization $org, Project $project, Participant $participant, string $competencyCode): void
{
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competencyCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    $utterance = new Utterance;
    $utterance->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => 'Candidate',
        'text' => 'I led the rollout end to end and checked in with the team daily.',
        'ts' => now(),
    ]);
    $utterance->save();
}

/**
 * Author 3 indicators for $role against $competency, with per-role distinct
 * text so a cross-role leak is identifiable by its text alone.
 *
 * @return list<string>
 */
function roleScopedAuthorIndicators(?Role $role, Competency $competency, string $label): array
{
    $texts = [];

    foreach ([0, 1, 2] as $position) {
        $text = "{$label} indicator text {$position}";

        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role?->id,
            'competency_id' => $competency->id,
            'revision_id' => $competency->revision_id,
            'text' => ['en' => $text],
            'anchor_5' => ['en' => 'Anchor 5'],
            'anchor_3' => ['en' => 'Anchor 3'],
            'anchor_1' => ['en' => 'Anchor 1'],
            'position' => $position,
        ]);
        $indicator->save();

        $texts[] = $text;
    }

    return $texts;
}

/**
 * Author 3 indicators for EACH of the 5 standard roles against ONE shared
 * competency — the exact shape of the real catalogue, where PRS/STG/COL carry
 * 15 rows apiece.
 *
 * @return array{roles: array<string, Role>, textsByRole: array<string, list<string>>}
 */
function roleScopedSeedAllRoles(Competency $competency): array
{
    $roles = [];
    $textsByRole = [];

    foreach (['ICO', 'FLL', 'MLL', 'BUL', 'SRX'] as $code) {
        $role = Role::factory()->create([
            'code' => $code.'_'.uniqid(),
            'revision_id' => $competency->revision_id,
        ]);

        $roles[$code] = $role;
        $textsByRole[$code] = roleScopedAuthorIndicators($role, $competency, $code);
    }

    return ['roles' => $roles, 'textsByRole' => $textsByRole];
}

function roleScopedThreeBehaviorsJson(): string
{
    return json_encode([
        'behaviors' => [
            ['indicator' => 'echo 0', 'score' => 5, 'explanation' => 'Strong.', 'excerpts' => ['I led the rollout end to end']],
            ['indicator' => 'echo 1', 'score' => 3, 'explanation' => 'Adequate.', 'excerpts' => ['checked in with the team daily']],
            ['indicator' => 'echo 2', 'score' => 1, 'explanation' => 'Weak.', 'excerpts' => []],
        ],
    ], JSON_THROW_ON_ERROR);
}

function roleScopedResultFor(Participant $participant, string $competencyCode): ?CompetencyResult
{
    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();

    expect($evaluation)->not->toBeNull();

    return CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();
}

// ─── (a) per-role cross-role isolation ───────────────────────────────────────

test('a project pinned to one role scores only that role indicators', function (string $pinnedCode): void {
    $org = roleScopedOrg();

    $competency = Competency::factory()->create(['code' => 'SHARED_'.uniqid()]);
    ['roles' => $roles, 'textsByRole' => $textsByRole] = roleScopedSeedAllRoles($competency);

    $project = roleScopedProject(['role_code' => $roles[$pinnedCode]->code]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = roleScopedParticipant($org, $project);
    roleScopedSession($org, $project, $participant, $competency->code);

    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $competency->code => roleScopedThreeBehaviorsJson(),
    ]));

    (new ScoreEvaluationJob($participant->id))->handle();

    $result = roleScopedResultFor($participant, $competency->code);

    expect($result)->not->toBeNull();
    expect($result->unscorable_reason)->toBeNull();

    $persisted = IndicatorScore::where('competency_result_id', $result->id)
        ->pluck('indicator_text')
        ->sort()
        ->values()
        ->all();

    $expected = collect($textsByRole[$pinnedCode])->sort()->values()->all();

    expect($persisted)->toBe($expected);

    foreach ($textsByRole as $code => $texts) {
        if ($code === $pinnedCode) {
            continue;
        }

        foreach ($texts as $foreignText) {
            expect($persisted)->not->toContain($foreignText);
        }
    }
})->with(['ICO', 'FLL', 'MLL', 'BUL', 'SRX']);

// ─── (b) potential — role-less rows resolve via whereNull ────────────────────

test('a potential project scores the role-less rows and never a role-scoped decoy', function (string $competencyCode): void {
    $org = roleScopedOrg();

    $competency = Competency::factory()->potential()->create(['code' => $competencyCode.'_'.uniqid()]);

    // Role-less rows — mirrors how MTG/LAT are authored (role_id IS NULL).
    $texts = roleScopedAuthorIndicators(null, $competency, $competencyCode);

    // A role-scoped decoy for the SAME competency must never leak in.
    $decoyRole = Role::factory()->create([
        'code' => 'DECOY_'.uniqid(),
        'revision_id' => $competency->revision_id,
    ]);
    $decoyTexts = roleScopedAuthorIndicators($decoyRole, $competency, 'DECOY');

    $project = roleScopedProject(['assessment_type' => 'potential', 'role_code' => null]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = roleScopedParticipant($org, $project);
    roleScopedSession($org, $project, $participant, $competency->code);

    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $competency->code => roleScopedThreeBehaviorsJson(),
    ]));

    (new ScoreEvaluationJob($participant->id))->handle();

    $result = roleScopedResultFor($participant, $competency->code);

    expect($result)->not->toBeNull();
    expect($result->unscorable_reason)->toBeNull();

    $persisted = IndicatorScore::where('competency_result_id', $result->id)
        ->pluck('indicator_text')
        ->sort()
        ->values()
        ->all();

    expect($persisted)->toBe(collect($texts)->sort()->values()->all());

    foreach ($decoyTexts as $decoyText) {
        expect($persisted)->not->toContain($decoyText);
    }
})->with(['MTG', 'LAT']);

// ─── (c) reliability domain ──────────────────────────────────────────────────

test('reliability is assessed over 3 indicators, never over every role indicators', function (): void {
    $org = roleScopedOrg();

    $competency = Competency::factory()->create(['code' => 'REL_'.uniqid()]);
    ['roles' => $roles] = roleScopedSeedAllRoles($competency);

    $project = roleScopedProject(['role_code' => $roles['ICO']->code]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = roleScopedParticipant($org, $project);
    roleScopedSession($org, $project, $participant, $competency->code);

    // 2 assessed (5, 3) + 1 unassessable (-1) → reliability = 2/3 = 0.6667.
    app()->instance(LLMProvider::class, new CassetteLLMProvider([
        $competency->code => json_encode([
            'behaviors' => [
                ['indicator' => 'echo 0', 'score' => 5, 'explanation' => 'Strong.', 'excerpts' => ['I led the rollout end to end']],
                ['indicator' => 'echo 1', 'score' => 3, 'explanation' => 'Adequate.', 'excerpts' => ['checked in with the team daily']],
                ['indicator' => 'echo 2', 'score' => -1, 'explanation' => 'No evidence.', 'excerpts' => []],
            ],
        ], JSON_THROW_ON_ERROR),
    ]));

    (new ScoreEvaluationJob($participant->id))->handle();

    $result = roleScopedResultFor($participant, $competency->code);

    expect($result)->not->toBeNull();
    expect(IndicatorScore::where('competency_result_id', $result->id)->count())->toBe(3);

    // Read the raw decimal(5,4) string Postgres stores, never a rounded float.
    $rawReliability = DB::table('competency_results')->where('id', $result->id)->value('reliability');

    expect(['0.0000', '0.3333', '0.6667', '1.0000'])->toContain($rawReliability);
    expect($rawReliability)->toBe('0.6667');
});

// ─── (d) role_no_bars is reachable for a genuinely unanchored pair ───────────

test('an unanchored role and competency pair is role_no_bars even when other roles anchor it', function (): void {
    $org = roleScopedOrg();

    $competency = Competency::factory()->create(['code' => 'NOBARS_'.uniqid()]);

    $pinnedRole = Role::factory()->create([
        'code' => 'NOBARS_'.uniqid(),
        'revision_id' => $competency->revision_id,
    ]);

    // Two OTHER roles DO anchor this competency. Under the competency-only
    // query their rows kept $indicators non-empty, so role_no_bars could never
    // fire and the candidate was scored against a role they were not assessed
    // for.
    foreach (['FOREIGN1', 'FOREIGN2'] as $label) {
        $foreignRole = Role::factory()->create([
            'code' => $label.'_'.uniqid(),
            'revision_id' => $competency->revision_id,
        ]);

        roleScopedAuthorIndicators($foreignRole, $competency, $label);
    }

    $project = roleScopedProject(['role_code' => $pinnedRole->code]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = roleScopedParticipant($org, $project);
    roleScopedSession($org, $project, $participant, $competency->code);

    // An empty cassette: any LLM call for this competency throws and fails the test.
    app()->instance(LLMProvider::class, new CassetteLLMProvider([]));

    (new ScoreEvaluationJob($participant->id))->handle();

    $result = roleScopedResultFor($participant, $competency->code);

    expect($result)->not->toBeNull();
    expect($result->unscorable_reason)->toBe(UnscorableReason::RoleNoBars->value);
    expect($result->valid)->toBeFalse();

    $evaluationId = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->value('id');

    expect(AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluationId)
        ->where('competency_code', $competency->code)
        ->count())->toBe(0);
});

// ─── (e) unresolvable role_code ends the participant ─────────────────────────

test('an unresolvable role_code ends the participant instead of stranding them', function (): void {
    // By the time the role is resolved the Evaluation row already exists as
    // `processing` and the participant is still `in_valutazione`. A bare
    // `return` here throws nothing, so failed() never runs, no terminal status
    // is written and no webhook is sent — and a re-dispatch hits the same
    // guard and returns again. The candidate would be stranded permanently and
    // the calling system never told.
    Event::fake([EvaluationFailed::class]);

    $org = roleScopedOrg();
    $project = roleScopedProject(['role_code' => 'NOT_A_REAL_ROLE']);
    $participant = roleScopedParticipant($org, $project);

    (new ScoreEvaluationJob($participant->id))->handle();

    expect($participant->fresh()->status)->toBe('errore');

    Event::assertDispatched(EvaluationFailed::class);
});

test('a project whose framework version pins no catalogue revision ends the participant', function (): void {
    // Deliberately a `potential` project, because that is the only shape where
    // this guard is the one doing the work. A `standard` project with a broken
    // pin fails the role lookup anyway (`revision_id = NULL` is UNKNOWN in
    // PostgreSQL, so it matches nothing), which makes the two guards
    // indistinguishable by outcome. A role-less project has no role lookup to
    // fail: without this guard the loader falls back to its LATEST PUBLISHED
    // default and the candidate is scored against content their project was
    // never pinned to — exactly the retargeting that pinning a framework
    // version exists to prevent.
    Event::fake([EvaluationFailed::class]);

    $org = roleScopedOrg();

    $competency = Competency::factory()->potential()->create(['code' => 'NOREV_'.uniqid()]);
    roleScopedAuthorIndicators(null, $competency, 'NOREV');

    $project = roleScopedProject(['assessment_type' => 'potential', 'role_code' => null]);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    // FrameworkVersion::creating assigns the latest published revision whenever
    // the pin is still unset, so it has to be cleared afterwards, and through
    // the query builder — the model itself refuses to be left unpinned.
    DB::table('framework_versions')
        ->where('id', $project->framework_version_id)
        ->update(['revision_id' => null]);

    $participant = roleScopedParticipant($org, $project);
    roleScopedSession($org, $project, $participant, $competency->code);

    // An empty cassette: scoring this competency at all throws and fails the test.
    app()->instance(LLMProvider::class, new CassetteLLMProvider([]));

    (new ScoreEvaluationJob($participant->id))->handle();

    expect($participant->fresh()->status)->toBe('errore');

    Event::assertDispatched(EvaluationFailed::class);

    // Nothing was scored: the job stopped at the pin, it did not fall back.
    $evaluationId = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->value('id');

    expect(CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $evaluationId)->count())->toBe(0);
});
