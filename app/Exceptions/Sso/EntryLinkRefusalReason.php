<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

/**
 * The closed set of reasons `EntryLinkMinter::mint()` can refuse a mint
 * (operator-interview-link, design D1). Each caller (M2M mint, operator
 * mint) maps a reason onto its OWN HTTP shape — the reason travels, the
 * response literal never does.
 */
enum EntryLinkRefusalReason: string
{
    case Gates = 'gates';
    case RoleCode = 'role_code';
    case Terminal = 'terminal';
}
