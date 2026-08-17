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

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function errRedirectProject(array $attributes = []): Project
{
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create($attributes);
}

/**
 * @return array{user: User, token: string}
 */
function errRedirectAdmin(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);
    $token = auth('api')->login($user);

    return ['user' => $user, 'token' => $token];
}

/**
 * @return array<string, mixed>
 */
function errRedirectStandardPayload(int $fvId): array
{
    return [
        'framework_version_id' => $fvId,
        'slug' => 'err-redirect-'.uniqid(),
        'name' => 'Error Redirect Project',
        'assessment_type' => 'standard',
        'role_code' => 'ICO',
        'language' => 'en',
    ];
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

test('it is nullable and length-capped on the same terms as its sibling', function (): void {
    // Asserted against the source rather than by calling rules(): that method
    // resolves the authenticated user's organization to build tenant-scoped
    // rules, so instantiating it bare throws on a null user. `nullable` and
    // `max:2048` are shape constraints with no observable HTTP behaviour worth
    // a full request round-trip, so source inspection is adequate for them.
    //
    // The actual URL-format validation is NOT asserted here — a field named
    // `error_redirect_url` contains the substring "url" regardless of whether
    // the `url` validator rule is present, so `toContain('url')` on this line
    // would pass even with the rule deleted. That behaviour is asserted below
    // via real HTTP requests instead (see "a malformed error_redirect_url…").
    foreach (['StoreProjectRequest', 'UpdateProjectRequest'] as $class) {
        $source = (string) file_get_contents(app_path("Http/Requests/{$class}.php"));

        expect($source)->toContain('error_redirect_url');

        $line = collect(explode("\n", $source))
            ->first(fn (string $l): bool => str_contains($l, "'error_redirect_url' =>"));

        expect($line)->toContain('nullable');
        expect($line)->toContain('max:2048');
    }
});

test('a malformed error_redirect_url is rejected with 422 on that field (create)', function (): void {
    // This is the behaviour the `url` validator rule exists to enforce.
    // Asserting it via a real request — instead of grepping the rule
    // source — fails if the validator is ever dropped, regardless of what
    // the rule line happens to contain as a substring.
    $org = Organization::factory()->create();
    ['token' => $token] = errRedirectAdmin($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($token)->postJson('/api/projects', array_merge(
        errRedirectStandardPayload($fv->id),
        ['error_redirect_url' => 'not-a-url']
    ));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['error_redirect_url']);
});

test('a malformed error_redirect_url is rejected with 422 on that field (update)', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = errRedirectAdmin($org);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    $response = $this->withToken($token)->patchJson("/api/projects/{$project->id}", [
        'error_redirect_url' => 'not-a-url',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['error_redirect_url']);
});

test('it reaches the candidate session resource', function (): void {
    // The party that needs this value is a browser recovering from a failed
    // interview, and that browser holds only a candidate token — so the value
    // has to travel on the candidate session, not an admin endpoint.
    $source = (string) file_get_contents(app_path('Http/Resources/ParticipantResource.php'));

    expect($source)->toContain('error_redirect_url');
});
