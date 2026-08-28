<?php

declare(strict_types=1);

/**
 * The BARS evidence for the competency a session probed, served on the
 * session review itself (`GET /api/interview-sessions/{session}/review`).
 *
 * THE JOIN, AND WHY IT IS EXACT
 * -----------------------------
 * `IndicatorScore` carries no `interview_session_id`, so the link is composite
 * — and it is a bijection enforced by three database unique constraints, not a
 * heuristic:
 *
 *   interview_sessions   UNIQUE (participant_id, competency_code)
 *   evaluations          UNIQUE (participant_id)
 *   competency_results   UNIQUE (evaluation_id, competency_code)
 *
 * A session therefore determines exactly one `(participant_id,
 * competency_code)` pair, that participant has at most one evaluation, and that
 * evaluation has at most one result for that competency. `competency_code`
 * ALONE would be ambiguous across participants; `competency_code` scoped to the
 * session's participant is not.
 *
 * WHAT THE JOIN DOES *NOT* PROVE
 * ------------------------------
 * That an excerpt was spoken DURING this session. `TranscriptAssembler` builds
 * the validation corpus from the participant's WHOLE interview on purpose
 * ("evidence a candidate gave while answering a different competency's question
 * is still evidence"), so a COL excerpt may legitimately quote the PRS session.
 * Rendering it under a session page without saying so would be the misattribution
 * this endpoint must not commit — hence `spoken_in_this_session`, computed with
 * the SAME matcher that validated the excerpt at scoring time.
 *
 * READ GATE
 * ---------
 * Excerpts are part of the structured evaluation, so the Evaluation threshold
 * applies: `completato` only. The endpoint itself stays a Summary read — the
 * gate decides whether the block is PRESENT, never whether the session is
 * readable. Raising the whole endpoint would deny session review to an
 * in-flight candidate, which is the review's main use.
 *
 * REQ: Admin session review; Lifecycle Read-Gate
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\Utterance;
use App\Services\Scoring\ExcerptValidator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

const EXCERPT_COL = 'ho riunito i due team e riscritto il piano insieme a loro';
const EXCERPT_PRS = 'ho presentato i numeri al board senza addolcirli';

function excerptToken(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

/**
 * A participant with two competency sessions (COL, PRS), each carrying one
 * candidate utterance, and a completed evaluation whose COL result quotes the
 * COL utterance and whose PRS result quotes the PRS one.
 *
 * @return array{org: Organization, participant: Participant, col: InterviewSession, prs: InterviewSession, colResult: CompetencyResult, prsResult: CompetencyResult}
 */
function excerptFixture(string $status = 'completato'): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
    ]);
    $participant = Participant::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'status' => $status,
    ]);

    $sessions = [];
    foreach (['COL' => 0, 'PRS' => 1] as $code => $index) {
        $sessions[$code] = InterviewSession::factory()->ended(600)->create([
            'organization_id' => $org->id,
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'framework_version_id' => $fv->id,
            'competency_code' => $code,
            'question_index' => $index,
            'provider' => 'heygen',
        ]);
    }

    Utterance::create([
        'interview_session_id' => $sessions['COL']->id,
        'speaker' => 'candidate',
        'text' => 'Quando i due gruppi si sono bloccati, '.EXCERPT_COL.' in una mattina.',
        'ts' => now(),
    ]);
    Utterance::create([
        'interview_session_id' => $sessions['PRS']->id,
        'speaker' => 'candidate',
        'text' => 'Alla revisione trimestrale '.EXCERPT_PRS.', anche quando erano brutti.',
        'ts' => now(),
    ]);

    $evaluation = Evaluation::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'framework_version_id' => $fv->id,
    ]);

    $results = [];
    foreach (['COL' => EXCERPT_COL, 'PRS' => EXCERPT_PRS] as $code => $excerpt) {
        $results[$code] = CompetencyResult::factory()->create([
            'organization_id' => $org->id,
            'evaluation_id' => $evaluation->id,
            'competency_code' => $code,
            'score' => 3.67,
            'reliability' => 1.0,
            'valid' => true,
        ]);

        IndicatorScore::factory()->create([
            'organization_id' => $org->id,
            'competency_result_id' => $results[$code]->id,
            'position' => 0,
            'indicator_text' => "Indicator for {$code}",
            'score' => 5,
            'explanation' => "Why {$code} scored 5.",
            'excerpts' => [$excerpt],
        ]);
    }

    return [
        'org' => $org,
        'participant' => $participant,
        'col' => $sessions['COL'],
        'prs' => $sessions['PRS'],
        'colResult' => $results['COL'],
        'prsResult' => $results['PRS'],
    ];
}

beforeEach(function (): void {
    Storage::fake();
});

test('the session review carries the excerpt for the competency THIS session probed — one request, no client-side correlation', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    $response = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk();

    // Same response as the rest of the review — the whole point is that the
    // backoffice does not fetch evaluation data separately and pair it up
    // itself, which is where a wrong association would be introduced.
    $response->assertJsonPath('data.competency_code', 'COL');
    $response->assertJsonPath('data.evaluation.competency_code', 'COL');
    $response->assertJsonPath('data.evaluation.behaviors.0.excerpts.0', EXCERPT_COL);
    $response->assertJsonPath('data.evaluation.score', 3.67);
});

test('each session gets its OWN competency evidence, never the sibling session s', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    $col = $this->withToken($token)->getJson("/api/interview-sessions/{$f['col']->id}/review")->assertOk();
    $prs = $this->withToken($token)->getJson("/api/interview-sessions/{$f['prs']->id}/review")->assertOk();

    expect($col->json('data.evaluation.behaviors.0.excerpts'))->toBe([EXCERPT_COL]);
    expect($prs->json('data.evaluation.behaviors.0.excerpts'))->toBe([EXCERPT_PRS]);

    // The failure this asserts against is the plausible-looking one: joining
    // on `competency_code` alone, or on the participant alone, and handing
    // both sessions the same (or the wrong) block.
    expect($col->json('data.evaluation.behaviors.0.excerpts'))
        ->not->toBe($prs->json('data.evaluation.behaviors.0.excerpts'));
});

test('the excerpt is byte-identical to the stored value — no truncation, no ellipsis, no whitespace rewrite', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    $stored = IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $f['colResult']->id)
        ->firstOrFail();

    $returned = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->json('data.evaluation.behaviors.0.excerpts');

    // The verbatim guarantee is only worth anything if the read surface does
    // not reformat what scoring validated.
    expect($returned)->toBe($stored->excerpts);
});

test('every returned excerpt is still a verbatim quote of the candidate transcript, checked with the scoring-time matcher', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    $corpus = Utterance::whereIn(
        'interview_session_id',
        [$f['col']->id, $f['prs']->id]
    )->where('speaker', 'candidate')->pluck('text')->implode("\n");

    $validator = app(ExcerptValidator::class);

    foreach ([$f['col'], $f['prs']] as $session) {
        $excerpts = $this->withToken($token)
            ->getJson("/api/interview-sessions/{$session->id}/review")
            ->json('data.evaluation.behaviors.0.excerpts');

        foreach ($excerpts as $excerpt) {
            expect($validator->matchesCorpus($excerpt, $corpus))->toBeTrue();
        }
    }
});

test('an excerpt spoken in a DIFFERENT session is flagged, because the scoring corpus spans the whole interview', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    // A legitimate cross-session quote: COL's evidence is something the
    // candidate said during the PRS session. `TranscriptAssembler` permits
    // exactly this, so the read surface must not imply otherwise.
    IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $f['colResult']->id)
        ->firstOrFail()
        ->forceFill(['excerpts' => [EXCERPT_PRS]])
        ->saveQuietly();

    $behaviour = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->json('data.evaluation.behaviors.0');

    expect($behaviour['excerpts'])->toBe([EXCERPT_PRS]);
    expect($behaviour['excerpts_spoken_in_this_session'])->toBe([false]);
});

test('an excerpt spoken in THIS session is flagged as such', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    $behaviour = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->json('data.evaluation.behaviors.0');

    expect($behaviour['excerpts_spoken_in_this_session'])->toBe([true]);
});

test('the evaluation block is absent before the participant reaches completato — the Evaluation read gate still binds', function (): void {
    // `in_valutazione` passes the TRANSCRIPT gate but not the EVALUATION one.
    // Scoring may already have written partial rows; exposing them here would
    // route around the gate that exists to stop exactly that.
    $f = excerptFixture('in_valutazione');
    $token = excerptToken($f['org']);

    $response = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk();

    // The SESSION stays readable — the gate governs the block, not the route.
    $response->assertJsonPath('data.competency_code', 'COL');
    $response->assertJsonPath('data.evaluation', null);
});

test('an errore participant gets no evaluation block — a BARS verdict for a crashed interview is never permitted', function (): void {
    $f = excerptFixture('errore');
    $token = excerptToken($f['org']);

    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->assertJsonPath('data.evaluation', null);
});

test('a completato participant with no evaluation row degrades to null instead of erroring', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    Evaluation::withoutGlobalScopes()->where('participant_id', $f['participant']->id)->delete();

    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->assertJsonPath('data.evaluation', null);
});

test('a session whose competency was never scored gets a null block, not another competency s evidence', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    CompetencyResult::withoutGlobalScopes()->where('id', $f['colResult']->id)->delete();

    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->assertJsonPath('data.evaluation', null);
});

test('an unassessable indicator renders score null with no excerpts, never the literal -1', function (): void {
    $f = excerptFixture();
    $token = excerptToken($f['org']);

    IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $f['colResult']->id)
        ->firstOrFail()
        ->forceFill(['score' => -1, 'excerpts' => [], 'unassessable_reason' => 'model_declared'])
        ->saveQuietly();

    $behaviour = $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertOk()
        ->json('data.evaluation.behaviors.0');

    expect($behaviour['score'])->toBeNull();
    expect($behaviour['excerpts'])->toBe([]);
    expect($behaviour['excerpts_spoken_in_this_session'])->toBe([]);
    expect($behaviour['unassessable_reason'])->toBe('model_declared');
});

test('a viewer of another organization cannot reach the session, so cannot reach its excerpts', function (): void {
    $f = excerptFixture();
    $otherOrg = Organization::factory()->create();
    $token = excerptToken($otherOrg);

    $this->withToken($token)
        ->getJson("/api/interview-sessions/{$f['col']->id}/review")
        ->assertNotFound();
});
