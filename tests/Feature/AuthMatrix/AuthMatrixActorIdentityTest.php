<?php

declare(strict_types=1);

/**
 * Authorization matrix — the actors are who they claim to be.
 *
 * `Gate::before` answers TRUE for a superadmin on every ability, so a role
 * helper that silently produced one would turn every "viewer is refused" cell
 * into a false pass. {@see AuthMatrixWorld} asserts
 * each actor's identity as it builds it; this file pins the property from the
 * other side, through the Gate itself.
 */

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Helpers\AuthMatrix\AuthMatrix;
use Tests\Helpers\AuthMatrix\AuthMatrixWorld;

uses(RefreshDatabase::class);

test('a role actor is never a superadmin, and the Gate refuses what its role does not grant', function (): void {
    $world = AuthMatrixWorld::make();

    foreach ([AuthMatrix::VIEWER, AuthMatrix::NO_ROLE, AuthMatrix::CROSS_TENANT_ADMIN] as $actor) {
        $user = $world->member($actor)->refresh();

        expect($user->is_superadmin)->toBeFalse("{$actor} must not be a superadmin")
            ->and($user->organization_id)->not->toBeNull();
    }

    $viewer = $world->member(AuthMatrix::VIEWER);
    $noRole = $world->member(AuthMatrix::NO_ROLE);

    // ProjectPolicy::create is admin/operator only; Gate::before must not have rescued them.
    expect(Gate::forUser($viewer)->denies('create', Project::class))->toBeTrue()
        ->and(Gate::forUser($noRole)->denies('create', Project::class))->toBeTrue();
});

test('the two superadmin actors are the ONLY ones Gate::before rescues', function (): void {
    $world = AuthMatrixWorld::make();

    foreach ([AuthMatrix::SUPERADMIN_BARE, AuthMatrix::SUPERADMIN_ACTING] as $actor) {
        $user = $world->member($actor);

        expect($user->is_superadmin)->toBeTrue()
            ->and($user->organization_id)->toBeNull()
            ->and(Gate::forUser($user)->allows('create', Project::class))->toBeTrue();
    }
});

test('the cross-tenant admin belongs to a different organization than the target', function (): void {
    $world = AuthMatrixWorld::make();
    $admin = $world->member(AuthMatrix::CROSS_TENANT_ADMIN);

    expect($admin->organization_id)->toBe($world->orgB->id)
        ->and($admin->organization_id)->not->toBe($world->orgA->id);
});
