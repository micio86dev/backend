<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two-tenant world one matrix case runs in, and the actors that call into it.
 *
 * `orgA` owns every target resource. `orgB` exists to supply the
 * `cross_tenant_admin` (an ADMIN of a different organization) and to give
 * collection writes somewhere to land for that actor.
 *
 * ACTORS ARE BUILT, NOT ASSUMED
 * -----------------------------
 * The role helpers (`authTokenForRole`, `saSuperadmin`) are shared with the
 * whole suite, and `Gate::before` (AppServiceProvider.php:156) answers TRUE
 * for a superadmin on every ability. So a helper that quietly returned a
 * superadmin would make every "403 for a viewer" cell pass for the wrong
 * reason — or rather, turn it into a 200 that nobody notices. Each actor is
 * therefore checked as it is built ({@see self::assertIdentity()}): a role
 * actor is NOT a superadmin, belongs to the org it is meant to, and holds
 * exactly the role it is named for; only the two superadmin actors are.
 */
final class AuthMatrixWorld
{
    /** Unique per world, embedded in every target resource so a leak is greppable. */
    public readonly string $marker;

    private readonly AuthMatrixResources $resources;

    private readonly AuthMatrixPlatformResources $platform;

    public function __construct(
        public readonly Organization $orgA,
        public readonly Organization $orgB,
        string $marker,
    ) {
        $this->marker = $marker;
        $this->resources = new AuthMatrixResources($this);
        $this->platform = new AuthMatrixPlatformResources($this);
    }

    public static function make(): self
    {
        $marker = 'amx'.Str::lower(Str::random(10));

        return new self(
            self::provisioned(Organization::factory()->create(['name' => "{$marker} org a"])),
            self::provisioned(Organization::factory()->create(['name' => "{$marker} org b"])),
            $marker,
        );
    }

    /**
     * Gives the organization its three authorization roles up front, as
     * production provisioning does (ProvisionOrganizationCommand): a route that
     * assigns a role (`POST /users`) resolves it with firstOrFail(), so an
     * organization without them would 404 for a reason unrelated to authorization.
     */
    private static function provisioned(Organization $org): Organization
    {
        foreach (['admin', 'operator', 'viewer'] as $role) {
            SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
        }

        return $org;
    }

    public function resources(): AuthMatrixResources
    {
        return $this->resources;
    }

    /**
     * The platform-level and avatar-template targets (T5).
     */
    public function platform(): AuthMatrixPlatformResources
    {
        return $this->platform;
    }

    /**
     * The credential (bearer token) an actor presents, and the organization
     * whose resources a WRITE payload may reference on that actor's behalf.
     *
     * @return array{token: ?string, org: ?Organization, user: ?User}
     */
    public function actor(string $actor): array
    {
        return match ($actor) {
            AuthMatrix::UNAUTHENTICATED => ['token' => null, 'org' => null, 'user' => null],
            AuthMatrix::ADMIN, AuthMatrix::OPERATOR, AuthMatrix::VIEWER => $this->roleActor($this->orgA, $actor),
            AuthMatrix::NO_ROLE => $this->noRoleActor(),
            AuthMatrix::CROSS_TENANT_ADMIN => $this->roleActor($this->orgB, 'admin'),
            AuthMatrix::SUPERADMIN_BARE => $this->bareSuperadmin(),
            AuthMatrix::SUPERADMIN_ACTING => $this->actingSuperadmin(),
            AuthMatrix::CANDIDATE_JWT => ['token' => $this->candidateToken(), 'org' => null, 'user' => null],
            AuthMatrix::API_KEY => ['token' => $this->liveApiKey(), 'org' => null, 'user' => null],
            default => throw new LogicException("AuthMatrixWorld cannot build the actor '{$actor}' yet."),
        };
    }

    /**
     * The user behind an actor that has one (every actor but the credential-only ones).
     */
    public function member(string $actor): User
    {
        return $this->actor($actor)['user'] ?? throw new LogicException("Matrix actor '{$actor}' has no user.");
    }

    /**
     * @return array{token: string, org: Organization, user: User}
     */
    private function roleActor(Organization $org, string $role): array
    {
        ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, $role);

        $this->assertIdentity($user, $org, [$role]);

        return ['token' => $token, 'org' => $org, 'user' => $user];
    }

    /**
     * A member of orgA who holds NO authorization role at all.
     *
     * @return array{token: string, org: Organization, user: User}
     */
    private function noRoleActor(): array
    {
        $user = User::factory()->create(['organization_id' => $this->orgA->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);

        $this->assertIdentity($user, $this->orgA, []);

        return ['token' => (string) auth('api')->login($user), 'org' => $this->orgA, 'user' => $user];
    }

    /**
     * @return array{token: string, org: null, user: User}
     */
    private function bareSuperadmin(): array
    {
        ['user' => $user, 'token' => $token] = saSuperadmin();

        $this->assertSuperadmin($user);

        return ['token' => $token, 'org' => null, 'user' => $user];
    }

    /**
     * @return array{token: string, org: Organization, user: User}
     */
    private function actingSuperadmin(): array
    {
        ['user' => $user, 'token' => $token] = authUserAndTokenForRole($this->orgA, 'platform');

        $this->assertSuperadmin($user);

        return ['token' => $token, 'org' => $this->orgA, 'user' => $user];
    }

    /**
     * A candidate-typed JWT (typ=candidate) minted for a participant of orgA.
     */
    private function candidateToken(): string
    {
        $participant = $this->resources()->participant();

        return CandidateTokenFactory::mintCandidateToken($participant);
    }

    /**
     * The raw key of a live M2M / Public API client of orgA.
     */
    private function liveApiKey(): string
    {
        $raw = ApiKeyGenerator::generate();
        ApiClient::factory()->withRawKey($raw)->create(['organization_id' => $this->orgA->id]);

        return $raw;
    }

    /**
     * @param  list<string>  $expectedRoles
     */
    private function assertIdentity(User $user, Organization $org, array $expectedRoles): void
    {
        if ($user->is_superadmin) {
            throw new LogicException("Matrix actor {$user->email} was built as a superadmin; Gate::before would answer TRUE for it on every ability.");
        }

        if ($user->organization_id !== $org->id) {
            throw new LogicException("Matrix actor belongs to organization {$user->organization_id}, expected {$org->id}.");
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $roles = $user->refresh()->getRoleNames()->sort()->values()->all();
        sort($expectedRoles);

        if ($roles !== $expectedRoles) {
            throw new LogicException('Matrix actor holds roles ['.implode(',', $roles).'], expected ['.implode(',', $expectedRoles).'].');
        }
    }

    private function assertSuperadmin(User $user): void
    {
        if (! $user->is_superadmin || $user->organization_id !== null) {
            throw new LogicException('A matrix superadmin must be is_superadmin with no organization of its own.');
        }
    }
}
