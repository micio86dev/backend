<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\User;

/**
 * EvaluationPolicy (C11 — Admin Dashboards, D3).
 *
 * RBAC gate for admin evaluation reads via Spatie/laravel-permission in teams
 * mode. Mirrors ProjectPolicy.php:30-41 / ParticipantPolicy verbatim: all
 * three roles (admin, operator, viewer) may read, no owner filter.
 */
class EvaluationPolicy
{
    /**
     * View an evaluation report — allowed for all roles.
     */
    public function view(User $user, Evaluation $evaluation): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }
}
