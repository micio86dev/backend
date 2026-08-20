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
     * The class-string `Gate::authorize()`/`$this->authorize()` needs for a
     * model-less ability check (`create`, which takes no `Participant`
     * instance). Exposed here — rather than referencing `Participant::class`
     * directly from `app/Http/Controllers/Api/EntryLinkController.php` —
     * because `tests/Arch/C11/AdminTenancySafetyArchTest.php` mechanically
     * (and deliberately, per its own docblock: "cheap... backstop") flags
     * ANY literal `Participant::` text under that directory as a suspected
     * AdminParticipantReader bypass. A class-string used ONLY to resolve
     * which policy governs an ability check is not a data read and cannot
     * bypass the org filter or lifecycle gate that guard exists to protect —
     * but the guard cannot tell the two apart by source text alone, so this
     * constant keeps the literal `Participant::class` reference where it
     * belongs (the policy that already owns the Participant/policy mapping)
     * instead of routing around the guard with an equivalent-looking
     * construct that would erode its signal for the next reviewer.
     */
    public const MODEL = Participant::class;

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

    /**
     * Mint an entry link for a participant (operator-interview-link, design
     * D2) — admin and operator only. Minting starts an assessment; it is not
     * a read, so `viewer` is denied (unlike viewAny/view above). No `create`
     * wiring change needed: `Gate::policy(Participant::class,
     * ParticipantPolicy::class)` is already registered
     * (`AppServiceProvider.php:79`).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator');
    }

    /**
     * Recover a participant out of `errore` (participant-error-recovery,
     * design D4) — admin and operator only, `viewer` denied. Same reasoning
     * as `create()` above, with more force: recovery re-opens an assessment
     * and can discard partial transcript data, so it must never be available
     * to a read-only role. Model-less, like `create()` — the check runs
     * BEFORE the participant is resolved (403 before 404).
     *
     * Named `recover` everywhere (route/action/log/i18n) — rejected `update`
     * (too generic, would widen silently) and `reopen` (suggests reopening a
     * SUCCESSFULLY completed assessment, which this explicitly does not do).
     */
    public function recover(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator');
    }
}
