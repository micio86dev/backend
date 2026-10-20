<?php

declare(strict_types=1);

namespace App\Services\Provider;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The one place that answers "is HR-Avatar available", and the HTTP client every
 * HR-Avatar consumer shares (provider, catalogue proxy, export command).
 *
 * Availability is computed from configuration only and performs no I/O. The
 * secret key is read here and nowhere else; it is server-side only and must never
 * reach a response, a resource, a log line, an exception message or command output.
 */
final class HrAvatarConnection
{
    public const PROVIDER = 'hr_avatar';

    /**
     * The one format of an HR-Avatar config id, wherever one is accepted, stored or read back. Self-contained:
     * the `D` modifier keeps `$` from also matching before a trailing newline, so every user of the constant
     * (validation rule or `preg_match()`) refuses "avc_x\n".
     */
    public const CONFIG_ID_REGEX = '/^avc_[0-9A-Za-z]+$/D';

    public const CONFIG_ID_MAX_LENGTH = 64;

    private const VARIABLES = [
        'base_url' => 'HR_AVATAR_BASE_URL',
        'secret_key' => 'HR_AVATAR_SECRET_KEY',
        'publishable_key' => 'HR_AVATAR_PUBLISHABLE_KEY',
    ];

    /**
     * True iff all three settings are non-empty after trimming and the base URL
     * is an absolute http(s) URL.
     */
    public static function isAvailable(): bool
    {
        return self::missingVariables() === [];
    }

    /**
     * Names (never values) of the environment variables that make the integration unavailable.
     *
     * @return list<'HR_AVATAR_BASE_URL'|'HR_AVATAR_SECRET_KEY'|'HR_AVATAR_PUBLISHABLE_KEY'>
     */
    public static function missingVariables(): array
    {
        $missing = [];

        foreach (self::VARIABLES as $key => $variable) {
            $value = self::setting($key);

            if ($value === '' || ($key === 'base_url' && ! self::isHttpUrl($value))) {
                $missing[] = $variable;
            }
        }

        /** @var list<'HR_AVATAR_BASE_URL'|'HR_AVATAR_SECRET_KEY'|'HR_AVATAR_PUBLISHABLE_KEY'> */
        return $missing;
    }

    public static function baseUrl(): string
    {
        return rtrim(self::setting('base_url'), '/');
    }

    public static function publishableKey(): string
    {
        return self::setting('publishable_key');
    }

    /** Server-side only: never serialized, logged or returned. */
    public static function secretKey(): string
    {
        return self::setting('secret_key');
    }

    public static function client(): PendingRequest
    {
        return Http::baseUrl(self::baseUrl())
            ->withToken(self::secretKey())
            ->acceptJson()
            ->timeout(15);
    }

    public static function isConfigId(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= self::CONFIG_ID_MAX_LENGTH
            && preg_match(self::CONFIG_ID_REGEX, $value) === 1;
    }

    /** Opaque, stable attribution value for an organization: no name, no personal data. */
    public static function organizationRef(int $organizationId): string
    {
        return 'org_'.$organizationId;
    }

    /**
     * Validation rule: `hr_avatar` is not an accepted provider while the integration is unavailable.
     * Other values are not this rule's concern.
     */
    public static function unavailableProviderRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === self::PROVIDER && ! self::isAvailable()) {
                $fail('validation.in')->translate();
            }
        };
    }

    private static function setting(string $key): string
    {
        $value = config('hr_avatar.'.$key);

        return is_string($value) ? trim($value) : '';
    }

    private static function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
