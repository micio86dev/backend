# Tasks: Split InterviewController (partial)

> Strict TDD active. Test runner: `php -d memory_limit=2G artisan test --coverage --min=85`.
> Isolated worktree — a dedicated local test database
> (`beai_test_interview_split`, phpunit.xml `DB_DATABASE` pointed at it locally, NOT
> committed) was required: the shared `beai_test` database is used concurrently by
> sibling worktrees running their own suites in parallel, causing deadlocks/duplicate
> tables. `phpunit.xml` is reverted to `beai_test` before the final commit.

## 0. Baseline

- [x] 0.1 `tests/Feature/C7a` + `tests/Feature/Interview` against the dedicated test
      DB: **256/256 passed, 774 assertions**, before any edit.

## 1. `BuildInterviewSessionResponse` (design.md D1)

- [x] 1.1 RED — `tests/Unit/Actions/Interview/BuildInterviewSessionResponseTest.php`:
      the 201 shape, completion-phrase fallback, audio_only true/false. Confirmed
      failing on "class not found" before creating the class.
- [x] 1.2 GREEN — class created, three method bodies moved verbatim.
- [x] 1.3 Wired into `InterviewController`: constructor-injected; both
      `buildSuccessResponse(...)` call sites became
      `$this->buildSessionResponse->handle(...)`.
- [x] 1.3b **Correction found by PHPStan, not anticipated in design.md**: a THIRD,
      separate call site (`start()`'s prompt-composition flow, line ~368) calls
      `resolveCompletionPhrases()` directly — for the avatar's SPOKEN closing
      phrase, not the response body. `phpstan analyse` caught the dangling private
      method reference after the controller methods were deleted. Fixed by making
      `resolveCompletionPhrases()` PUBLIC on the new class (documented why in its
      own docblock) and pointing that third call site at
      `$this->buildSessionResponse->resolveCompletionPhrases(...)` — one object,
      both callers, phrases can't drift apart between what the avatar says and
      what the response body advertises.
- [x] 1.4 16/16 new unit tests green; feature-level confirmation folded into task
      3.3's full rerun (below) rather than a separate pass.

## 2. `ResolveInterviewDirective` (design.md D2)

- [x] 2.1 RED — 5 cases (done/pause/continue, the done-before-pause ordering on a
      shared modulus boundary, null-pause fail-closed). Confirmed failing first.
- [x] 2.2 GREEN — class created, body moved verbatim.
- [x] 2.3 Wired into `InterviewController`: constructor-injected, one call site
      inside `end()`'s transaction replaced, method deleted.
- [x] 2.4 12/12 new unit tests green (includes the pre-existing DB-free
      `SettleParticipantCompletionTest` in the same directory, unaffected).

## 3. Verification

- [x] 3.1 `vendor/bin/pint --dirty --format agent` — passed (auto-fixed 2 minor
      import-ordering issues in the new test files, harmless).
- [x] 3.2 `vendor/bin/phpstan analyse --memory-limit=1G` — after the 1.3b fix,
      **0 errors from this change**. 2 unrelated pre-existing errors remain
      (`SendPasswordResetLinkJob.php`/`SendUserInvitationJob.php`,
      `PasswordBroker::createToken()`) — confirmed via `git diff develop` that
      neither file is touched by this change; they exist on `develop` already.
- [x] 3.3 Full suite + coverage: **4464 tests, 4457 passed, 0 failed, 7 skipped**
      (pre-existing/environment-dependent — same 7 as the sibling
      `webhook-ssrf-guard` change's own full run), 14272 assertions. **Coverage
      94.9% overall** (floor 85%, held — identical to the pre-change baseline
      figure). `ResolveInterviewDirective`: 100% (absent from the per-file
      uncovered-lines listing). `BuildInterviewSessionResponse`: 93.1% (2 lines
      uncovered — not chased further; already above both the repo floor and the
      correctness-critical-zone target).
- [x] 3.4 Reverted — `git checkout -- phpunit.xml` confirmed clean (`git diff
      --stat` empty).
- [x] 3.5 Controller: 2092 → 1907 lines (**-185**, more than the ~115 estimate —
      docblocks moved with their methods).
- [ ] 3.6 Commit as one work unit on `feature/split-interview-controller`.
      Conventional Commit, no AI attribution. Expect the blocking `gga run`
      pre-commit review; treat its findings as credible (per this session's prior
      experience on the sibling `webhook-ssrf-guard` change, where it caught two
      real defects).
