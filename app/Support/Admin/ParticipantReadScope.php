<?php

declare(strict_types=1);

namespace App\Support\Admin;

/**
 * The lifecycle read scope requested for a given admin participant read (C11).
 *
 * D2 — naming a scope is mandatory when calling AdminParticipantReader::read()
 * (D1): it applies both the RBAC check and the lifecycle threshold
 * (LifecycleReadGate) in the same call, so a future developer cannot obtain a
 * Participant without declaring which threshold applies.
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */
enum ParticipantReadScope
{
    /** List/detail read — RBAC only, no lifecycle threshold. */
    case Summary;

    /** Transcript read/download — requires lifecycle >= in_valutazione. */
    case Transcript;

    /** Evaluation read/download — requires lifecycle === completato. */
    case Evaluation;
}
