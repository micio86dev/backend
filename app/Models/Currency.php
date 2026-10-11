<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A platform-wide display currency (organization-currencies). GLOBAL: no
 * `organization_id`, managed by superadmins only. Amounts are stored in USD;
 * `rate_per_usd` converts them for display.
 *
 * @property string $code ISO 4217, three uppercase letters; the primary key.
 * @property string $symbol
 * @property int $decimals
 * @property string|null $rate_per_usd Units of this currency per 1 USD, six decimals.
 * @property Carbon|null $rate_as_of
 * @property bool $is_active
 */
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'symbol', 'decimals', 'rate_per_usd', 'rate_as_of', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'rate_per_usd' => 'decimal:6',
            'rate_as_of' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Currency>  $query
     * @return Builder<Currency>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
