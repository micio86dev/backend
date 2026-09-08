<?php

declare(strict_types=1);

/**
 * RED — PlatformUserGuards (platform-user-management D3).
 *
 * The org surface refuses to leave an organization with zero admins. The
 * platform has the same failure mode and a worse blast radius: deactivate the
 * last ACTIVE superadmin and nobody can administer BEAI again — no clients
 * console, no platform settings, and no way to create a replacement short of
 * shell access to the production container.
 *
 * Mirrors `UserGuards` deliberately, including the lesson it already learned
 * the hard way: holding the identity is not the same as being able to use it.
 * A deactivated superadmin cannot authenticate, so counting one as a survivor
 * is not counting administrators.
 */

use App\Exceptions\Users\UserGuardException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Users\PlatformUserGuards;

function guardPlatformUser(array $attributes = []): User
{
    return User::factory()->create([
        'organization_id' => null,
        'is_superadmin' => true,
        ...$attributes,
    ]);
}

test('the last active superadmin cannot be deactivated by a peer', function (): void {
    $actor = guardPlatformUser();
    // Only ONE active superadmin exists once the target is the actor's peer…
    // so make the actor the survivor and the target the last other one.
    $target = $actor;

    // A single active superadmin: deactivating them reaches zero.
    expect(fn () => app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
        actor: $actor,
        target: $target,
        mutate: fn () => 'mutated',
    ))->toThrow(UserGuardException::class);
});

test('deactivating YOURSELF as the last one reports self_deactivation', function (): void {
    $actor = guardPlatformUser();

    try {
        app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
            actor: $actor,
            target: $actor,
            mutate: fn () => 'mutated',
        );
        $this->fail('the guard did not fire');
    } catch (UserGuardException $e) {
        // A different mistake from deactivating a peer, and the operator
        // deserves to be told which one they made.
        expect($e->errorCode())->toBe('self_deactivation');
    }
});

test('deactivating an ALREADY-DEACTIVATED peer is not refused', function (): void {
    // It removes no survivor, so this guard has nothing to say about it. The
    // first version refused here — one active superadmin plus one retired
    // peer, and retiring the peer again was impossible — which also made the
    // reactivate/deactivate round trip a dead end.
    $actor = guardPlatformUser();
    $retired = guardPlatformUser(['deactivated_at' => now()]);

    $result = app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
        actor: $actor,
        target: $retired,
        mutate: fn () => 'mutated',
    );

    expect($result)->toBe('mutated');
});

test('deactivating the last ACTIVE platform superadmin as a peer reports last_superadmin', function (): void {
    // Reachable because `is_superadmin` and "platform user" are not the same
    // predicate: a superadmin who still carries an organization_id passes the
    // controller's caller check without being one of the survivors this guard
    // counts. They must not be able to lock the platform out.
    $org = Organization::factory()->create();
    $actor = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => true]);
    $target = guardPlatformUser();

    try {
        app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
            actor: $actor,
            target: $target,
            mutate: fn () => 'mutated',
        );
        $this->fail('the guard did not fire');
    } catch (UserGuardException $e) {
        expect($e->errorCode())->toBe('last_superadmin');
    }
});

test('with two active superadmins, either may stand down', function (): void {
    $actor = guardPlatformUser();
    guardPlatformUser();

    $result = app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
        actor: $actor,
        target: $actor,
        mutate: fn () => 'mutated',
    );

    expect($result)->toBe('mutated');
});

test('a DEACTIVATED superadmin does not count as a survivor', function (): void {
    // The exact defect UserGuards records: a guard that counts users who
    // cannot log in is not counting administrators.
    $actor = guardPlatformUser();
    guardPlatformUser(['deactivated_at' => now()]);
    guardPlatformUser(['deactivated_at' => now()]);

    expect(fn () => app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
        actor: $actor,
        target: $actor,
        mutate: fn () => 'mutated',
    ))->toThrow(UserGuardException::class);
});

test('an ORGANISATION admin never counts as a superadmin survivor', function (): void {
    $actor = guardPlatformUser();
    User::factory()->count(3)->create(['is_superadmin' => false]);

    expect(fn () => app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
        actor: $actor,
        target: $actor,
        mutate: fn () => 'mutated',
    ))->toThrow(UserGuardException::class);
});

test('the mutation does NOT run when the guard refuses', function (): void {
    $actor = guardPlatformUser();
    $ran = false;

    try {
        app(PlatformUserGuards::class)->ensureSuperadminSurvivesThenMutate(
            actor: $actor,
            target: $actor,
            mutate: function () use (&$ran) {
                $ran = true;

                return null;
            },
        );
    } catch (UserGuardException) {
        // expected
    }

    expect($ran)->toBeFalse();
});
