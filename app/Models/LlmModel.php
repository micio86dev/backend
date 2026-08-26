<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LlmCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A registered conversation LLM model and its published rate card
 * (pluggable-conversation-llm PR P1, design D1).
 *
 * GLOBAL, NOT tenant-scoped — extends `Model` directly, never `TenantModel`.
 * A rate card is a vendor fact: Google's price list does not belong to any
 * one organization, and making it tenant-scoped would mean N copies of the
 * same list drifting independently. Joins the documented exclusion list in
 * `tests/Arch/C2/TenantModelArchTest.php`, beside `Competency`,
 * `BarsIndicator`, `Role`, `CatalogMeta`, `FrameworkGap`.
 *
 * Every rate column may be NULL, and NULL is never treated as zero — see the
 * migration docblock. `key` is the exact vendor model string, sent verbatim
 * to both providers, and is the natural key the seeder upserts on.
 *
 * @property int $id
 * @property string $key
 * @property string $vendor
 * @property string $display_name
 * @property string $base_url
 * @property LlmCapability $capability
 * @property bool $is_available
 * @property int $sort_order
 * @property string|null $rate_card_source_url
 * @property Carbon|null $rate_card_verified_at
 * @property string|null $text_input_usd_per_million
 * @property string|null $text_output_usd_per_million
 * @property string|null $text_input_usd_per_million_high
 * @property string|null $text_output_usd_per_million_high
 * @property int|null $context_tier_threshold_tokens
 * @property string|null $audio_input_usd_per_million
 * @property string|null $audio_output_usd_per_million
 * @property string|null $audio_input_usd_per_minute
 * @property string|null $audio_output_usd_per_minute
 * @property int|null $audio_tokens_per_second
 */
class LlmModel extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'vendor',
        'display_name',
        'base_url',
        'capability',
        'is_available',
        'sort_order',
        'rate_card_source_url',
        'rate_card_verified_at',
        'text_input_usd_per_million',
        'text_output_usd_per_million',
        'text_input_usd_per_million_high',
        'text_output_usd_per_million_high',
        'context_tier_threshold_tokens',
        'audio_input_usd_per_million',
        'audio_output_usd_per_million',
        'audio_input_usd_per_minute',
        'audio_output_usd_per_minute',
        'audio_tokens_per_second',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capability' => LlmCapability::class,
            'is_available' => 'boolean',
            'sort_order' => 'integer',
            'rate_card_verified_at' => 'immutable_datetime',
            'text_input_usd_per_million' => 'decimal:6',
            'text_output_usd_per_million' => 'decimal:6',
            'text_input_usd_per_million_high' => 'decimal:6',
            'text_output_usd_per_million_high' => 'decimal:6',
            'context_tier_threshold_tokens' => 'integer',
            'audio_input_usd_per_million' => 'decimal:6',
            'audio_output_usd_per_million' => 'decimal:6',
            'audio_input_usd_per_minute' => 'decimal:6',
            'audio_output_usd_per_minute' => 'decimal:6',
            'audio_tokens_per_second' => 'integer',
        ];
    }
}
