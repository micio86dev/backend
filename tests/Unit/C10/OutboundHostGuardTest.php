<?php

declare(strict_types=1);

/**
 * RED — webhook-ssrf-guard design.md D1: OutboundHostGuard.
 *
 * Every case here is deterministic without network access — literal IPs need no DNS,
 * and `*.test`/`*.example`/`*.invalid` are IANA Special-Use domains (RFC 6761) that
 * never resolve anywhere, including CI. No case in this file depends on real DNS
 * actually succeeding.
 */

use App\Services\Webhooks\OutboundHostGuard;

test('a non-https scheme is blocked', function (): void {
    expect((new OutboundHostGuard)->isBlocked('http://example.test/hook'))->toBeTrue();
});

test('a URL with no host is blocked', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https:///hook'))->toBeTrue();
});

test('a malformed URL is blocked', function (): void {
    expect((new OutboundHostGuard)->isBlocked('not a url at all'))->toBeTrue();
});

test('loopback is blocked', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://127.0.0.1/hook'))->toBeTrue();
});

test('the cloud metadata / link-local address is blocked', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://169.254.169.254/latest/meta-data'))->toBeTrue();
});

test('RFC1918 private ranges are blocked', function (string $ip): void {
    expect((new OutboundHostGuard)->isBlocked("https://{$ip}/hook"))->toBeTrue();
})->with([
    '10.0.0.1',
    '172.16.0.1',
    '192.168.1.1',
]);

test('a public IP literal is allowed', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://8.8.8.8/hook'))->toBeFalse();
});

test('a hostname that does not resolve is allowed — no connection is possible either way', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://example.test/hook'))->toBeFalse();
});

test('a hostname that does not resolve is allowed, port and path preserved', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://webhooks.invalid:8443/beai-demo/team-lead'))->toBeFalse();
});

test('a hostname that DOES resolve, to a blocked address, is blocked', function (): void {
    // `localhost` resolves via the local hosts file / nsswitch on every environment
    // this runs in (dev, CI) — deterministic, no real internet DNS dependency — and
    // exercises the resolve-then-check-every-IP branch that a literal-IP or
    // never-resolving-hostname case cannot reach.
    expect((new OutboundHostGuard)->isBlocked('https://localhost/hook'))->toBeTrue();
});

test('a bracketed literal IPv6 loopback/private/link-local/metadata address is blocked', function (string $ip): void {
    // gga review finding: parse_url() keeps the brackets in the host component
    // ("[::1]", not "::1") — filter_var() on the bracketed string fails, and
    // falling straight through to hostname resolution used to silently allow it.
    expect((new OutboundHostGuard)->isBlocked("https://[{$ip}]/hook"))->toBeTrue();
})->with([
    '::1',
    'fd00::1',
    'fe80::1',
    '::ffff:169.254.169.254',
    '::ffff:10.0.0.1',
]);

test('a bracketed literal public IPv6 address is allowed', function (): void {
    expect((new OutboundHostGuard)->isBlocked('https://[2001:4860:4860::8888]/hook'))->toBeFalse();
});
