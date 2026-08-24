<?php

declare(strict_types=1);

/**
 * Determinism test — Task D8 (WARNING-4 quality debt closure).
 *
 * Spec D8 hard invariant: same input at temperature=0 → identical scores, reliability, and excerpts
 * across multiple runs. This test makes it explicit by running the full scoring pipeline TWICE
 * on the SAME input (same transcript, same indicators, same cassette) via CassetteLLMProvider
 * and asserting that the two runs produce IDENTICAL CompetencyResult scores, reliability, and
 * IndicatorScore excerpts.
 *
 * The golden cassette fixture is keyed by competency_code; CassetteLLMProvider returns the
 * same pre-recorded JSON on every call, simulating a real temperature=0 LLM endpoint that
 * always returns the same output for the same prompt.
 *
 * Verifies:
 * (a) Two runs on the same participant/transcript/framework → identical COL score.
 * (b) Two runs → identical COL reliability.
 * (c) Two runs → identical COL IndicatorScore excerpts (all three indicators).
 * (d) (bars-full-scale-1-5, task 3.10) Two runs over a cassette that returns the
 *     RESIDUAL levels {5,4,2} → identical CompetencyResult score/reliability and
 *     identical IndicatorScore excerpts — run-twice invariance is not an
 *     accident of the anchor-only {1,3,5} domain, it must also hold once 2 and
 *     4 are legal.
 *
 * REQ: Determinism (C9 D8) — explicit run-twice assertion
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
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

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function detOrg(): Organization
{
    return Organization::factory()->create();
}

function detProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function detParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'det-'.uniqid(),
        'display_name' => 'Determinism Test Participant',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return Participant::withoutGlobalScopes()->findOrFail($p->id);
}

/**
 * Set up one competency (COL, 3 indicators matching the golden cassette) for a fresh participant.
 *
 * Returns the Competency instance.
 */
function detSetupCompetency(Organization $org, Project $project, Participant $participant): Competency
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $role = Role::factory()->create(['code' => 'DET_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'DET_COL_'.uniqid()]);

    $project->competencies()->syncWithoutDetaching([$competency->id => ['position' => 0]]);

    $indicatorSpecs = [
        ['text' => 'Work effectively with others', 'anchor_5' => 'Always collaborates excellently', 'anchor_3' => 'Collaborates adequately', 'anchor_1' => 'Rarely collaborates'],
        ['text' => 'Willingly help colleagues in trouble', 'anchor_5' => 'Always helps colleagues', 'anchor_3' => 'Sometimes helps colleagues', 'anchor_1' => 'Rarely helps colleagues'],
        ['text' => 'Demonstrate commitment to team goals', 'anchor_5' => 'Always committed to team goals', 'anchor_3' => 'Partially committed to team goals', 'anchor_1' => 'Rarely committed'],
    ];

    foreach ($indicatorSpecs as $i => $spec) {
        $ind = new BarsIndicator;
        $ind->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => $spec['text']],
            'anchor_5' => ['en' => $spec['anchor_5']],
            'anchor_3' => ['en' => $spec['anchor_3']],
            'anchor_1' => ['en' => $spec['anchor_1']],
            'position' => $i,
        ]);
        $ind->save();
    }

    // Interview session with utterances that contain the verbatim excerpts from the cassette
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competency->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);

    $utterances = [
        'Candidate: I worked collaboratively on multiple projects.',
        "Candidate: Quello che abbiamo fatto è stato di cambiare le nostre abitudini e quindi di interfacciarci direttamente l'uno con l'altro.",
        'Candidate: è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi che avevamo in quel momento.',
    ];

    foreach ($utterances as $idx => $line) {
        [$speaker, $text] = explode(': ', $line, 2);
        $utt = new Utterance;
        $utt->forceFill([
            'organization_id' => $org->id,
            'interview_session_id' => $session->id,
            'speaker' => $speaker,
            'text' => $text,
            'ts' => now()->addSeconds($idx),
        ]);
        $utt->save();
    }

    return $competency;
}

/**
 * Build a cassette that returns COL {5,3,3} — same response for every call.
 *
 * Excerpts are verbatim substrings of the utterances created in detSetupCompetency().
 */
function detCassetteForCompetencyCode(string $code): CassetteLLMProvider
{
    $response = (string) json_encode([
        'behaviors' => [
            [
                'indicator' => 'Work effectively with others',
                'score' => 5,
                'explanation' => 'Strong collaboration evidence.',
                'excerpts' => ['I worked collaboratively on multiple projects'],
            ],
            [
                'indicator' => 'Willingly help colleagues in trouble',
                'score' => 3,
                'explanation' => 'Moderate evidence of helping colleagues.',
                'excerpts' => ['Quello che abbiamo fatto è stato di cambiare le nostre abitudini'],
            ],
            [
                'indicator' => 'Demonstrate commitment to team goals',
                'score' => 3,
                'explanation' => 'Some commitment to team goals.',
                'excerpts' => ['è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi'],
            ],
        ],
    ]);

    return new CassetteLLMProvider([$code => $response]);
}

/**
 * Build a cassette that returns residual levels {5,4,2} — same response for every call
 * (bars-full-scale-1-5, task 3.10). Uses the SAME fixed indicator/utterance shape as
 * detSetupCompetency(), so the excerpts below are verbatim substrings of those utterances.
 */
function detResidualCassetteForCompetencyCode(string $code): CassetteLLMProvider
{
    $response = (string) json_encode([
        'behaviors' => [
            [
                'indicator' => 'Work effectively with others',
                'score' => 5,
                'explanation' => 'Matches the Score 5 anchor: strong collaboration evidence.',
                'excerpts' => ['I worked collaboratively on multiple projects'],
            ],
            [
                'indicator' => 'Willingly help colleagues in trouble',
                'score' => 4,
                'explanation' => 'Clearly exceeds the Score 3 anchor but does not fully match the Score 5 anchor; a residual level between the two.',
                'excerpts' => ['Quello che abbiamo fatto è stato di cambiare le nostre abitudini'],
            ],
            [
                'indicator' => 'Demonstrate commitment to team goals',
                'score' => 2,
                'explanation' => 'Clearly below the Score 3 anchor but not as weak as the Score 1 anchor; a residual level between the two.',
                'excerpts' => ['è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi'],
            ],
        ],
    ]);

    return new CassetteLLMProvider([$code => $response]);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a–c) same input at temperature=0 → two runs produce identical scores, reliability, and excerpts', function (): void {
    $org = detOrg();

    // ── RUN 1 ─────────────────────────────────────────────────────────────────
    $project1 = detProject($org);
    $participant1 = detParticipant($org, $project1);
    $competency1 = detSetupCompetency($org, $project1, $participant1);

    app()->instance(LLMProvider::class, detCassetteForCompetencyCode($competency1->code));

    (new ScoreEvaluationJob($participant1->id))->handle();

    $eval1 = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant1->id)
        ->firstOrFail();

    $cr1 = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $eval1->id)
        ->where('competency_code', $competency1->code)
        ->firstOrFail();

    $scores1 = IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $cr1->id)
        ->orderBy('position')
        ->get()
        ->map(static fn ($is) => [
            'score' => $is->score,
            'excerpts' => $is->excerpts,
        ])
        ->all();

    // ── RUN 2 (fresh participant, same org, same data shape, same cassette) ──
    // Use the same org (same tenant scope) with a fresh project+participant so we get
    // an independent Evaluation row — proving two separate runs on identical inputs
    // produce identical outputs.

    // Reset TenantResolver for the second project creation
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project2 = detProject($org);
    $participant2 = detParticipant($org, $project2);
    $competency2 = detSetupCompetency($org, $project2, $participant2);

    app()->instance(LLMProvider::class, detCassetteForCompetencyCode($competency2->code));

    (new ScoreEvaluationJob($participant2->id))->handle();

    $eval2 = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant2->id)
        ->firstOrFail();

    $cr2 = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $eval2->id)
        ->where('competency_code', $competency2->code)
        ->firstOrFail();

    $scores2 = IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $cr2->id)
        ->orderBy('position')
        ->get()
        ->map(static fn ($is) => [
            'score' => $is->score,
            'excerpts' => $is->excerpts,
        ])
        ->all();

    // ── (a) Identical CompetencyResult scores ─────────────────────────────────
    expect($cr1->score)->toBe($cr2->score,
        'Determinism (a): two runs on same input must produce identical competency score.'
    );

    // ── (b) Identical reliability ─────────────────────────────────────────────
    expect($cr1->reliability)->toBe($cr2->reliability,
        'Determinism (b): two runs on same input must produce identical reliability.'
    );

    // ── (c) Identical IndicatorScore excerpts (all 3 indicators) ─────────────
    expect(count($scores1))->toBe(count($scores2),
        'Determinism (c): both runs must produce the same number of IndicatorScore rows.'
    );

    foreach ($scores1 as $i => $row1) {
        expect($row1['score'])->toBe($scores2[$i]['score'],
            "Determinism (c): indicator[$i] score must be identical across runs."
        );
        expect($row1['excerpts'])->toBe($scores2[$i]['excerpts'],
            "Determinism (c): indicator[$i] excerpts must be identical across runs."
        );
    }

    // Confirm expected values (COL {5,3,3} → 3.67, reliability 1.0)
    expect($cr1->score)->toBe(3.67, 'COL score must be 3.67 (mean of {5,3,3}).');
    expect($cr1->reliability)->toBe(1.0, 'COL reliability must be 1.0 (all 3 assessed).');
});

test('(d) determinism holds over residual score levels {5,4,2} (bars-full-scale-1-5)', function (): void {
    $org = detOrg();

    // ── RUN 1 ─────────────────────────────────────────────────────────────────
    $project1 = detProject($org);
    $participant1 = detParticipant($org, $project1);
    $competency1 = detSetupCompetency($org, $project1, $participant1);

    app()->instance(LLMProvider::class, detResidualCassetteForCompetencyCode($competency1->code));

    (new ScoreEvaluationJob($participant1->id))->handle();

    $eval1 = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant1->id)
        ->firstOrFail();

    $cr1 = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $eval1->id)
        ->where('competency_code', $competency1->code)
        ->firstOrFail();

    $scores1 = IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $cr1->id)
        ->orderBy('position')
        ->get()
        ->map(static fn ($is) => [
            'score' => $is->score,
            'excerpts' => $is->excerpts,
        ])
        ->all();

    // ── RUN 2 (fresh participant, same org, same cassette) ────────────────────
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project2 = detProject($org);
    $participant2 = detParticipant($org, $project2);
    $competency2 = detSetupCompetency($org, $project2, $participant2);

    app()->instance(LLMProvider::class, detResidualCassetteForCompetencyCode($competency2->code));

    (new ScoreEvaluationJob($participant2->id))->handle();

    $eval2 = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant2->id)
        ->firstOrFail();

    $cr2 = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $eval2->id)
        ->where('competency_code', $competency2->code)
        ->firstOrFail();

    $scores2 = IndicatorScore::withoutGlobalScopes()
        ->where('competency_result_id', $cr2->id)
        ->orderBy('position')
        ->get()
        ->map(static fn ($is) => [
            'score' => $is->score,
            'excerpts' => $is->excerpts,
        ])
        ->all();

    expect($cr1->score)->toBe($cr2->score,
        'Determinism (d): two runs over residual levels must produce identical competency score.'
    );
    expect($cr1->reliability)->toBe($cr2->reliability,
        'Determinism (d): two runs over residual levels must produce identical reliability.'
    );

    foreach ($scores1 as $i => $row1) {
        expect($row1['score'])->toBe($scores2[$i]['score'],
            "Determinism (d): indicator[$i] score must be identical across runs."
        );
        expect($row1['excerpts'])->toBe($scores2[$i]['excerpts'],
            "Determinism (d): indicator[$i] excerpts must be identical across runs."
        );
    }

    // Confirm the residual levels actually persisted (not silently coerced to anchors).
    expect($scores1[0]['score'])->toBe(5);
    expect($scores1[1]['score'])->toBe(4, 'Indicator[1] must persist the residual score 4, not be rejected or coerced.');
    expect($scores1[2]['score'])->toBe(2, 'Indicator[2] must persist the residual score 2, not be rejected or coerced.');

    // Confirm expected values (COL {5,4,2} → 3.67, reliability 1.0)
    expect($cr1->score)->toBe(3.67, 'COL score must be 3.67 (mean of {5,4,2}).');
    expect($cr1->reliability)->toBe(1.0, 'COL reliability must be 1.0 (all 3 assessed).');
});
