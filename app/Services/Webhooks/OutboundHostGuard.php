<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * OutboundHostGuard — classifies a webhook target URL as safe or blocked for a
 * server-side outbound HTTP call (webhook-ssrf-guard, design.md D1).
 *
 * Blocks: any non-`https` scheme; a literal IP (IPv4 or bracketed/unbracketed
 * IPv6) that is loopback, link-local (includes the `169.254.169.254` cloud
 * metadata address and its IPv6-mapped form), RFC1918/unique-local private, or
 * otherwise IANA-reserved; a hostname that RESOLVES (via A or AAAA) to any such
 * address.
 *
 * Does NOT block a hostname that fails to resolve at all — see design.md "Why not
 * resolve-and-reject unconditionally on DNS failure": every existing webhook test
 * fixture uses an IANA Special-Use domain (`*.test`/`*.example`/`*.invalid`, RFC 6761)
 * that never resolves anywhere, and a host nothing can connect to poses no SSRF risk
 * in the first place — `Http::post()` against it already fails as a normal,
 * already-handled `ConnectionException`.
 *
 * Stateless and dependency-free by design: both `App\Rules\SafeWebhookUrl`
 * (submission-time) and `DeliverWebhookJob` (send-time, design.md D3) instantiate this
 * directly, so the two enforcement points can never drift apart.
 *
 * REQ: webhooks-integration — "Outbound target validation — SSRF guard"
 */
final class OutboundHostGuard
{
    /**
     * `FILTER_FLAG_NO_PRIV_RANGE` excludes RFC1918 (10/8, 172.16/12, 192.168/16) and
     * link-local/metadata (169.254/16). `FILTER_FLAG_NO_RES_RANGE` additionally
     * excludes loopback (127/8) and the remaining IANA-reserved ranges. Together they
     * cover every address class this guard needs to reject, for both IPv4 and IPv6.
     */
    private const int DISALLOWED_IP_FLAGS = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    public function isBlocked(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if ($scheme !== 'https' || $host === null || $host === '') {
            return true;
        }

        // PHP's parse_url() keeps a literal IPv6 host bracketed ("[::1]", not
        // "::1") — filter_var() only accepts the unbracketed form (gga review
        // finding: a bracketed IPv6 literal fell through to hostname resolution,
        // failed to resolve as a hostname, and was silently allowed).
        $host = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! $this->isAllowedIp($host);
        }

        $resolved = $this->resolveIps($host);

        if ($resolved === []) {
            // Cannot resolve → cannot connect → no SSRF exposure. See class doc.
            return false;
        }

        foreach ($resolved as $ip) {
            if (! $this->isAllowedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Both record types — `gethostbynamel()` resolves A (IPv4) only, so a hostname
     * that resolves solely via AAAA to a blocked IPv6 address used to bypass this
     * guard entirely (gga review finding).
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $aRecords = @gethostbynamel($host);
        $ipv4 = $aRecords === false ? [] : $aRecords;

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        $ipv6 = $aaaaRecords === false
            ? []
            : array_column($aaaaRecords, 'ipv6');

        return [...$ipv4, ...$ipv6];
    }

    private function isAllowedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, self::DISALLOWED_IP_FLAGS) !== false;
    }
}
