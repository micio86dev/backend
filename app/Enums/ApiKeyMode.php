<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `live`/`test` mode of a BEAI M2M / public-API key (C5, public-api step 2 —
 * SPEC.md §3.7 "Test mode").
 *
 * Review follow-up on step 2: the marker string (`beai_live_`/`beai_test_`),
 * the DB column value (`live`/`test`) and the mode literal used across
 * `App\Services\ApiKeyGenerator`, `App\Support\PublicApi\ApiMode`,
 * `App\Http\Middleware\PublicApi\AuthenticatePublicApi`,
 * `App\Http\Controllers\M2m\ApiClientController` and `App\Models\ApiClient`
 * used to be five independent string literals that happened to agree. This
 * enum is the single source of truth all of them now read from.
 */
enum ApiKeyMode: string
{
    case Live = 'live';
    case Test = 'test';

    /**
     * The `beai_live_`/`beai_test_` prefix a raw key of this mode starts with.
     */
    public function marker(): string
    {
        return match ($this) {
            self::Live => 'beai_live_',
            self::Test => 'beai_test_',
        };
    }

    /**
     * The mode a raw key encodes, or null when it carries neither the
     * `beai_live_` nor the `beai_test_` marker (malformed / unknown key).
     */
    public static function fromMarker(string $rawKey): ?self
    {
        foreach (self::cases() as $case) {
            if (str_starts_with($rawKey, $case->marker())) {
                return $case;
            }
        }

        return null;
    }
}
