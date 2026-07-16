<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base for all tenant-scoped Eloquent models.
 *
 * Every model that owns an `organization_id` column (except User and Organization
 * themselves) MUST extend TenantModel instead of Model directly. This ensures:
 *   - The TenantScoped global scope is automatically applied to all queries.
 *   - The tamper-proof creating listener stamps organization_id from the resolver.
 *
 * A Pest architecture test asserts this invariant at CI time.
 *
 * @see \App\Models\Concerns\TenantScoped
 */
abstract class TenantModel extends Model
{
    use TenantScoped;
}
