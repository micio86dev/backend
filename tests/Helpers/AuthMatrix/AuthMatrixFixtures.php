<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
     * @return array<string, array{params?: Closure(AuthMatrixResources): array<string, string|int>, payload?: Closure(AuthMatrixWorld, Organization): array<string, mixed>, before?: Closure(): mixed}>
     */
    private static function registry(): array
    {
        return [
            ...AuthMatrixPlatformFixtures::registry(),

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

            // ─── participants (admin read API) ───────────────────────────────
            'GET api/participants' => [],
            'GET api/participants/{id}' => ['params' => self::participant(...)],
            'GET api/participants/{id}/evaluation' => ['params' => self::participant(...)],
            'GET api/participants/{id}/evaluation/download' => ['params' => self::participant(...)],
            'GET api/participants/{id}/transcript' => ['params' => self::participant(...)],
            'GET api/participants/{id}/transcript/download' => ['params' => self::participant(...)],
            'GET api/participants/{participant}/sessions' => [
                'params' => fn (AuthMatrixResources $r): array => ['participant' => $r->participant()->id],
            ],
            'POST api/participants/{id}/recover' => [
                'params' => fn (AuthMatrixResources $r): array => ['id' => $r->erroredParticipant()->id],
            ],
            'PATCH api/participants/{id}/schedule' => [
                'params' => self::scheduledParticipant(...),
                'payload' => fn (): array => ['scheduled_at' => now('UTC')->addDays(2)->format('Y-m-d\TH:i:s\Z')],
            ],
            'DELETE api/participants/{id}/schedule' => ['params' => self::scheduledParticipant(...)],
            // The audit kill switch answers 409 BEFORE authorization (KQ-I1), so the
            // matrix runs with it ENABLED, and fakes the queue: an allowed call
            // dispatches a job that would otherwise run inline (sync) against an LLM.
            'POST api/participants/{id}/evaluation/audit' => [
                'params' => self::participant(...),
                'before' => function (): void {
                    config(['scoring.audit.enabled' => true]);
                    Queue::fake();
                },
            ],

            // ─── interview sessions ──────────────────────────────────────────
            'GET api/interview-sessions/{session}/review' => [
                'params' => fn (AuthMatrixResources $r): array => ['session' => $r->session()->id],
            ],

            // ─── evaluations index / dashboard ───────────────────────────────
            'GET api/evaluations' => [],
            'GET api/evaluations/summary' => [],
            'GET api/dashboard/activity' => [],
            'GET api/dashboard/metrics' => [],

            // ─── users (admin only) ──────────────────────────────────────────
            'GET api/users' => [],
            'POST api/users' => [
                'payload' => fn (): array => [
                    'name' => 'User created by the matrix',
                    'email' => Str::lower(Str::random(8)).'@matrix.test',
                    'password' => 'a-long-enough-password',
                    'role' => 'viewer',
                ],
            ],
            'PATCH api/users/{user}' => [
                'params' => fn (AuthMatrixResources $r): array => ['user' => $r->member()->id],
                'payload' => fn (): array => ['name' => 'Renamed by the matrix'],
            ],
            'POST api/users/{user}/deactivate' => [
                'params' => fn (AuthMatrixResources $r): array => ['user' => $r->member()->id],
            ],
            'POST api/users/{user}/activate' => [
                'params' => fn (AuthMatrixResources $r): array => ['user' => $r->deactivatedMember()->id],
            ],

            // ─── organization + logo (own organization) ──────────────────────
            'GET api/organization' => [],
            'PATCH api/organization' => ['payload' => fn (): array => ['name' => 'Renamed organization']],
            'POST api/organization/logo' => [
                'before' => fn () => Storage::fake(),
                'payload' => fn (): array => ['logo' => self::imageUpload('logo.png')],
            ],
            'DELETE api/organization/logo' => ['before' => fn () => Storage::fake()],

            // ─── profile + own session (the caller's own account) ────────────
            'GET api/auth/me' => [],
            'POST api/auth/logout' => [],
            'GET api/profile' => [],
            'PATCH api/profile' => ['payload' => fn (): array => ['name' => 'Renamed profile']],
            'PUT api/profile/password' => [
                'payload' => fn (): array => [
                    'current_password' => 'password',
                    'password' => 'a-brand-new-password',
                    'password_confirmation' => 'a-brand-new-password',
                ],
            ],
            'POST api/profile/photo' => [
                'before' => fn () => Storage::fake(),
                'payload' => fn (): array => ['photo' => self::imageUpload('photo.png')],
            ],
            'DELETE api/profile/photo' => ['before' => fn () => Storage::fake()],

            // ─── entry links ─────────────────────────────────────────────────
            // `send_email` off: an allowed mint must not depend on a mailer.
            'POST api/entry-links' => [
                'before' => fn () => config(['interview.candidate_app_url' => 'https://interview.example.test']),
                'payload' => function (AuthMatrixWorld $w): array {
                    return [
                        'project_id' => $w->resources()->openProject()->id,
                        'candidate_ref' => 'matrix-'.Str::lower(Str::random(8)),
                        'email' => Str::lower(Str::random(8)).'@matrix.test',
                        'display_name' => 'Entry link candidate',
                        'send_email' => false,
                    ];
                },
            ],

            // ─── reusable interview links ────────────────────────────────────
            // A link is created for an OPEN, interviewable project only; the
            // origin is configured so an allowed create composes its URL.
            'POST api/projects/{project}/reusable-links' => [
                'before' => fn () => config(['interview.candidate_app_url' => 'https://interview.example.test']),
                'params' => fn (AuthMatrixResources $r): array => ['project' => $r->openProject()->id],
                'payload' => fn (): array => ['label' => 'Matrix link'],
            ],
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function participant(AuthMatrixResources $r): array
    {
        return ['id' => $r->participant()->id];
    }

    /**
     * @return array<string, string|int>
     */
    private static function scheduledParticipant(AuthMatrixResources $r): array
    {
        return ['id' => $r->scheduledParticipant()->id];
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
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::registry());
    }

    /**
     * Route-specific test-environment setup that must be in place before the
     * request (and before the database is fingerprinted).
     */
    public static function prepare(string $key): void
    {
        $before = self::registry()[$key]['before'] ?? null;

        if ($before !== null) {
            $before();
        }
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

    private static function imageUpload(string $filename): UploadedFile
    {
        return UploadedFile::fake()->image($filename, 64, 64);
    }
}
