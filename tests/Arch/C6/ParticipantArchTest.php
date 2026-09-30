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
 * - external_id / source NOT in $fillable (candidate-external-reference: written only
 *   through forceFill(ExternalReference::toAttributes()), never mass-assigned)
 * - no SoftDeletes trait on Participant
 * - Participant implements JWTSubject (required for fromUser)
 * - the candidate-facing ParticipantResource is used ONLY by the candidate session
 *   controller and by ParticipantEnrolmentResource, which builds on its shape
 *   (candidate-external-reference: a second consumer of the shared class is how a
 *   field meant for operators reaches a candidate)
 *
 * REQ: Participant Model and Schema — structural invariants
 */

use App\Http\Controllers\Candidate\SessionController;
use App\Http\Resources\ParticipantEnrolmentResource;
use App\Http\Resources\ParticipantResource;
use App\Models\Participant;
use App\Models\TenantModel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
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

test('external_id and source are NOT in Participant $fillable (written only via forceFill)', function (): void {
    $fillable = (new Participant)->getFillable();

    expect($fillable)->not->toContain('external_id');
    expect($fillable)->not->toContain('source');
});

test('Participant does NOT use SoftDeletes trait (no SoftDeletes in C6)', function (): void {
    $traits = class_uses_recursive(Participant::class);

    expect($traits)->not->toContain(SoftDeletes::class);
});

test('Participant extends plain Model (not Foundation\Auth\User)', function (): void {
    expect(Participant::class)->not->toExtend(User::class);
});

test('Participant has no global scopes registered (not TenantScoped)', function (): void {
    $scopes = (new Participant)->getGlobalScopes();

    expect($scopes)->toBeEmpty();
});

test('the candidate ParticipantResource is used only by the candidate session controller and the enrolment resource', function (): void {
    // The candidate is an outsider holding a short-lived token. Every operator,
    // M2M and integration response that used to share this class now goes
    // through ParticipantEnrolmentResource, so a field added "for operators"
    // can never reach /api/candidate/session by default.
    expect(ParticipantResource::class)->toOnlyBeUsedIn([
        SessionController::class,
        ParticipantEnrolmentResource::class,
    ]);
});
