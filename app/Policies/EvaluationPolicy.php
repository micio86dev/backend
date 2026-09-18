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

    /**
     * Trigger a post-hoc audit run against a completed evaluation
     * (scoring-audit-jev, design D12) — admin ONLY, diverging from
     * `viewAny`/`view` above. Those are reads; this spends money with a
     * third party (TypeSafe/Jev). Mirrors `ParticipantPolicy::recover()`'s
     * own narrowing one step further: recovery admits `operator` because it
     * restores a stuck candidate, an audit does not because it is
     * discretionary spend an operator cannot authorize on their own.
     * Model-less, like `recover()` — evaluated BEFORE any Evaluation is
     * resolved (403 before 404, D12 step 2).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
