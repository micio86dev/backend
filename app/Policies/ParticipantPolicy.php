<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Participant;
use App\Models\User;

/**
 * ParticipantPolicy (C11 — Admin Dashboards, D3).
 *
 * RBAC gate for admin participant reads via Spatie/laravel-permission in teams
 * mode. Mirrors ProjectPolicy.php:30-41 verbatim: all three roles (admin,
 * operator, viewer) may read, no owner filter — org scoping is the reader's
 * job (AdminParticipantReader, D1), not the policy's.
 *
 * Authorization runs INSIDE AdminParticipantReader::read(), after the org
 * filter and before the lifecycle gate (D3): the fixed failure order is
 * 404 (not yours) → 403 (not your role) → 409 (not yet).
 */
class ParticipantPolicy
{
    /**
     * List participants — allowed for all roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }

    /**
     * View a single participant (detail, transcript, evaluation) — allowed for all roles.
     */
    public function view(User $user, Participant $participant): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }
}
