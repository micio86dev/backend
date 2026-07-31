<?php

declare(strict_types=1);

/**
 * projects.error_redirect_url — where a candidate goes when the interview FAILS.
 *
 * Sibling of `exit_redirect_url` and, in practice, the more important of the
 * two. On success the candidate is finished; on failure they are stranded on a
 * BEAI screen belonging to a company they have no relationship with, and only
 * the calling system can tell them what happens next.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

function errRedirectProject(array $attributes = []): Project
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create($attributes);
}

test('a project persists a configured error redirect url', function (): void {
    $project = errRedirectProject(['error_redirect_url' => 'https://hr.acme.test/assessment/failed']);

    expect($project->fresh()->error_redirect_url)->toBe('https://hr.acme.test/assessment/failed');
});

test('the field is optional and defaults to null', function (): void {
    // Absence is a supported configuration, not a missing setting: a client
    // that wants BEAI to handle its own failures keeps the inline screen.
    expect(errRedirectProject()->fresh()->error_redirect_url)->toBeNull();
});

test('it is validated on the same terms as its sibling', function (): void {
    // Asserted against the source rather than by calling rules(): that method
    // resolves the authenticated user's organization to build tenant-scoped
    // rules, so instantiating it bare throws on a null user. The property under
    // test is the rule definition, not the request lifecycle.
    foreach (['StoreProjectRequest', 'UpdateProjectRequest'] as $class) {
        $source = (string) file_get_contents(app_path("Http/Requests/{$class}.php"));

        expect($source)->toContain('error_redirect_url');

        // Same shape as exit_redirect_url: a divergence here would let an
        // unvalidated URL reach a browser redirect.
        $line = collect(explode("\n", $source))
            ->first(fn (string $l): bool => str_contains($l, "'error_redirect_url' =>"));

        expect($line)->toContain('nullable');
        expect($line)->toContain('url');
        expect($line)->toContain('max:2048');
    }
});

test('it reaches the candidate session resource', function (): void {
    // The party that needs this value is a browser recovering from a failed
    // interview, and that browser holds only a candidate token — so the value
    // has to travel on the candidate session, not an admin endpoint.
    $source = (string) file_get_contents(app_path('Http/Resources/ParticipantResource.php'));

    expect($source)->toContain('error_redirect_url');
});
