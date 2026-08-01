<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Evaluation lifecycle status (C9 — Scoring Engine).
 *
 * Transitions:
 *   processing → completed | pending
 *
 * 'completed' — all competencies scored; ≥ 90% valid (gate passed).
 * 'pending'   — scoring done; < 90% valid (gate not passed); still delivered.
 * 'processing'— job started; Evaluation row created at job START.
 *
 * Note: 'pending' is an Evaluation sub-state only. Both 'completed' and
 * 'pending' resolve the participant to 'completato' (C9 D9).
 *
 * REQ: EvaluationStatus enum (C9 D1)
 */
enum EvaluationStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Pending = 'pending';
}
