# Proposal: Webhook SSRF Guard

## Intent

`webhook_url` (`StoreProjectRequest`, `UpdateProjectRequest`) and `default_webhook_url`
(`UpdateOrganizationRequest`) are validated only with Laravel's generic `url` rule — no
scheme restriction, no check on the resolved network address. `DeliverWebhookJob`
(`app/Jobs/DeliverWebhookJob.php:170`) then issues a server-side `Http::post()` straight
at that stored value, following redirects by default.

A tenant admin — or an attacker who compromises one admin account — can therefore point
outbound webhook delivery at internal network services or the cloud metadata endpoint
(`169.254.169.254`) reachable from Railway's infrastructure, by submitting a URL whose
host is a private/loopback/link-local/reserved IP, or a public hostname engineered to
redirect there. This is a server-side request forgery (SSRF) gap.

## Verified current state

| Claim | Evidence |
|---|---|
| `webhook_url` has no host/scheme restriction beyond `url` | `app/Http/Requests/StoreProjectRequest.php:118`, `app/Http/Requests/UpdateProjectRequest.php:162` |
| `default_webhook_url` has the same gap | `app/Http/Requests/UpdateOrganizationRequest.php:45` |
| The outbound call is a direct POST at the stored value | `app/Jobs/DeliverWebhookJob.php:170`, `Http::...->post($delivery->target_url)` |
| Guzzle follows redirects by default (no `allow_redirects` override anywhere in this job) | `app/Jobs/DeliverWebhookJob.php:159-170` |
| A comparable field elsewhere already restricts scheme | `app/Http/Requests/PublicApi/CreateInterviewRequest.php:64` — `exit_redirect_url` requires `starts_with:https://`, but that field is a browser redirect target, not a server-side outbound call, so it carries no SSRF exposure itself and is out of scope here |
| No existing SSRF/private-IP guard exists anywhere in `app/` | `rg -iln "ssrf\|private.?ip\|FILTER_FLAG_NO_PRIV\|169\\.254\|isPrivate"` — no matches outside this change |

## Approach

Add one small, dependency-free service, `App\Services\Webhooks\OutboundHostGuard`, that
classifies a URL as blocked when: the scheme is not `https`, the host is a literal
private/loopback/link-local/reserved IP, or the host resolves (via `gethostbynamel`) to
one. A hostname that fails to resolve at all is NOT blocked — every existing webhook
test fixture uses an IANA-reserved, intentionally non-resolving domain (`*.test`,
`*.example`, `*.invalid`), and a host that cannot be resolved poses no connection risk in
the first place; Guzzle's own connection attempt fails naturally and is already handled
as a retryable connection error.

Wire the guard in twice, per the audit's own recommendation ("re-checked at send time...
to defend against DNS rebinding"):

1. **Input validation** — a new `App\Rules\SafeWebhookUrl` rule on `webhook_url` and
   `default_webhook_url`, rejecting the obviously-bad case at submission time.
2. **Send time** — `DeliverWebhookJob::handle()` re-checks the guard immediately before
   the HTTP call, and disables redirect-following (`allow_redirects: false`) so a
   receiver that returns a redirect to a private target is never followed — the classic
   bypass for a check that only inspects the *original* URL.

## Out of scope

- `exit_redirect_url` / `error_redirect_url` (browser redirects, not server-side calls).
- Full DNS-rebinding protection at the TCP-connect level (would require a custom Guzzle
  handler resolving DNS itself and pinning the connection to the checked IP). The
  send-time re-check plus redirect-disabling closes the practical exploit paths without
  that additional machinery; documented as a residual, accepted gap.
