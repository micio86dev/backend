<?php

declare(strict_types=1);

/**
 * Participant architecture tests (C6 — Participant + SSO Ingress).
 *
 * Asserts structural invariants:
 * - Participant does NOT extend TenantModel
 * - Participant does NOT use HasRoles
 * - Participant implements AuthenticatableContract
 * - organization_id NOT in $fillable (named security invariant)
 * - no SoftDeletes trait on Participant
 * - Participant implements JWTSubject (required for fromUser)
 *
 * REQ: Participant Model and Schema — structural invariants
 */

use App\Models\Participant;
use App\Models\TenantModel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

test('Participant does NOT extend TenantModel', function (): void {
    expect(Participant::class)->not->toExtend(TenantModel::class);
});

test('Participant does NOT use HasRoles (Spatie)', function (): void {
    $traits = class_uses_recursive(Participant::class);

    expect($traits)->not->toContain(HasRoles::class);
});

test('Participant implements AuthenticatableContract', function (): void {
    expect(Participant::class)->toImplement(AuthenticatableContract::class);
});

test('Participant implements JWTSubject', function (): void {
    expect(Participant::class)->toImplement(JWTSubject::class);
});

test('Participant uses Illuminate\Auth\Authenticatable trait', function (): void {
    $traits = class_uses_recursive(Participant::class);

    expect($traits)->toContain(Authenticatable::class);
});

test('organization_id is NOT in Participant $fillable (named security invariant)', function (): void {
    $participant = new Participant;

    expect($participant->getFillable())->not->toContain('organization_id');
});

test('Participant does NOT use SoftDeletes trait (no SoftDeletes in C6)', function (): void {
    $traits = class_uses_recursive(Participant::class);

    expect($traits)->not->toContain(SoftDeletes::class);
});

test('Participant extends plain Model (not Foundation\Auth\User)', function (): void {
    expect(Participant::class)->not->toExtend(\Illuminate\Foundation\Auth\User::class);
});

test('Participant has no global scopes registered (not TenantScoped)', function (): void {
    $scopes = (new Participant)->getGlobalScopes();

    expect($scopes)->toBeEmpty();
});
