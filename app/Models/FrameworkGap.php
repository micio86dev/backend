<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FrameworkGap model (C3 Framework Catalog).
 *
 * Queryable authoring task registry — gaps are structured, not silent.
 *
 * kind values:
 *   - role_no_bars: a role has no bars file at all (e.g. SRX)
 *   - competency_no_bars: a competency assigned to a role is absent from the bars file
 *   - missing_role_meta: role metadata (responsibilities) is empty/missing
 *   - missing_translation: IT authoring translations not yet provided
 *   - missing_potential_competency: MTG or LAT not yet defined
 *
 * IMPORTANT: $casts = [] — do NOT cast role_code or competency_code as 'string'.
 * Laravel's primitive 'string' cast coerces null → '', which would corrupt null-column
 * gap rows on read (e.g. missing_translation with both null, role_no_bars with null competency_code).
 * Nullable string columns need no cast.
 *
 * @property string      $kind
 * @property string|null $role_code
 * @property string|null $competency_code
 * @property string|null $note
 * @property string      $status
 */
class FrameworkGap extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['kind', 'role_code', 'competency_code', 'note', 'status'];

    /**
     * Explicitly empty — do NOT add 'string' cast to nullable columns.
     *
     * @var array<string, string>
     */
    protected $casts = [];
}
