<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\Organization;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * The FIXTURE RESOLVER of the matrix: how to build a VALID request for a route.
 *
 * A cell says what an actor gets for an otherwise valid request, so a 403 is
 * only evidence of authorization when the request would have passed
 * validation. Each route therefore declares:
 *
 *   params   the URL placeholders — always the resource of `orgA` (the target
 *            tenant); a cross-tenant actor reaches for it from `orgB`.
 *   payload  the minimal valid body of a write. It receives the organization
 *            whose resources the ACTOR may legitimately reference (`orgA` for
 *            its own members and an acting superadmin, `orgB` for the
 *            cross-tenant admin), because collection writes such as
 *            `POST /projects` validate their foreign keys against the caller's
 *            own organization.
 *
 * A route with no entry falls back to {@see self::defaults()}: numeric
 * placeholders and no payload, which is all the credential-rejection row needs
 * (the guard answers before anything is resolved or validated).
 */
final class AuthMatrixFixtures
{
    /**
     * @return array<string, array{params?: Closure(AuthMatrixResources): array<string, string|int>, payload?: Closure(AuthMatrixWorld, Organization): array<string, mixed>}>
     */
    private static function registry(): array
    {
        return [];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::registry());
    }

    /**
     * @return array<string, string|int>
     */
    public static function params(string $key, AuthMatrixWorld $world): array
    {
        $resolver = self::registry()[$key]['params'] ?? null;

        return $resolver !== null ? $resolver($world->resources()) : self::defaults($key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(string $key, AuthMatrixWorld $world, Organization $actorOrg): array
    {
        $resolver = self::registry()[$key]['payload'] ?? null;

        return $resolver !== null ? $resolver($world, $actorOrg) : [];
    }

    /**
     * @return array<string, string|int>
     */
    private static function defaults(string $key): array
    {
        preg_match_all('/\{(\w+)\}/', $key, $m);

        $params = [];
        foreach ($m[1] as $name) {
            $params[$name] = match ($name) {
                'roleCode' => 'ICO',
                'competencyCode' => 'PRS',
                default => 1,
            };
        }

        return $params;
    }

    public static function pngUpload(string $field = 'logo'): UploadedFile
    {
        return UploadedFile::fake()->image("{$field}.png", 64, 64);
    }
}
