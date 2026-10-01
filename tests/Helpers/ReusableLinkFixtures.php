<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Support\Tenancy\TenantContextScope;

/**
 * Shared arrangement for the reusable-interview-links HTTP tests.
 *
 * Class-based (this repo has no `tests/Datasets` and no shared test-case
 * traits) and autoloaded through the `Tests\` PSR-4 root, so every file under
 * `tests/Feature/ReusableLinks` builds its world the same way instead of
 * carrying its own copy of "an open, interviewable project".
 *
 * Every model is created inside `TenantContextScope::runFor()`: a
 * `TenantScoped` row takes its organisation from the resolver, and the
 * resolver state left behind by an earlier HTTP call in the same test must not
 * decide which tenant a fixture lands in.
 */
final class ReusableLinkFixtures
{
    public const CANDIDATE_ORIGIN = 'https://interview.example.com';

    /**
     * Point the entry URL composer at a known candidate app origin.
     */
    public static function configureOrigin(): void
    {
        config(['interview.candidate_app_url' => self::CANDIDATE_ORIGIN]);
    }

    /**
     * A project a reusable link may be created for: `active`, no timing
     * window, and interviewable (one selected competency with a question).
     *
     * @param  array<string, mixed>  $attributes  overrides, e.g. `['status' => 'inactive']`
     * @param  bool  $interviewable  false leaves the project with no competency
     */
    public static function project(Organization $org, array $attributes = [], bool $interviewable = true): Project
    {
        return TenantContextScope::runFor($org->id, function () use ($org, $attributes, $interviewable): Project {
            $project = Project::factory()->create(array_merge([
                'framework_version_id' => FrameworkVersion::factory()->create(['organization_id' => $org->id])->id,
                'status' => 'active',
                'assessment_type' => 'standard',
                'role_code' => 'ICO',
                'language' => 'en',
                'goes_live_at' => null,
                'deadline_at' => null,
            ], $attributes));

            if ($interviewable) {
                makeProjectInterviewable($project);
            }

            return $project;
        });
    }

    /**
     * A persisted link of `$project`, in the project's own organisation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function link(Project $project, array $attributes = []): ReusableInterviewLink
    {
        return TenantContextScope::runFor(
            $project->organization_id,
            fn (): ReusableInterviewLink => ReusableInterviewLink::factory()->forProject($project)->create($attributes),
        );
    }

    /**
     * Every link of a project, read past the tenant scope so an assertion
     * sees the table exactly as it is.
     *
     * @return list<ReusableInterviewLink>
     */
    public static function rowsOf(Project $project): array
    {
        return ReusableInterviewLink::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The link count across the whole table, regardless of tenant.
     */
    public static function totalRows(): int
    {
        return ReusableInterviewLink::withoutGlobalScopes()->count();
    }

    /**
     * The token carried by a composed entry URL: its fragment.
     */
    public static function tokenOf(string $entryUrl): string
    {
        $fragment = parse_url($entryUrl, PHP_URL_FRAGMENT);

        return is_string($fragment) ? $fragment : '';
    }

    /**
     * Every key name at any depth of a decoded JSON document.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<string>
     */
    public static function keysAtAnyDepth(array $document): array
    {
        $keys = [];

        foreach ($document as $key => $value) {
            $keys[] = (string) $key;

            if (is_array($value)) {
                array_push($keys, ...self::keysAtAnyDepth($value));
            }
        }

        return $keys;
    }
}
