## ADDED Requirements

### Requirement: Outbound target validation — SSRF guard

The system MUST refuse to accept or deliver to a `webhook_url` / `default_webhook_url`
whose scheme is not `https`, or whose host is (or resolves to) a private, loopback,
link-local, or otherwise reserved network address. This check MUST run both at
submission time (`StoreProjectRequest`, `UpdateProjectRequest`,
`UpdateOrganizationRequest`) and again immediately before each outbound delivery attempt
(`DeliverWebhookJob`), and `DeliverWebhookJob` MUST disable HTTP redirect-following so a
receiver cannot bounce a delivery to a disallowed target via a 3xx response.

#### Scenario: A literal private/loopback/link-local IP is rejected at submission

- GIVEN an admin submits `webhook_url = "https://127.0.0.1/hook"` (or a `10.0.0.0/8`,
  `172.16.0.0/12`, `192.168.0.0/16`, or `169.254.0.0/16` host, including the cloud
  metadata address `169.254.169.254`)
- WHEN `StoreProjectRequest`/`UpdateProjectRequest`/`UpdateOrganizationRequest` validates
  the payload
- THEN validation fails with `webhook_url_unsafe` (or `default_webhook_url` in the
  organization case) and no project/organization row is written with that value

#### Scenario: A non-https scheme is rejected at submission

- GIVEN an admin submits `webhook_url = "http://example.test/hook"`
- WHEN the request is validated
- THEN validation fails with `webhook_url_unsafe`

#### Scenario: A hostname that cannot be resolved is accepted

- GIVEN an admin submits `webhook_url = "https://example.test/hook"` (an IANA
  Special-Use domain that never resolves)
- WHEN the request is validated
- THEN validation passes on this rule (existing `url`/`max` rules still apply
  unchanged) — no connection is possible to an unresolvable host, so it carries no SSRF
  risk

#### Scenario: Send-time re-check blocks a delivery even if input-time missed it

- GIVEN a `webhook_deliveries` row has `target_url` resolving, at send time, to a
  disallowed network address (e.g. changed after creation)
- WHEN `DeliverWebhookJob` runs
- THEN no HTTP request is attempted; the row transitions directly to
  `status = failed_permanent` with a `last_error` that does not reveal internal network
  details beyond "disallowed network address", and no retry is scheduled

#### Scenario: A redirect to a disallowed target is never followed

- GIVEN a delivery's `target_url` passes the guard (its host resolves to a public
  address at send time)
- AND the receiver responds with a 3xx redirecting to a private/internal address
- WHEN `DeliverWebhookJob` sends the request
- THEN the redirect target is never connected to; the attempt is recorded using the
  receiver's own 3xx status, following the existing retry classification for that status
  class
