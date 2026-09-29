<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Enums\ApiKeyMode;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * The credential an M2M / Public API actor or scenario presents.
 *
 * `$required` is the ability (M2M) or scope (`/v1`) the route demands, `$all`
 * the full set the routes of the surface demand. So "a key WITHOUT the
 * ability" is every ability but the required one — a key that is valid, live
 * and holds plenty, just not THIS one — rather than an empty key that could be
 * refused for being empty.
 */
final class AuthMatrixMachineCredentials
{
    /**
     * Refused before authorization ever runs: the key does not authenticate.
     *
     * @var list<string>
     */
    public const UNUSABLE_KEYS = ['revoked', 'denylisted', 'expired_key', 'unknown_key', 'not_bearer', 'empty_bearer'];

    /**
     * @param  list<string>  $all
     * @return array{token: ?string, headers: array<string, string>}
     */
    public static function for(string $who, ?string $required, array $all, AuthMatrixMachineWorld $m, Organization $org): array
    {
        $key = static fn (array $abilities, ApiKeyMode $mode = ApiKeyMode::Live, array $overrides = []): string => $m->key($org, $abilities, $mode, $overrides)['raw'];
        $holding = $required === null ? $all : [$required];
        $lacking = $required === null ? [] : array_values(array_diff($all, [$required]));

        return match ($who) {
            AuthMatrix::UNAUTHENTICATED => self::bearer(null),
            AuthMatrix::KEY_WITH_ABILITY, AuthMatrix::KEY_WITH_SCOPE => self::bearer($key($holding)),
            AuthMatrix::KEY_WITHOUT_ABILITY, AuthMatrix::KEY_WITHOUT_SCOPE, 'no_abilities' => self::bearer($key($who === 'no_abilities' ? [] : $lacking)),
            AuthMatrix::TEST_MODE_KEY => self::bearer($key($all, ApiKeyMode::Test)),
            AuthMatrix::LIVE_KEY_BROWSER_ORIGIN => ['token' => $key($all), 'headers' => ['Origin' => 'https://app.example.test']],
            AuthMatrix::USER_JWT => self::bearer($m->world->actor(AuthMatrix::ADMIN)['token']),
            AuthMatrix::CANDIDATE_JWT => self::bearer($m->token($m->participant($org, 'in_corso', label: 'bearer'))),
            'revoked' => self::bearer($key($all, ApiKeyMode::Live, ['is_active' => false])),
            'denylisted' => self::denylisted($m, $org, $all),
            'expired_key' => self::bearer($key($all, ApiKeyMode::Live, ['expires_at' => now()->subDay()])),
            'unknown_key' => self::bearer(ApiKeyGenerator::generate()),
            'not_bearer' => ['token' => null, 'headers' => ['Authorization' => 'Basic '.base64_encode('user:'.ApiKeyGenerator::generate())]],
            'empty_bearer' => ['token' => null, 'headers' => ['Authorization' => 'Bearer ']],
            default => throw new LogicException("Unknown machine credential '{$who}'."),
        };
    }

    /**
     * A key still `is_active` in the database but on the Redis denylist — the
     * window between a revocation being written and being read back.
     *
     * @param  list<string>  $all
     * @return array{token: string, headers: array<string, string>}
     */
    private static function denylisted(AuthMatrixMachineWorld $m, Organization $org, array $all): array
    {
        ['raw' => $raw, 'client' => $client] = $m->key($org, $all);
        Cache::put('client_revoked:'.$client->id, true, 3600);

        return ['token' => $raw, 'headers' => []];
    }

    /**
     * @return array{token: ?string, headers: array<string, string>}
     */
    private static function bearer(?string $token): array
    {
        return ['token' => $token, 'headers' => []];
    }
}
