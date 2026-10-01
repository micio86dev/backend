<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
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
     * The public redemption endpoint.
     */
    public const REDEEM_URL = '/api/reusable-links/redeem';

    /**
     * The one body every non-redeemable token is answered with, byte for byte.
     */
    public const NOT_FOUND_BODY = '{"message":"Not found."}';

    /**
     * Per-process sequence behind {@see self::identity()}: it never repeats
     * inside one PHP process, so two calls never produce the same address.
     */
    private static int $identitySequence = 0;

    /**
     * The identity a visitor types before a redemption: a name and an email.
     *
     * Defaults to a fresh `visitor-{n}@example.test` / `Visitor {n}` per call,
     * so a test that redeems several times never trips the duplicate-email
     * rule by accident. The counter is per PHP process: an actor that runs in
     * its own process (the concurrency harness) must be handed an explicit
     * identity instead of relying on the default.
     *
     * @return array{display_name: string, email: string}
     */
    public static function identity(?string $email = null, ?string $name = null): array
    {
        $number = ++self::$identitySequence;

        return [
            'display_name' => $name ?? 'Visitor '.$number,
            'email' => $email ?? 'visitor-'.$number.'@example.test',
        ];
    }

    /**
     * The one place that builds a redemption request body: the raw token plus
     * the visitor identity (a fresh one unless the test supplies its own).
     *
     * `$token` is `mixed` on purpose: the refusal tests submit arrays, integers
     * and null in the token position.
     *
     * @param  array{display_name: string, email: string}|null  $identity
     * @return array{link_token: mixed, display_name: string, email: string}
     */
    public static function redeemBody(mixed $token, ?array $identity = null): array
    {
        return ['link_token' => $token] + ($identity ?? self::identity());
    }

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
     * An organisation with an open project and an ENABLED link whose raw token
     * is known, so a test can redeem it. The row stores only the hash and the
     * prefix, exactly as a created link does.
     *
     * @param  array<string, mixed>  $projectAttributes  overrides for the project
     * @param  array<string, mixed>  $linkAttributes  overrides for the link, e.g. `['label' => 'Stand']`
     * @param  bool  $interviewable  false leaves the project with no competency
     * @return array{org: Organization, project: Project, link: ReusableInterviewLink, token: string}
     */
    public static function redeemable(array $projectAttributes = [], array $linkAttributes = [], bool $interviewable = true): array
    {
        $org = Organization::factory()->create();
        $project = self::project($org, $projectAttributes, $interviewable);
        $token = ReusableLinkTokenGenerator::generate();

        $link = self::link($project, array_merge([
            'token_hash' => ReusableLinkTokenGenerator::hash($token),
            'token_prefix' => ReusableLinkTokenGenerator::prefixOf($token),
        ], $linkAttributes));

        return ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token];
    }

    /**
     * The visitors a link produced, oldest first (`participants` is not a
     * tenant-scoped model, so this reads the table as it is).
     *
     * @return list<Participant>
     */
    public static function visitorsOf(ReusableInterviewLink $link): array
    {
        return Participant::query()
            ->where('reusable_interview_link_id', $link->id)
            ->orderBy('id')
            ->get()
            ->all();
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
     * The property names the COMMITTED `openapi.json` declares for a schema,
     * sorted: what a client generated from the contract is told to expect.
     *
     * @return list<string>
     */
    public static function exportedKeys(string $schema): array
    {
        $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($spec['components']['schemas'][$schema]['properties'] ?? []);
        sort($keys);

        return $keys;
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
