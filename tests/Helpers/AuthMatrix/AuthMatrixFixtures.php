<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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
        return [
            // ─── projects ────────────────────────────────────────────────────
            'GET api/projects' => [],
            'POST api/projects' => [
                'payload' => fn (AuthMatrixWorld $w, Organization $org): array => self::newProject($org),
            ],
            'GET api/projects/{project}' => ['params' => self::project(...)],
            'PUT|PATCH api/projects/{project}' => [
                'params' => self::project(...),
                'payload' => fn (): array => ['name' => 'Renamed by the matrix'],
            ],
            'DELETE api/projects/{project}' => ['params' => self::project(...)],

            // ─── project questions ───────────────────────────────────────────
            'GET api/projects/{project}/questions' => ['params' => self::project(...)],
            'POST api/projects/{project}/questions' => [
                'params' => self::project(...),
                'payload' => fn (AuthMatrixWorld $w): array => [
                    'competency_id' => $w->resources()->spareCompetency()->id,
                    'text' => ['en' => 'A question authored by the matrix'],
                ],
            ],
            'PUT api/projects/{project}/questions/order' => [
                'params' => self::project(...),
                'payload' => fn (AuthMatrixWorld $w): array => ['ids' => [$w->resources()->question()->id]],
            ],
            'PATCH api/projects/{project}/questions/{question}' => [
                'params' => self::question(...),
                'payload' => fn (): array => ['text' => ['en' => 'Edited by the matrix']],
            ],
            'DELETE api/projects/{project}/questions/{question}' => ['params' => self::question(...)],
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function project(AuthMatrixResources $r): array
    {
        return ['project' => $r->project()->id];
    }

    /**
     * @return array<string, string|int>
     */
    private static function question(AuthMatrixResources $r): array
    {
        return ['project' => $r->project()->id, 'question' => $r->question()->id];
    }

    /**
     * A valid `POST /projects` body that references ONLY the given
     * organization's own framework version and avatar template — which is
     * all a caller may reference.
     *
     * @return array<string, mixed>
     */
    private static function newProject(Organization $org): array
    {
        return TenantContextScope::runFor($org->id, fn (): array => [
            'framework_version_id' => FrameworkVersion::factory()->create(['organization_id' => $org->id])->id,
            'slug' => 'matrix-'.Str::lower(Str::random(8)),
            'name' => 'Project created by the matrix',
            'assessment_type' => 'standard',
            'role_code' => 'ICO',
            'language' => 'en',
            'avatar_template_id' => templateIdForCurrentOrg(),
            'competency_ids' => [],
        ]);
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
