<?php

declare(strict_types=1);

/**
 * RED — Task 28.1 (framework-catalogue-authoring PR7, D8): `TurnClassifier`.
 *
 * An avatar turn is `primary` only when the NEXT unmatched entry of the
 * session's own `primary_questions` snapshot is the turn's OWN trailing
 * content, verbatim, under normalisation (casefold + whitespace-collapse +
 * trailing-punctuation-strip) — otherwise `follow_up`. Not a similarity
 * score, not a word list: an exact trailing match after normalisation, or
 * nothing. A trailing match rather than equality specifically so a `retry`
 * opening — which wraps the primary in a fixed apology template
 * (`OpeningTextComposer`, `interview.opening.retry_authored`) — still
 * matches; a genuinely different rewording, or a follow-up that merely
 * QUOTES the primary's wording without it being the turn's final question
 * (Z23, R3-turn-classifier-substring-false-primary), still does not.
 *
 * Uses the shared `cas*()` fixtures (`tests/Helpers/C9Fixtures.php`,
 * autoloaded) to satisfy `InterviewSession`'s required foreign keys.
 *
 * REQ: interview-conversation — "Primary And Follow-Up Turns Are
 * Distinguishable In Transcript/Telemetry".
 */

use App\Models\InterviewSession;
use App\Models\Utterance;
use App\Support\Interview\TurnClassifier;
use App\Support\Tenancy\TenantResolver;

/**
 * @param  list<string>  $primaryQuestions
 */
function turnClassifierSession(array $primaryQuestions): InterviewSession
{
    $org = casOrg();
    [$project, $comps] = casProject($org, 1);
    $participant = casParticipant($org, $project, 'in_corso');

    app(TenantResolver::class)->setOrgId($org->id);
    app(TenantResolver::class)->setBypass(false);

    return InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'framework_version_id' => $project->framework_version_id,
        'participant_id' => $participant->id,
        'competency_code' => $comps[0]->code,
        'status' => 'in_corso',
        'primary_questions' => $primaryQuestions,
        'follow_up_budget' => 4,
    ]);
}

/**
 * Persist an avatar turn already marked `primary` — simulates a turn a
 * previous classify() call, or an earlier request, already matched.
 */
function turnClassifierMarkMatched(InterviewSession $session, string $text): void
{
    $utterance = new Utterance;
    $utterance->forceFill([
        'interview_session_id' => $session->id,
        'provider_session_ref' => null,
        'speaker' => 'avatar',
        'text' => $text,
        'ts' => now(),
        'turn_kind' => 'primary',
    ]);
    $utterance->save();
}

test('an avatar turn matching the next unmatched primary, exactly, classifies as primary', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify($session, 'Tell me about a time you led a difficult project.');

    expect($result)->toBe('primary');
});

test('casefold + whitespace-collapse + trailing-punctuation-strip normalisation still matches', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify(
        $session,
        '  TELL me   about a time you LED a difficult   project  ',
    );

    expect($result)->toBe('primary');
});

test('trailing punctuation is stripped before comparison', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project']);

    $result = (new TurnClassifier)->classify(
        $session,
        'Tell me about a time you led a difficult project.',
    );

    expect($result)->toBe('primary');
});

/**
 * Z19 (R3, optional — done, cheap and safe): the trailing-punctuation strip
 * used to be a byte-wise `rtrim()`, unsafe on a multi-byte punctuation
 * character (`。`/`！`/`？`, each 3 UTF-8 bytes) — `rtrim()` strips
 * individual BYTES matching its mask, one at a time from the end, with no
 * concept of a character boundary. A CJK ideograph immediately BEFORE the
 * punctuation mark can share a byte VALUE with the mark's own bytes (both
 * are UTF-8 continuation bytes, 0x80-0xBF) — `rtrim` then keeps eating past
 * the punctuation mark and into the ideograph itself, and (empirically
 * confirmed, not assumed) `一。` (U+4E00 + U+3002) is exactly such a case:
 * the OLD `rtrim()` corrupted it to a truncated, invalid 3-byte sequence.
 *
 * Reflection reaches `normalize()` directly (private) — `classify()`'s own
 * CONTAINMENT check cannot observe this defect on its own: when the SAME
 * ideograph also ends the primary-question snapshot, `rtrim`'s trailing-byte
 * scan corrupts BOTH strings identically (neither has actual punctuation,
 * but `rtrim` never checks that — it strips whatever byte is last,
 * regardless of source), so the corrupted primary still matches the
 * corrupted turn and the classification result never changes. Only the RAW
 * BYTES prove the corruption.
 */
test('normalize() does not corrupt a CJK ideograph immediately before a multibyte trailing punctuation mark', function (): void {
    $normalize = new ReflectionMethod(TurnClassifier::class, 'normalize');
    $normalize->setAccessible(true);

    $result = $normalize->invoke(null, 'project一。');

    expect($result)->toBe('project一');
});

test('a genuinely reworded turn does not match — not a similarity score', function (): void {
    // Paraphrased throughout, so the primary's exact wording is not a
    // contiguous substring of this turn under any normalisation.
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify(
        $session,
        'What is an example where you had to steer something tough?',
    );

    expect($result)->toBe('follow_up');
});

/**
 * Z23 (R3-turn-classifier-substring-false-primary, REQUIRED BEFORE ARCHIVE):
 * this test used to also append " Take your time." AFTER the primary and
 * still expect `primary` — genuine plain containment, not just a prefix
 * wrapper. That is exactly the false-positive shape the fix above closes
 * (the primary must be the turn's own trailing content), so the trailing
 * filler is gone: this now proves the PREFIX-only wrapper the class's own
 * docblock actually documents (a leading preamble, primary as the final
 * question) still classifies as primary.
 */
test('the primary embedded verbatim at the end of a longer sentence still matches — a prefix wrapper, not equality', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify(
        $session,
        'So, to start: Tell me about a time you led a difficult project.',
    );

    expect($result)->toBe('primary');
});

test('a retry opening — the primary wrapped in the apology template — still classifies as primary', function (): void {
    // OpeningTextComposer's `retry` variant renders
    // "Sorry, we had a technical problem on our side. Let's start over. :question"
    // with `:question` replaced by the authored primary verbatim
    // (lang/en/interview.php `opening.retry_authored`) — this is the exact
    // shape a re-offered competency's opening turn takes.
    $session = turnClassifierSession(['Walk me through a time you handled a hostile client.']);

    $result = (new TurnClassifier)->classify(
        $session,
        "Sorry, we had a technical problem on our side. Let's start over. "
        .'Walk me through a time you handled a hostile client.',
    );

    expect($result)->toBe('primary');
});

/**
 * Z23 (R3-turn-classifier-substring-false-primary, framework-catalogue-
 * authoring, REQUIRED BEFORE ARCHIVE): plain substring CONTAINMENT matches
 * the next unmatched primary anywhere inside the turn, including in the
 * MIDDLE of a follow-up that only QUOTES it in passing — never actually
 * asking it — and then keeps talking about something else. The class's own
 * docblock already only ever describes the primary's exact text as embedded
 * "at the end" of a longer sentence (the retry-apology wrapper); this turn
 * has substantial NEW content trailing the quoted primary, so it is not the
 * turn's final question and must not classify as `primary`.
 */
test('a follow-up that quotes the next primary but keeps talking afterward does not match', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify(
        $session,
        'Before we wrap up, I should mention we may later ask: Tell me about a time you led a difficult project. '
        .'But first, what was the outcome of the situation you just described?',
    );

    expect($result)->toBe('follow_up');
});

test('a retry opening still matches when the primary is the turn\'s final question, tolerating the apology prefix', function (): void {
    $session = turnClassifierSession(['Tell me about a time you led a difficult project.']);

    $result = (new TurnClassifier)->classify(
        $session,
        "Sorry, we had a technical problem on our side. Let's start over. Tell me about a time you led a difficult project.",
    );

    expect($result)->toBe('primary');
});

test('a turn matching an EARLIER, already-matched primary does not re-match — next unmatched only', function (): void {
    $session = turnClassifierSession(['Primary one.', 'Primary two.']);
    turnClassifierMarkMatched($session, 'Primary one.');

    $result = (new TurnClassifier)->classify($session, 'Primary one.');

    expect($result)->toBe('follow_up');
});

test('a turn matching the next unmatched primary (primary 2) classifies as primary once primary 1 is already matched', function (): void {
    $session = turnClassifierSession(['Primary one.', 'Primary two.']);
    turnClassifierMarkMatched($session, 'Primary one.');

    $result = (new TurnClassifier)->classify($session, 'Primary two.');

    expect($result)->toBe('primary');
});

test('every primary already matched → follow_up, even for text that repeats a primary verbatim', function (): void {
    $session = turnClassifierSession(['Primary one.']);
    turnClassifierMarkMatched($session, 'Primary one.');

    $result = (new TurnClassifier)->classify($session, 'Primary one.');

    expect($result)->toBe('follow_up');
});

test('no primary_questions snapshot at all → follow_up', function (): void {
    $session = turnClassifierSession([]);

    $result = (new TurnClassifier)->classify($session, 'Anything at all.');

    expect($result)->toBe('follow_up');
});
