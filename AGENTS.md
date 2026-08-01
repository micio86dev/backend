# Code Review Rules — BEAI api (Laravel 13 / PHP 8.5)

Concrete, checkable rules. Every one of these exists because the mistake was actually
made in this codebase, not because it is good practice in the abstract.

## Multi-tenancy — the highest-severity category

- **Check the base class before applying any tenancy pattern.** `Project`, `Evaluation`,
  `CompetencyResult`, `IndicatorScore`, `InterviewSession`, `IntegrityEvent`, `AiRequest`,
  `InterviewSnapshot` and `Utterance` extend `TenantModel` and carry a global scope.
  **`Participant` and `User` do NOT** — they extend plain `Model` / `Authenticatable`.
- **Never `findOrFail()` / `find()` / `where()` a `Participant` or `User` without an explicit
  `organization_id` filter.** Without the filter it returns another organisation's row with a
  200. The safe precedent is `M2m/ParticipantController` (`->where('organization_id', $orgId)`).
- **`User::role('admin')->get()` inside a job returns EVERY tenant's users.** Spatie's `role()`
  scopes the pivot, not the `users` table.
- **Every queued job that writes tenant-scoped rows must open
  `App\Support\Tenancy\TenantContextScope::runFor($orgId, ...)`.** `Queue::before` nulls the
  ambient resolver for every job, so ambient context is never available in a worker.
- **Never use `withoutGlobalScopes()` in HTTP-context code.** It is correct only inside a
  queued job that has established context. In a controller it removes tenant isolation.
- **`withoutGlobalScopes()` does NOT bypass model events.** The `creating` listener still
  fires and still stamps `organization_id`.

## Secrets

- **`projects.webhook_secret` must never appear in a persisted row, a log line, an API
  response, or an exception message.** An exception carrying it ends up in error tracking.
- It is `encrypted` + `$hidden`, so only the Eloquent path decrypts it — a raw query returns
  ciphertext.
- **Never commit a `.env` into an image.** The `.dockerignore` exists for this.

## BARS scoring — domain correctness

- **Indicator scores are the discrete set `{1, 3, 5}`.** Never 2, never 4, never a decimal.
- **`-1` means UNASSESSABLE.** It is not a score: exclude it from the competency mean, never
  render it as a number, never place it on an error/warning/success scale.
- **A competency whose indicators are all unassessable has no mean** — never `0`.
- **Excerpts must be verbatim from the transcript**, validated by substring. Never reworded.
- **Serialize scores with `JSON_PRESERVE_ZERO_FRACTION`.** Without it `json_encode(3.0)`
  emits `3`, and a BARS mean is a decimal by definition.

## Queue and jobs

- **Every `ShouldQueue` class declares its own `$timeout` and `$tries`.** Without `$timeout`
  the framework default of 60 s applies, which kills a scoring job nine minutes inside its
  own stated budget.
- **Never pass `--tries` at worker level** — but know why, because the obvious reason is wrong.
  A worker-level `--tries` does NOT override a job that declares its own: `maxTries` is baked
  into the payload at dispatch (`Illuminate/Queue/Queue.php:176`) and the job's value always
  wins (`Illuminate/Queue/Worker.php:639` and `:667`). The real exposure is a job that declares
  **nothing** — a null payload `maxTries` does fall through to the worker's value. That is what
  `tests/Arch/Queue/QueuedJobRetryOwnershipArchTest.php` structurally prevents, and omitting the
  option on the wrapper additionally stops an operator's `beai:queue-work --tries=N` from being
  silently forwarded.
- **`job $timeout < worker --timeout < retry_after`**, and the scoring timeout must also clear
  the declared latency ceiling. Satisfying the ordering by shrinking every number toward zero
  is not a fix.
- **A job's payload carries scalar IDs only** — never a serialized model, never a secret.

## Webhooks

- **Sign the exact bytes you transmit.** `Http::post($url, $array)` re-encodes the body and
  breaks every receiver's signature check. Use one `json_encode` result, signed and sent via
  `withBody()`.
- **Do not weaken the raw-SQL atomicity at the SSO seam.** It is deliberate TOCTOU safety;
  duplicates are absorbed by the dedupe index instead.

## Tests

- **A test that has never been seen to fail is not evidence.** For any guard, assertion or
  arch rule, the reviewable question is: what mutation would make this fail?
- **Assert on the row the code actually wrote**, not on a different row's absence.
- **Assert specific failure modes**, not bare exception classes — `toThrow(QueryException::class)`
  passes against a missing table just as happily as against a constraint violation.
- **Helpers used by more than one test file MUST live in `tests/Helpers/` and be registered in
  `composer.json` `autoload-dev.files`.** CI runs `php artisan test --parallel`, and ParaTest
  distributes files across workers: a helper defined inside a test file is undefined in a
  worker that did not receive it.
- **Never call `handle()` directly** when the test needs production-realistic behaviour — that
  skips `Queue::before` and every other queue hook.
- Never weaken an assertion to restore green.

## Gates

- PHPStan runs at max level with a **0-error baseline**. Any error is new work's.
- `./vendor/bin/pint` must be **scoped to touched files** — never bare, it reformats the repo.
- The CI gate is `php artisan test --parallel`, not the sequential run.
