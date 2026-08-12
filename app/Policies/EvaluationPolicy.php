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
     * List/aggregate evaluations (backoffice-missing-pages D6/D7's
     * `/evaluations` and `/evaluations/summary`) — allowed for all roles,
     * same as the single-resource `view` ability below.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }

    /**
     * View an evaluation report — allowed for all roles.
     */
    public function view(User $user, Evaluation $evaluation): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }
}
