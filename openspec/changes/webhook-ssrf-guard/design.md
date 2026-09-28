# Design: Webhook SSRF Guard

## Technical Approach

One pure, stateless service backs both enforcement points, so the input-time and
send-time checks can never drift apart.

### D1 — `App\Services\Webhooks\OutboundHostGuard`

```php
final class OutboundHostGuard
{
    public function isBlocked(string $url): bool;
}
```

Logic, in order:

1. Parse with `parse_url()`. No scheme or no host → blocked (defensive; the `url`
   Laravel rule already guarantees a parseable absolute URL runs first).
2. Scheme !== `https` → blocked.
3. Host is a literal IP (`filter_var($host, FILTER_VALIDATE_IP)`) → blocked when
   `filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)`
   fails. These two flags together cover RFC1918 (10/8, 172.16/12, 192.168/16),
   link-local/metadata (169.254/16 — includes the cloud metadata IP), loopback
   (127/8), and the remaining IANA-reserved ranges, for both IPv4 and IPv6.
4. Host is a hostname → resolve via `gethostbynamel($host)`. If it returns a non-empty
   array, apply the same `FILTER_VALIDATE_IP` check to **every** resolved address; any
   one blocked address blocks the URL. If resolution returns `false` (no records), NOT
   blocked — see proposal.md's "Approach" for why (test-fixture domains, and no
   connection is possible to an unresolvable host regardless).

No constructor dependencies — nothing to fake in tests, real IP-literal and
real-reserved-TLD inputs are deterministic everywhere (CI included).

### D2 — `App\Rules\SafeWebhookUrl` (input-time)

Implements `Illuminate\Contracts\Validation\ValidationRule`. `validate()` instantiates
`OutboundHostGuard` directly (no DI needed — see D1) and calls `$fail('webhook_url_unsafe')`
on a block. The literal slug string, not a translated sentence, matches this repo's
existing convention for this field (`ValidatesProjectComposition::messages()`:
`webhook_url_invalid`, `webhook_url_too_long` are already short slugs, not sentences).

Applied as an additional array element on the existing `webhook_url` /
`default_webhook_url` rule arrays — additive, not a replacement of `url`/`max:2048`.

### D3 — `DeliverWebhookJob::handle()` (send-time)

Inserted immediately after the existing `$secret === null || $delivery->target_url ===
null` guard (line ~139, which already proves `target_url` is a non-null string past that
point) and before the request is built:

```php
if ($hostGuard->isBlocked($delivery->target_url)) {
    $this->persist($delivery, function () use ($delivery, $attemptCount): void {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::FailedPermanent,
            'attempt_count' => $attemptCount,
            'last_attempt_at' => now(),
            'last_error' => 'target_url resolves to a disallowed network address',
        ])->save();
    });

    return;
}
```

`FailedPermanent`, not `Retryable`: a blocked target does not become unblocked by
retrying the identical URL, mirroring the existing "4xx that isn't 408/429 → permanent"
reasoning in `RetryClassifier`. `OutboundHostGuard` is added as a fourth
constructor-injected parameter on `handle()` (Laravel resolves job method parameters via
the container automatically — matches the existing `WebhookSigner`/`SecretRedactor`/
`RetryClassifier` pattern; no new binding needed for a dependency-free class).

The existing `Http::timeout(...)->connectTimeout(...)` chain (line 160) gains
`->withOptions(['allow_redirects' => false])`. A receiver that responds with a redirect
is then reported as its raw 3xx status; `RetryClassifier`'s existing fallback branch
("1xx / 3xx and anything else outside the modeled ranges: defensively retryable")
already handles that status with no change to `RetryClassifier` itself — the connection
to the redirect target is simply never made.

## Why not resolve-and-reject unconditionally on DNS failure

Considered and rejected. Every existing webhook fixture across ~10 test files uses an
IANA Special-Use domain (`*.test`, `*.example`, `*.invalid` — RFC 6761), which by
definition never resolves in the public DNS, anywhere, including CI. Failing closed on
"could not resolve" would reject every one of those fixtures alongside any genuinely
unreachable production URL, for the same reason a production webhook to an unresolvable
host is already harmless: no TCP connection is possible either way. `Http::post()`
against a non-resolving host already fails as a `ConnectionException`, which
`DeliverWebhookJob::handle()` already classifies as `Retryable` — no security bypass,
only a normal delivery failure.

## Residual gap (documented, accepted)

A hostname that resolves to a public IP at both input-time AND at the exact instant of
send-time re-check, but is repointed to a private IP in the sub-second window between
that check and Guzzle's own DNS resolution during connect, is not caught. Closing this
fully requires a custom Guzzle connect-handler that resolves once and pins the
connection to the checked IP. Out of scope here (proposal.md) — the two checks plus
disabled redirects close the practical, demonstrated exploit paths (direct private-IP
submission, and the redirect bounce).
