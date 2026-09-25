<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Authorization role allow-list (backoffice-missing-pages D4).
 *
 * These are Spatie AUTHORIZATION roles — `admin`/`operator`/`viewer`, held by
 * a User through spatie/laravel-permission in teams mode. They are NOT the
 * BEAI organizational roles (ICO/FLL/MLL/BUL/SRX, carried as `role_code` on
 * Project/Participant) — a domain concept describing what an interview
 * assesses, not a permission.
 *
 * Backed as a code-level enum (never a query against the `roles` table,
 * which also holds rows for other organizations) so Scramble exports
 * `role: "admin"|"operator"|"viewer"` into openapi.json — the backoffice's
 * generated client carries the allow-list with zero drift and zero extra
 * authorization surface. There is deliberately no `GET /api/roles` route.
 */
enum OrgRole: string
{
    use HasValues;

    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';
}
