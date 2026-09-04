<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PlatformSetting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSetting>
 *
 * Exists so a MALFORMED row can be built.
 *
 * `PlatformSettings` guards its reads — `is_array($stored)`, a per-entry
 * `is_numeric` check, integer keys from `json_decode` — and every one of those
 * guards defends a state the HTTP endpoint cannot produce. Without a factory
 * the only way to create a row is through a validated PATCH, so the guards were
 * documented, argued for at length, and untestable: no mutation of them would
 * have failed a test.
 */
class PlatformSettingFactory extends Factory
{
    protected $model = PlatformSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => PlatformSettings::MAX_QUESTIONS_KEY,
            'value' => ['standard' => 1, 'potential' => 4],
        ];
    }

    /**
     * A row nobody's validator ever saw — hand-edited, restored from an older
     * schema, or written by a future setting that stores something else.
     */
    public function malformed(): static
    {
        return $this->state(fn (): array => ['value' => ['standard' => 'not-a-number']]);
    }

    /**
     * Value is a SCALAR rather than a map.
     *
     * JSON `null` is NOT reachable here — the column is `json NOT NULL`, and
     * Eloquent's array cast turns a PHP null into SQL NULL, which the
     * constraint rejects (verified: 23502). A scalar is: `"whatever"` and `5`
     * are both valid JSON and both survive the NOT NULL check, and both make
     * `is_array($stored)` false.
     *
     * So the guard defends a real state, just not the one it looked like it
     * defended — and until this factory state existed, deleting it broke
     * nothing.
     */
    public function nonArray(): static
    {
        return $this->state(fn (): array => ['value' => 'not-a-map']);
    }

    /**
     * Integer keys, which is what `json_decode` hands back for `{"0": 5}`.
     *
     * The reader's `is_string($type)` check exists for this and only this;
     * before this state, removing it broke nothing.
     */
    public function numericKeys(): static
    {
        return $this->state(fn (): array => ['value' => [0 => 5, 'standard' => 2]]);
    }
}
