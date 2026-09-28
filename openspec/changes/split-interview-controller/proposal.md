# Proposal: Split InterviewController (partial)

## Intent

`app/Http/Controllers/Candidate/InterviewController.php` is 2092 lines and sits in
BEAI's candidate-state-machine correctness-critical zone (~95% coverage target,
CLAUDE.md). Too large for safe review/change as a whole. This change extracts two
clean, low-risk, single-responsibility collaborators — it does NOT attempt a full
decomposition.

## Verified current state

The controller has 3 public endpoints (`start`, `suspend`, `end`) and 22 private
helpers. Read in full before scoping this change. Most of the private surface —
`createOrResumeSession`, `handleResumeInCorso`, `handleIssuePending`,
`handleProviderFailure`, and the utterance-persistence group
(`insertUtterances`/`replaceUtterances`/`replaceUtteranceStretch`) — carries dense,
load-bearing documentation about transaction boundaries, row locking order, and
teardown-compensation coupling (e.g. `replaceUtteranceStretch()`'s docblock: "MUST run
inside an existing transaction... this is a cheap, harmless re-acquire within the SAME
transaction there"). Moving that code confidently, with a genuine zero-behavior-change
guarantee, needs more time/scrutiny than this change's scope allows. Extracting it
carelessly is exactly how this kind of invariant breaks silently.

Two groups are safe:

1. **Response building** (`buildSuccessResponse`, `resolveCompletionPhrases`,
   `resolveAudioOnly`, lines 1823-1936) — pure computation from already-resolved
   inputs. No DB writes, no transaction coupling. Two call sites, both inside
   `start()`, passing the identical argument shape.
2. **Pause/completion directive** (`buildDirective`, lines 817-834) — a single
   read-only helper (two `SELECT`s via `CompetencyTally` + `Project::whereKey`), one
   call site, inside `end()`.

## Approach

Move each group verbatim into a new `final` class under `App\Actions\Interview\*`,
matching this file's own existing precedent: `settleCompletionIfFinished()` is already
a one-line delegator to `App\Actions\Interview\SettleParticipantCompletion` (a prior
extraction from this same controller). The controller keeps thin delegating methods
where a call site's existing method name is worth preserving for readability, or
calls the new Action directly where that reads at least as clearly.

## Out of scope (accepted, not silently dropped)

- The `createOrResumeSession`/`handleResumeInCorso`/`handleIssuePending`/
  `handleProviderFailure` orchestration core (~450 lines) — the actual state-machine
  logic. Highest risk, highest value, and needs its own dedicated change with more
  scrutiny than a "1-2 collaborators" pass allows.
- The utterance-persistence group — transaction/locking invariants documented in the
  code itself as fragile to reordering; same reasoning.
- `buildDirective`'s sibling `composePromptForCompetency` (adjacent, ~80 lines) — its
  docblock documents subtle caller-ordering dependencies (composed BEFORE
  `createOrResumeSession()`/`issue()`) that are safer left alone until the orchestration
  core itself is tackled.

This is intentionally a small, low-risk PR that shrinks the file by ~115 lines and
establishes two new tested collaborators, not a rewrite.
