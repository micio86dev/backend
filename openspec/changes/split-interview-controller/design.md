# Design: Split InterviewController (partial)

## D1 — `App\Actions\Interview\BuildInterviewSessionResponse`

Moves `buildSuccessResponse()`, `resolveCompletionPhrases()`, and `resolveAudioOnly()`
verbatim (bodies unchanged) into a new stateless `final class`:

```php
final class BuildInterviewSessionResponse
{
    public function handle(
        InterviewSession $session,
        ProviderToken $token,
        ?string $language,
        ?string $promptVersion = null,
        ?int $competencyOrdinal = null,
        ?int $totalCompetencies = null,
    ): JsonResponse;

    private function resolveCompletionPhrases(?string $language): array;
    private function resolveAudioOnly(InterviewSession $session): bool;
}
```

No constructor dependencies — every call inside is a static facade (`Lang::get`,
`config()`, `response()`) or an Eloquent static (`Project::whereKey`,
`AvatarTemplate::whereKey`). The controller's two call sites become
`$this->buildSessionResponse->handle(...)` (constructor-injected, matching the
`SettleParticipantCompletion` precedent).

`start()`'s own `@scramble-return` annotation already spells out the exact OpenAPI
response shape by hand (its docblock explains why: Scramble cannot follow a private
helper whose fields come from further method calls). That annotation is untouched —
this move does not change what Scramble emits.

## D2 — `App\Actions\Interview\ResolveInterviewDirective`

Moves `buildDirective()` verbatim:

```php
final class ResolveInterviewDirective
{
    /** @return array{ended_competencies: int, total_competencies: int, next_action: string} */
    public function handle(int $participantId, int $projectId): array;
}
```

Single call site, inside `end()`'s explicit DB transaction (comment at the call site:
"(D7) The directive, computed HERE — inside the transaction, so the numbers it
reports and the completion just settled cannot disagree"). The method is read-only
(two `SELECT`s via `CompetencyTally` + `Project::whereKey`) — moving it to an
injected, constructor-resolved Action changes nothing about transaction membership;
Laravel resolves the constructor dependency once per request, and the method itself
opens no transaction of its own.

## Why these two and not more

See proposal.md's "Out of scope". In short: everything else touching provider
sessions, utterance persistence, or the resume/issue orchestration carries
in-code-documented transaction/locking/teardown invariants dense enough that a
confident zero-behavior-change move needs its own dedicated, more heavily-scrutinized
change — not a "grab two more while I'm in here" addition to this one.

## Verification

- Baseline: full existing test suite touching `/candidate/interview/{start,suspend,end}`
  (31 test files across `C7a`, `C8`, `C9`, `C10`, `C11`, `Interview`, and others) green
  before any edit.
- Each new Action gets a focused unit test (`tests/Unit/Actions/Interview/*`).
- Existing feature tests must pass with ZERO assertion changes — only mechanical
  construction changes are acceptable (e.g. a test that constructs
  `InterviewController` directly, if any, gaining a new constructor arg).
- Full suite + coverage rerun after the move.
