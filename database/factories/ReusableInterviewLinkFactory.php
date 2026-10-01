<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for `App\Models\ReusableInterviewLink` (reusable-interview-links).
 *
 * NOTE: `organization_id` is NOT fillable — it is stamped by
 * `TenantScoped::creating` from the active `TenantResolver`. Callers MUST
 * create inside `App\Support\Tenancy\TenantContextScope::runFor()` (or with the
 * resolver already set), the same discipline as `ExportFactory`.
 *
 * The default `project_id` is resolved lazily, inside that same tenant context,
 * so the link's organisation is always its project's organisation: a link whose
 * project belongs to another tenant is an incoherent row no real code path can
 * create. Use `forProject()` to pin a specific project.
 *
 * Every default link carries its OWN freshly generated token, stored as hash +
 * prefix only; the raw value is not recoverable from the row. A test that needs
 * to redeem the link passes a known token to `withRawToken()`.
 *
 * @extends Factory<ReusableInterviewLink>
 */
class ReusableInterviewLinkFactory extends Factory
{
    protected $model = ReusableInterviewLink::class;

    /**
     * An active, never-used link with no label.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rawToken = ReusableLinkTokenGenerator::generate();

        return [
            'project_id' => fn (): int => Project::factory()->create()->id,
            'label' => null,
            'lang' => 'en',
            'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
            'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
            'uses_count' => 0,
            'last_used_at' => null,
            'disabled_at' => null,
        ];
    }

    /**
     * A link of an existing project, in that project's language (the rule the
     * creation action applies when it freezes `lang`).
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'lang' => $project->language,
        ]);
    }

    /**
     * A link whose secret is `$rawToken`: stores its hash and visible prefix,
     * never the token. Throws for a value that is not a well-formed token.
     */
    public function withRawToken(string $rawToken): static
    {
        return $this->state(fn (): array => [
            'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
            'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
        ]);
    }

    /**
     * A link that has been switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'disabled_at' => now(),
        ]);
    }
}
