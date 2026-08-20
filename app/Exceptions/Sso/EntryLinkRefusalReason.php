<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

/**
 * The closed set of reasons `EntryLinkMinter::mint()` can refuse a mint
 * (operator-interview-link, design D1; split by participant-error-recovery
 * design D3). Each caller (M2M mint, operator mint) maps a reason onto its
 * OWN HTTP shape — the reason travels, the response literal never does.
 *
 * `Terminal` was split into `Completed` and `Failed` (participant-error-
 * recovery D3): an `errore` participant is now recoverable by an operator,
 * so reporting it as "completed" would be false and would hide the real
 * refusal cause from the calling system. Both stay 409 — only the machine-
 * facing `reason` and the message differ.
 */
enum EntryLinkRefusalReason: string
{
    case Gates = 'gates';
    case RoleCode = 'role_code';
    case Completed = 'completed';
    case Failed = 'failed';
}
