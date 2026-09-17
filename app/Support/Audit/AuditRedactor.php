<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Strips credential values from an audit `before`/`after` payload, at any
 * depth (C13).
 *
 * Extracted from `AuditRecorder` (framework-catalogue-authoring PR8, design
 * D13) so a SECOND audit writer — `App\Support\Superadmin\
 * PlatformAuditWriter`, which writes platform-scoped rows outside
 * `AuditRecorder`'s tenant-stamped `AuditLog::create()` path — is subject to
 * the SAME redaction rule rather than a second, independently-maintained
 * copy of the denylist that could drift from this one. `AuditRecorder`'s own
 * public behaviour is unchanged: it delegates here instead of redacting
 * inline.
 *
 * Names are kept and values are not, deliberately: "the webhook secret was
 * rotated" is exactly what an auditor needs to see, and the secret itself is
 * exactly what they must not.
 */
final class AuditRedactor
{
    /**
     * Attribute names whose VALUES never reach the trail.
     *
     * @var list<string>
     */
    private const DENYLIST = [
        'password',
        'password_confirmation',
        'key_hash',
        'api_key',
        'webhook_secret',
        'secret',
        'token',
        'remember_token',
        'jwt_secret',
    ];

    private const REDACTED = '[redacted]';

    /**
     * Recursive because a changed attribute can itself be an array — a
     * project's webhook config, for instance — and a top-level-only pass
     * would let a nested secret through while looking like it had done its
     * job.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = strtolower($key);

        if (in_array($normalised, self::DENYLIST, true)) {
            return true;
        }

        // Catches api_token, access_token, refresh_token and anything else that
        // follows the convention without needing to enumerate it.
        return str_ends_with($normalised, '_token')
            || str_ends_with($normalised, '_secret');
    }
}
