<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One platform-wide setting.
 *
 * @property string $key
 * @property array<array-key, mixed>|null $value
 *
 * The `$value` annotation is load-bearing rather than decorative: Larastan
 * reads the COLUMN type for an undeclared property, and a `json` column reads
 * back as `string|null`. Without this the `array` cast below is invisible to
 * static analysis, which then reports every `is_array($setting->value)` guard
 * as impossible — a false negative that would have been "fixed" by deleting
 * the guard that keeps a hand-edited row from crashing the reader.
 *
 * `array-key` rather than `string` for the same honesty: `json_decode` on an
 * object whose keys are numeric strings hands back INTEGER keys, so promising
 * `array<string, …>` here would make the reader's own key check look redundant
 * and invite its removal.
 *
 * NOT a `TenantModel`, and that is the point rather than an omission: every
 * other model in this application carries an `organization_id` and a global
 * scope that hides other tenants' rows. A row here belongs to the PLATFORM.
 * Extending `TenantModel` would make these rows invisible to the only identity
 * allowed to write them — a superadmin has no organization at all.
 *
 * Read through `App\Support\Settings\PlatformSettings` rather than directly.
 * That class owns the defaults, so a missing row degrades to the value the
 * product shipped with instead of to null.
 */
class PlatformSetting extends Model
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    protected $table = 'platform_settings';

    /**
     * The key IS the primary key: one row per setting, forever.
     */
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
