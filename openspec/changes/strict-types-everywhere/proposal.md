# Proposal: Strict Types Everywhere

## Intent

`declare(strict_types=1);` is the near-universal convention in this codebase — 507 of
516 PHP files under `app/` already carry it. 9 do not, and none of the 9 is an
accidental omission of a throwaway file: they include the User model, the tenant-scoping
middleware, the security-headers middleware, and the main app service provider — exactly
the kind of file where an implicit type coercion at a boundary is most expensive to debug
later. Strict typing is free (no runtime behavior change for correctly-typed call sites)
and closes off an entire class of "it worked because PHP coerced '5' to 5" bugs.

## Verified current state

| Claim | Evidence |
|---|---|
| 9 files under `app/` lack `declare(strict_types=1)` | `rg -L 'declare\(strict_types=1\)' app -g '*.php' --files-without-match` (re-run at the start of this change, list unchanged from the original audit) |
| The 9 files | `app/Console/Commands/CreateSuperadmin.php`, `app/Providers/AppServiceProvider.php`, `app/DTOs/LLMResponse.php`, `app/Contracts/LLMProvider.php`, `app/Testing/FakeLLMProvider.php`, `app/Models/User.php`, `app/Http/Controllers/Controller.php`, `app/Http/Middleware/TenantContext.php`, `app/Http/Middleware/SecurityHeaders.php` |
| No existing arch test guards this convention | `rg -l "strict_types" tests/Arch` — no matches before this change |
| PHPStan is already at level 8 with 0 errors | `phpstan.neon`; `vendor/bin/phpstan analyse` clean on `develop` — strict types should not surface new type errors PHPStan wasn't already catching at the static-analysis level, but it DOES change runtime behavior for implicit coercions PHPStan's inference didn't already forbid |

## Approach

Add `declare(strict_types=1);` to each of the 9 files, in the same position every other
file in this codebase uses it (`<?php`, blank line, `declare(strict_types=1);`, blank
line, `namespace ...;`). Add one Pest arch test, matching this repo's established
glob+`file_get_contents`+`str_contains` arch-test convention (no `pest-plugin-arch`
dependency — see `tests/Arch/ScoringFormulaIsolationTest.php`'s own docblock), asserting
every file under `app/` carries the declaration, so a new file added later without it
fails CI instead of silently joining the minority.

## Out of scope

- `tests/`, `database/`, `config/`, `routes/` — this change touches `app/` only, matching
  the audit finding's own scope. A broader repo-wide sweep is a separate decision.
- Any behavior change beyond what strict typing itself causes. If strict typing surfaces
  a genuine implicit-coercion bug at a call site into one of these 9 files, that is
  logged as a finding, not silently patched around, and reported for a separate decision
  — this change's job is the declaration, not incidental bug-hunting.
