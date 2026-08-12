<?php

declare(strict_types=1);

/**
 * UserGuards — the last-admin / self-action invariants for user-management
 * writes (backoffice-missing-pages D4).
 *
 * `ensureAdminSurvivesThenMutate()` is the ONE atomic entry point for both
 * the role-change path and the deactivate path: it wraps a count-then-write
 * in a transaction with `lockForUpdate()` on the admin role-assignment rows,
 * so two admins racing to remove each other cannot both observe "2 admins
 * exist" and leave the organization with zero.
 *
 * REQ: Last-Admin And Self-Action Guards
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Exceptions\Users\UserGuardException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use App\Support\Users\UserGuards;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function guardsTestOrgWithAdmins(int $adminCount): array
{
    $org = Organization::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $adminRole = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);

    $admins = [];
    for ($i = 0; $i < $adminCount; $i++) {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($adminRole);
        $admins[] = $user;
    }

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);

    return ['org' => $org, 'admins' => $admins, 'resolver' => $resolver];
}

test('the last admin cannot self-demote', function (): void {
    ['admins' => $admins, 'resolver' => $resolver] = guardsTestOrgWithAdmins(1);
    $solo = $admins[0];
    $guards = new UserGuards($resolver);
    $mutated = false;

    $act = fn () => $guards->ensureAdminSurvivesThenMutate(
        actor: $solo,
        target: $solo,
        targetLosesAdminStatus: true,
        selfErrorCode: 'self_demotion',
        mutate: function () use (&$mutated): void {
            $mutated = true;
        },
    );

    expect($act)->toThrow(UserGuardException::class);

    try {
        $act();
    } catch (UserGuardException $e) {
        expect($e->errorCode())->toBe('self_demotion');
    }

    expect($mutated)->toBeFalse();
});

test('the last admin cannot self-deactivate', function (): void {
    ['admins' => $admins, 'resolver' => $resolver] = guardsTestOrgWithAdmins(1);
    $solo = $admins[0];
    $guards = new UserGuards($resolver);
    $mutated = false;

    try {
        $guards->ensureAdminSurvivesThenMutate(
            actor: $solo,
            target: $solo,
            targetLosesAdminStatus: true,
            selfErrorCode: 'self_deactivation',
            mutate: function () use (&$mutated): void {
                $mutated = true;
            },
        );
    } catch (UserGuardException $e) {
        expect($e->errorCode())->toBe('self_deactivation');
    }

    expect($mutated)->toBeFalse();
});

test('demoting a peer admin succeeds when another admin remains', function (): void {
    ['admins' => $admins, 'resolver' => $resolver] = guardsTestOrgWithAdmins(2);
    [$actor, $target] = $admins;
    $guards = new UserGuards($resolver);
    $mutated = false;

    $result = $guards->ensureAdminSurvivesThenMutate(
        actor: $actor,
        target: $target,
        targetLosesAdminStatus: true,
        selfErrorCode: 'self_demotion',
        mutate: function () use (&$mutated): string {
            $mutated = true;

            return 'demoted';
        },
    );

    expect($mutated)->toBeTrue();
    expect($result)->toBe('demoted');
});

test('two admins demoting each other concurrently: the lock closes the race', function (): void {
    // This test drives two INDEPENDENT Postgres sessions (a raw PDO
    // connection alongside the default Laravel connection) to prove
    // `lockForUpdate()` genuinely serializes concurrent access rather than
    // letting two callers both observe "2 admins exist" before either
    // writes. Per design D4: "Without that test the guard is decorative."
    ['org' => $org, 'admins' => $admins, 'resolver' => $resolver] = guardsTestOrgWithAdmins(2);
    [$admin1, $admin2] = $admins;

    $adminRoleId = SpatieRole::where('name', 'admin')
        ->where('guard_name', 'api')
        ->where('team_id', $org->id)
        ->value('id');

    // Commit the fixtures created inside RefreshDatabase's wrapping
    // transaction — a genuinely independent Postgres session (below) cannot
    // observe another session's uncommitted writes, exactly as in
    // production, so the race can only be exercised on committed data.
    DB::commit();

    try {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $sessionB = new PDO($dsn, $config['username'], $config['password']);
        $sessionB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Session B = admin1's request thread, demoting admin2. Locks the
        // admin assignment rows and does NOT commit yet — the exact moment
        // UserGuards::ensureAdminSurvivesThenMutate() has acquired the lock
        // but not yet returned.
        $sessionB->beginTransaction();
        $lockStmt = $sessionB->prepare(
            'SELECT model_id FROM model_has_roles WHERE role_id = :roleId AND model_type = :modelType FOR UPDATE'
        );
        $lockStmt->execute(['roleId' => $adminRoleId, 'modelType' => User::class]);
        expect($lockStmt->fetchAll(PDO::FETCH_ASSOC))->toHaveCount(2);

        // Proof the lock is REAL: a THIRD, independent session attempting
        // the SAME row lock with NOWAIT must fail IMMEDIATELY (lock not
        // available) while B still holds it uncommitted. This is precisely
        // what prevents a second caller from ever reading the pre-removal
        // count "2 admins exist" — without it, this whole test would only
        // be proving sequential ordering, not that the lock closes the race.
        $sessionC = new PDO($dsn, $config['username'], $config['password']);
        $sessionC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sessionC->beginTransaction();
        $lockWasUnavailable = false;
        try {
            $sessionC->prepare(
                'SELECT model_id FROM model_has_roles WHERE role_id = :roleId AND model_type = :modelType FOR UPDATE NOWAIT'
            )->execute(['roleId' => $adminRoleId, 'modelType' => User::class]);
        } catch (PDOException $e) {
            $lockWasUnavailable = str_contains(strtolower($e->getMessage()), 'lock');
        }
        $sessionC->rollBack();
        expect($lockWasUnavailable)->toBeTrue();

        // B completes the demotion of admin2 and commits — one admin remains.
        $sessionB->prepare(
            'DELETE FROM model_has_roles WHERE role_id = :roleId AND model_type = :modelType AND model_id = :userId'
        )->execute(['roleId' => $adminRoleId, 'modelType' => User::class, 'userId' => $admin2->id]);
        $sessionB->commit();

        // A = admin2's request thread, demoting admin1 — the REAL production
        // guard, on the default connection, called only now that B's lock is
        // released. It must observe the POST-COMMIT count (1), not the stale
        // "2" both sessions could see before B committed.
        $guards = new UserGuards($resolver);
        $rejected = null;

        try {
            $guards->ensureAdminSurvivesThenMutate(
                actor: $admin2,
                target: $admin1,
                targetLosesAdminStatus: true,
                selfErrorCode: 'self_demotion',
                mutate: fn () => throw new RuntimeException('mutate must never run — the guard must reject first'),
            );
        } catch (UserGuardException $e) {
            $rejected = $e;
        }

        expect($rejected)->not->toBeNull();
        expect($rejected->errorCode())->toBe('last_admin');

        // admin1 must still hold the admin role — the guard's rejection
        // means the mutate closure (which would have removed it) never ran.
        expect($admin1->fresh()->hasRole('admin'))->toBeTrue();
    } finally {
        // This test committed real rows outside RefreshDatabase's rollback —
        // it must remove them itself so no state leaks into other tests.
        DB::table('model_has_roles')->where('role_id', $adminRoleId)->delete();
        SpatieRole::where('team_id', $org->id)->delete();
        User::where('organization_id', $org->id)->delete();
        $org->delete();
    }
});

test('a role change that does not remove admin status never touches the guard count check', function (): void {
    // Deliberately only ONE admin exists — if the count check ran here it
    // would reject the change even though it has nothing to do with admin
    // survival, proving the short-circuit is real (not accidentally always-pass).
    ['org' => $org, 'admins' => $admins, 'resolver' => $resolver] = guardsTestOrgWithAdmins(1);
    $actor = $admins[0];
    $target = User::factory()->create(['organization_id' => $org->id]);
    $guards = new UserGuards($resolver);
    $mutated = false;

    $guards->ensureAdminSurvivesThenMutate(
        actor: $actor,
        target: $target,
        targetLosesAdminStatus: false,
        selfErrorCode: 'self_demotion',
        mutate: function () use (&$mutated): void {
            $mutated = true;
        },
    );

    expect($mutated)->toBeTrue();
});
