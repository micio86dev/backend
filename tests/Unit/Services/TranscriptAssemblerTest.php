<?php

declare(strict_types=1);

/**
 * TranscriptAssembler (C9 D3 CW3, split corpora — evaluator-evidence-and-rigor D-1/D-2/D-3).
 *
 * Verifies:
 * (a) Utterances ordered by ts then id; prompt corpus format "{speaker}: {text}".
 * (b) Timestamp tie: id=42 before id=43 (HeyGen bulk-replace produces equal ts).
 * (c) The prompt corpus spans EVERY session of the participant, not just the target.
 * (d) Sessions ordered by session id.
 * (e) The target competency's segment is delimited; the others are not.
 * (f) Moving the target moves only the markers.
 * (g) The validation corpus excludes the interviewer entirely.
 * (h) The validation corpus carries no marker text.
 * (i) Subset invariant.
 * (j) Single-session participant.
 * (k) No utterances → both corpora empty.
 *
 * Refs spec: D3 CW3 ordering; D-1 subset invariant; D-2 session ordering; D-3 markers.
 */

use App\DTOs\Scoring\ScoringCorpora;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\Utterance;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function assemblerOrg(): Organization
{
    return Organization::factory()->create();
}

function assemblerProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active']);
}

function assemblerParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'ta-test-'.uniqid(),
        'display_name' => 'TA Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

function assemblerSession(Organization $org, Project $project, Participant $participant, string $competencyCode = 'COL'): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $competencyCode,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'status' => 'completed',
    ]);
}

/**
 * Speaker values are LOWERCASE in production: `UtteranceController` validates
 * `in:candidate,avatar` at the candidate ingress, and `HeygenProvider` maps
 * provider roles through a `match` that THROWS on anything unrecognized. The
 * candidate-only filter depends on that contract, so these fixtures honour it.
 */
function assemblerUtterance(Organization $org, InterviewSession $session, string $speaker, string $text, string $ts): Utterance
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $u = new Utterance;
    $u->forceFill([
        'organization_id' => $org->id,
        'interview_session_id' => $session->id,
        'speaker' => $speaker,
        'text' => $text,
        'ts' => $ts,
    ]);
    $u->save();

    return $u;
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) utterances ordered by ts then id; prompt corpus serialized as "{speaker}: {text}"', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant);

    // Inserted out of order intentionally; ts ordering must reorder them.
    assemblerUtterance($org, $session, 'avatar', 'Third utterance', '2024-01-01 10:00:03');
    assemblerUtterance($org, $session, 'candidate', 'First utterance', '2024-01-01 10:00:01');
    assemblerUtterance($org, $session, 'candidate', 'Second utterance', '2024-01-01 10:00:02');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora)->toBeInstanceOf(ScoringCorpora::class);
    expect($corpora->prompt)->toContain(
        "candidate: First utterance\ncandidate: Second utterance\navatar: Third utterance"
    );
});

test('(b) timestamp tie: lower id comes before higher id', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COM');

    $sameTs = '2024-01-01 10:00:00';
    $first = assemblerUtterance($org, $session, 'avatar', 'Earlier id', $sameTs);
    $second = assemblerUtterance($org, $session, 'candidate', 'Later id', $sameTs);

    expect($second->id)->toBeGreaterThan($first->id);

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COM');

    expect($corpora->prompt)->toContain("avatar: Earlier id\ncandidate: Later id");
});

test('(c) task 9.1 — the prompt corpus spans EVERY session of the participant', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    $col = assemblerSession($org, $project, $participant, 'COL');
    $drv = assemblerSession($org, $project, $participant, 'DRV');
    $com = assemblerSession($org, $project, $participant, 'COM');

    assemblerUtterance($org, $col, 'candidate', 'Answer about collaboration', '2024-01-01 10:00:00');
    assemblerUtterance($org, $drv, 'candidate', 'Answer about drive', '2024-01-01 10:01:00');
    assemblerUtterance($org, $com, 'candidate', 'Answer about communication', '2024-01-01 10:02:00');

    // Scoring COL — evidence given while answering DRV and COM must still be visible.
    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->prompt)->toContain('Answer about collaboration')
        ->and($corpora->prompt)->toContain('Answer about drive')
        ->and($corpora->prompt)->toContain('Answer about communication');
});

test('(d) task 9.2 — sessions are ordered by session id', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    $first = assemblerSession($org, $project, $participant, 'COL');
    $second = assemblerSession($org, $project, $participant, 'DRV');

    // Later session's utterance carries an EARLIER ts, so a ts-driven global sort
    // would interleave the two interviews. Session id must win at the outer level.
    assemblerUtterance($org, $first, 'candidate', 'Spoken in session one', '2024-01-01 10:05:00');
    assemblerUtterance($org, $second, 'candidate', 'Spoken in session two', '2024-01-01 10:00:00');

    expect($second->id)->toBeGreaterThan($first->id);

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect(strpos($corpora->prompt, 'Spoken in session one'))
        ->toBeLessThan(strpos($corpora->prompt, 'Spoken in session two'));
});

test('(e) task 9.3 — the target segment is delimited, the others are not', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    $col = assemblerSession($org, $project, $participant, 'COL');
    $drv = assemblerSession($org, $project, $participant, 'DRV');

    assemblerUtterance($org, $col, 'candidate', 'Collaboration answer', '2024-01-01 10:00:00');
    assemblerUtterance($org, $drv, 'candidate', 'Drive answer', '2024-01-01 10:01:00');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->prompt)->toContain('TARGET COMPETENCY COL')
        ->and($corpora->prompt)->not->toContain('TARGET COMPETENCY DRV');

    // The COL answer sits INSIDE the markers; the DRV answer does not.
    $begin = strpos($corpora->prompt, 'PRIMARY EVIDENCE BEGINS');
    $end = strpos($corpora->prompt, 'PRIMARY EVIDENCE ENDS');
    $colAt = strpos($corpora->prompt, 'Collaboration answer');
    $drvAt = strpos($corpora->prompt, 'Drive answer');

    expect($colAt)->toBeGreaterThan($begin)->toBeLessThan($end);
    expect($drvAt)->toBeGreaterThan($end);
});

test('(f) task 9.4 — retargeting moves the markers and leaves the text otherwise identical', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    $col = assemblerSession($org, $project, $participant, 'COL');
    $drv = assemblerSession($org, $project, $participant, 'DRV');

    assemblerUtterance($org, $col, 'candidate', 'Collaboration answer', '2024-01-01 10:00:00');
    assemblerUtterance($org, $drv, 'candidate', 'Drive answer', '2024-01-01 10:01:00');

    $assembler = new TranscriptAssembler;
    $forCol = $assembler->assembleForParticipant($participant->id, 'COL');
    $forDrv = $assembler->assembleForParticipant($participant->id, 'DRV');

    expect($forDrv->prompt)->toContain('TARGET COMPETENCY DRV')
        ->and($forDrv->prompt)->not->toContain('TARGET COMPETENCY COL');

    // Strip the marker lines from both: what remains must be identical.
    $strip = static fn (string $s): string => implode("\n", array_filter(
        explode("\n", $s),
        static fn (string $line): bool => ! str_starts_with($line, '=== TARGET COMPETENCY'),
    ));

    expect($strip($forCol->prompt))->toBe($strip($forDrv->prompt));

    // The validation corpus carries no markers at all, so it is identical outright.
    expect($forCol->validation)->toBe($forDrv->validation);
});

test('(g) task 9.5 — the validation corpus excludes the interviewer entirely', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COL');

    assemblerUtterance($org, $session, 'avatar', 'Tell me about a time you overruled your team', '2024-01-01 10:00:00');
    assemblerUtterance($org, $session, 'candidate', 'I overruled my team on the vendor choice', '2024-01-01 10:00:01');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    // The interviewer's question is context for the model...
    expect($corpora->prompt)->toContain('Tell me about a time you overruled your team');

    // ...but it is NOT evidence the candidate did anything.
    expect($corpora->validation)->toContain('I overruled my team on the vendor choice')
        ->and($corpora->validation)->not->toContain('Tell me about a time you overruled your team');
});

test('(h) task 9.6 — the validation corpus carries no marker text', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COL');

    assemblerUtterance($org, $session, 'candidate', 'Some answer', '2024-01-01 10:00:00');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->validation)->not->toContain('TARGET COMPETENCY')
        ->and($corpora->validation)->not->toContain('PRIMARY EVIDENCE')
        ->and($corpora->validation)->not->toContain('candidate:');
});

test('(i) task 9.7 — subset invariant: every candidate utterance in validation is in prompt', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    $col = assemblerSession($org, $project, $participant, 'COL');
    $drv = assemblerSession($org, $project, $participant, 'DRV');

    $texts = ['First candidate line', 'Second candidate line', 'Third candidate line'];
    assemblerUtterance($org, $col, 'candidate', $texts[0], '2024-01-01 10:00:00');
    assemblerUtterance($org, $col, 'avatar', 'An interviewer question', '2024-01-01 10:00:01');
    assemblerUtterance($org, $drv, 'candidate', $texts[1], '2024-01-01 10:01:00');
    assemblerUtterance($org, $drv, 'candidate', $texts[2], '2024-01-01 10:01:01');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    foreach ($texts as $text) {
        expect($corpora->validation)->toContain($text);
        expect($corpora->prompt)->toContain($text);
    }
});

test('(j) task 9.8 — single-session participant: the whole corpus is the delimited target', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COL');

    assemblerUtterance($org, $session, 'avatar', 'Question one', '2024-01-01 10:00:00');
    assemblerUtterance($org, $session, 'candidate', 'Answer one', '2024-01-01 10:00:01');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    $lines = explode("\n", $corpora->prompt);

    expect($lines[0])->toStartWith('=== TARGET COMPETENCY COL')
        ->and($lines[array_key_last($lines)])->toStartWith('=== TARGET COMPETENCY COL');
    expect($corpora->prompt)->toContain("avatar: Question one\ncandidate: Answer one");
    expect($corpora->validation)->toBe('Answer one');
});

test('(k) task 9.9 — a participant with no utterances yields two empty corpora, no exception', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    assemblerSession($org, $project, $participant, 'COL');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->prompt)->toBe('')
        ->and($corpora->validation)->toBe('');
});

test('(l) an empty TARGET session among non-empty siblings emits no markers', function (): void {
    // There is no target segment to delimit. Claiming one with empty markers
    // would tell the model a primary-evidence block exists when it does not.
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);

    assemblerSession($org, $project, $participant, 'COL'); // target, empty
    $drv = assemblerSession($org, $project, $participant, 'DRV');
    assemblerUtterance($org, $drv, 'candidate', 'Drive answer', '2024-01-01 10:00:00');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->prompt)->toContain('Drive answer')
        ->and($corpora->prompt)->not->toContain('TARGET COMPETENCY');
    expect($corpora->validation)->toBe('Drive answer');
});

test('(m) another participant\'s utterances never leak into the corpora', function (): void {
    $org = assemblerOrg();
    $project = assemblerProject($org);

    $mine = assemblerParticipant($org, $project);
    $theirs = assemblerParticipant($org, $project);

    $mySession = assemblerSession($org, $project, $mine, 'COL');
    $theirSession = assemblerSession($org, $project, $theirs, 'COL');

    assemblerUtterance($org, $mySession, 'candidate', 'My own answer', '2024-01-01 10:00:00');
    assemblerUtterance($org, $theirSession, 'candidate', 'Somebody else answer', '2024-01-01 10:00:00');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($mine->id, 'COL');

    expect($corpora->prompt)->toContain('My own answer')
        ->and($corpora->prompt)->not->toContain('Somebody else answer');
    expect($corpora->validation)->toBe('My own answer');
});

test('(n) a capitalized speaker is still recognized as the candidate', function (): void {
    // Both current write paths normalise to lowercase, so nothing writes this
    // today. Historic rows are the risk: a case-sensitive filter would drop a
    // stale "Candidate" from the validation corpus, every excerpt would then
    // fail verbatim validation, and the participant would score -1 across the
    // board with nothing saying why. Case-folding cannot confuse two speakers;
    // case-sensitivity can lose an entire interview. Not symmetric.
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COL');

    assemblerUtterance($org, $session, 'Candidate', 'Legacy capitalized answer', '2024-01-01 10:00:00');
    assemblerUtterance($org, $session, 'Avatar', 'Legacy capitalized question', '2024-01-01 10:00:01');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->validation)->toBe('Legacy capitalized answer');
});

test('(o) a speaker that is neither candidate nor avatar is excluded from validation', function (): void {
    // Degrades to a visible per-indicator excerpt_unverifiable, never a silent
    // wrong score.
    $org = assemblerOrg();
    $project = assemblerProject($org);
    $participant = assemblerParticipant($org, $project);
    $session = assemblerSession($org, $project, $participant, 'COL');

    assemblerUtterance($org, $session, 'system', 'A system notice', '2024-01-01 10:00:00');
    assemblerUtterance($org, $session, 'candidate', 'A real answer', '2024-01-01 10:00:01');

    $corpora = (new TranscriptAssembler)->assembleForParticipant($participant->id, 'COL');

    expect($corpora->validation)->toBe('A real answer');
    // Still visible to the model as context, just not citable as candidate evidence.
    expect($corpora->prompt)->toContain('system: A system notice');
});
