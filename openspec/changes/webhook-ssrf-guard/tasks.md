# Tasks: Webhook SSRF Guard

> Strict TDD active. Test runner: `php -d memory_limit=2G artisan test --coverage --min=85`
> (mirrors CI). This is one PR, well under the 400-line budget — no chaining needed.
> Correctness-critical zone (design.md D1's IP-classification logic) targets ~95%
> coverage in isolation; the change as a whole holds the repo's 85% floor.

## 1. `OutboundHostGuard` (design.md D1)

- [x] 1.1 RED — `tests/Unit/C10/OutboundHostGuardTest.php` (matches this slice's flat
      convention — `RetryClassifierTest.php`, `SecretRedactorTest.php`,
      `WebhookSignerTest.php` all live directly under `tests/Unit/C10/`, not mirrored
      under `Services/Webhooks/`): non-https
      scheme blocked; loopback (`127.0.0.1`) blocked; link-local/metadata
      (`169.254.169.254`) blocked; RFC1918 (`10.0.0.1`, `172.16.0.1`, `192.168.1.1`)
      blocked; a public IP literal (`8.8.8.8`) allowed; an unresolvable hostname
      (`example.test`) allowed; a malformed URL (no host) blocked.
- [x] 1.2 GREEN — implement `App\Services\Webhooks\OutboundHostGuard::isBlocked()` per
      design.md D1.
- [x] 1.3 REFACTOR — none needed; 11/11 green as written.

## 2. `SafeWebhookUrl` validation rule (design.md D2)

- [x] 2.1 RED — `tests/Unit/Rules/SafeWebhookUrlTest.php`: a blocked-per-guard URL fails
      with message `webhook_url_unsafe`; an allowed URL passes. Calls `validate()`
      directly (not via the `Validator` facade — this directory isn't wired to boot the
      app container, unlike `Unit/Rules/PublicApi`; no Pest.php change needed).
- [x] 2.2 GREEN — implement `App\Rules\SafeWebhookUrl implements ValidationRule`.

## 3. Wire the rule into the three FormRequests

- [x] 3.1 RED — extended `tests/Feature/C4/ProjectCrudTest.php`'s existing
      invalid-payload dataset with a loopback URL, the cloud-metadata URL, and a
      non-https scheme; both POST and PATCH already loop this dataset.
- [x] 3.2 RED — added a dedicated data-driven test to
      `tests/Feature/OrganizationSettings/WebhookDefaultsTest.php` (loopback,
      metadata, non-https).
- [x] 3.3 GREEN — added `new SafeWebhookUrl` to `webhook_url` in
      `StoreProjectRequest::rules()`/`UpdateProjectRequest::rules()` and to
      `default_webhook_url` in `UpdateOrganizationRequest::rules()`.
- [x] 3.4 CORRECTION to the plan as written: a `messages()` entry WAS needed.
      `ProjectCrudTest.php`'s "EVERY declared rule ... carries a code" test keys by
      `{field}.{lowercased class basename}` for object rules too, not just built-ins —
      added `'webhook_url.safewebhookurl' => 'webhook_url_unsafe'` to
      `ValidatesProjectComposition::messages()`. Confirmed empirically (ran the actual
      HTTP request) that Laravel's `ValidationRule::validate()` `$fail()` message is
      used verbatim regardless of this entry — it exists solely to satisfy that one
      completeness guard, not to produce the message.
- [x] 3.5 Confirmed — `tests/Feature/OrganizationSettings`, `tests/Feature/C4`,
      `tests/Unit/C10`, `tests/Unit/Rules`: 274/274 passed, 668 assertions, 0
      regressions. Every existing `*.test`/`*.example`/`*.invalid` fixture still
      passes (unresolvable → allowed per design.md).

## 4. Send-time re-check in `DeliverWebhookJob` (design.md D3)

- [x] 4.1 RED — added to `tests/Feature/C10/DeliverWebhookJobTest.php`: a pending
      delivery whose `target_url` is forced to a literal private IP transitions to
      `failed_permanent`, `Http::assertNothingSent()`, `next_attempt_at` stays null.
- [x] 4.2 RED — a receiver responding 302 with a private-IP `Location`: confirmed RED
      empirically proved the vulnerability — before the fix, `Http::assertSentCount(1)`
      failed because Guzzle actually followed the redirect (2 requests recorded).
- [x] 4.3 GREEN — added `OutboundHostGuard $hostGuard` as a fourth `handle()`
      parameter (and updated the shared `c10InvokeHandle()` test helper — the only
      caller); inserted the block-and-return right after the existing
      secret/target_url null guard.
- [x] 4.4 GREEN — added `->withOptions(['allow_redirects' => false])` to the existing
      `Http::timeout(...)->connectTimeout(...)` chain. 18/18 in
      `DeliverWebhookJobTest.php` green, including every pre-existing test in the file
      (0 regressions).

## 5. Spec + full-suite verification

- [x] 5.1 Confirmed — every scenario in `specs/webhooks-integration/spec.md`'s new
      requirement is covered: literal-IP/metadata/scheme rejection by
      `OutboundHostGuardTest.php` + `ProjectCrudTest.php`/`WebhookDefaultsTest.php`;
      unresolvable-hostname acceptance by `OutboundHostGuardTest.php`; send-time
      re-check and redirect-not-followed by `DeliverWebhookJobTest.php`.
- [x] 5.2 `vendor/bin/pint --dirty --format agent` — passed, 0 changes.
- [x] 5.3 `vendor/bin/phpstan analyse --memory-limit=1G` — passed, 0 errors.
- [x] 5.4 `php -d memory_limit=2G artisan test --coverage --min=85` — **full suite
      green: 4474 tests, 4467 passed, 0 failed, 7 skipped (pre-existing, unrelated),
      14305 assertions. Coverage 94.9% overall** (floor 85%, held). New
      `OutboundHostGuard` at 93.3% in isolation after adding a `localhost`-resolution
      case (added post-hoc: the first full run showed 73.3%, missing the
      resolves-and-blocked branch a literal-IP/never-resolving case can't reach). The
      one remaining uncovered line (65, `return false` after an all-allowed resolved
      set) needs a hostname that deterministically resolves to a known-public IP with
      no real-network dependency — not available without either flaky live DNS or a
      fake-resolver dependency the design deliberately omitted (see design.md); an
      accepted, minor residual gap.
- [x] 5.5a First commit attempt was **refused by the blocking pre-commit `gga run`
      adversarial review** (`api/captainhook.json`), which found two real defects in
      the diff it reviewed:
      1. **IPv6 SSRF bypass** — `parse_url()` keeps a literal IPv6 host bracketed
         (`"[::1]"`), which `filter_var(..., FILTER_VALIDATE_IP)` rejects; the code
         fell through to `gethostbynamel("[::1]")` (fails to resolve a bracketed
         literal) and returned "not blocked". Separately, `gethostbynamel()` only
         resolves A (IPv4) records, so a hostname resolving solely via AAAA to a
         private/loopback IPv6 address bypassed the guard entirely. Both verified
         empirically (`php -r ...`) before fixing, per this project's
         verify-before-completion discipline. Fixed: strip enclosing brackets before
         `FILTER_VALIDATE_IP`; resolve AAAA via `dns_get_record($host, DNS_AAAA)`
         alongside `gethostbynamel()`, merge, check every resolved address.
      2. `default_webhook_url.safewebhookurl` missing from
         `UpdateOrganizationRequest::messages()` — that file's own docblock states
         "EVERY declared rule appears here." Added, mirroring
         `ValidatesProjectComposition::messages()`'s identical entry.
      New RED tests added for both (bracketed IPv6 loopback/unique-local/link-local/
      metadata-mapped literals; a bracketed public IPv6 literal allowed) before the
      fix, confirmed failing, then GREEN. Re-ran affected suites (258/258 passed) +
      Pint + PHPStan (0 errors) after the fix.
- [ ] 5.5b Commit as one work unit on `feature/webhook-ssrf-guard`; Conventional
      Commit, no AI attribution beyond what the session's own attribution
      instructions require.
