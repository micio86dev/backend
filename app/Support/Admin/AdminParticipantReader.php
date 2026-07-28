<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Participant;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Gate;

/**
 * The single sanctioned path to obtain a Participant for an admin HTTP read (C11 D1).
 *
 * There is no zero-argument overload and no default for $scope. A developer
 * adding a sixth read endpoint physically cannot obtain a Participant without
 * naming a read scope, and naming a scope applies the org filter and the
 * lifecycle threshold in the same call.
 *
 * Fixed order of checks — the failure a caller sees always means exactly one
 * thing:
 *   1. Org filter (Participant::where('organization_id', ...)->findOrFail())
 *      → ModelNotFoundException → 404 (not yours; cross-org is invisible)
 *   2. RBAC (Gate::authorize('view', $participant), D3)
 *      → AuthorizationException → 403 (not your role)
 *   3. Lifecycle gate (LifecycleReadGate::assert(), D2)
 *      → LifecycleNotReadyException → 409 (not yet)
 *
 * Internally this is exactly the M2m/ParticipantController.php:110-111
 * pattern — Participant::where('organization_id', $resolver->getOrgId())
 * ->findOrFail($id) — with TenantResolver injected, so a null/bypass org is
 * visible at one place instead of six.
 *
 * REQ: Cross-Tenant Isolation on Every Admin Read Endpoint,
 *      Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
final class AdminParticipantReader
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly LifecycleReadGate $gate = new LifecycleReadGate,
    ) {}

    public function read(int $participantId, ParticipantReadScope $scope): Participant
    {
        // (1) Org filter — Participant is a plain Model, NO global scope
        // (Participant.php:23-25). A bare Participant::findOrFail() here
        // would return another org's row with a 200 (arch-tested, D1).
        $participant = Participant::where('organization_id', $this->resolver->getOrgId())
            ->findOrFail($participantId);

        // (2) RBAC — runs AFTER the org filter, so a cross-org id 404s before
        // any role is even evaluated (D3).
        Gate::authorize('view', $participant);

        // (3) Lifecycle gate — runs LAST, so a same-org, authorized caller
        // sees 409 (temporal, self-resolving) rather than 403 or 404 (D2/D4).
        $this->gate->assert($participant->status, $scope);

        return $participant;
    }
}
