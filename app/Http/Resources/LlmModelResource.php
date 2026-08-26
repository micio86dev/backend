<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LlmModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LlmModelResource (pluggable-conversation-llm PR P1).
 *
 * Serializes a global `llm_models` row for `GET /api/llm-models` — a public
 * price list, readable by all three authorization roles. `mode` is derived
 * (`LlmCapability::mode()`), never stored, so the API surface matches D1's
 * "no `mode` column" decision exactly. Every rate field is nullable and
 * rendered `null`, never coerced to `0` — a NULL here means Google does not
 * publish that rate, which is a different fact from "free."
 *
 * @mixin LlmModel
 */
class LlmModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @scramble-return array{key: string, vendor: string, display_name: string, capability: string, mode: string, is_available: bool, sort_order: int, rate_card_source_url: string|null, rate_card_verified_at: string|null, text_input_usd_per_million: string|null, text_output_usd_per_million: string|null, text_input_usd_per_million_high: string|null, text_output_usd_per_million_high: string|null, context_tier_threshold_tokens: int|null, audio_input_usd_per_million: string|null, audio_output_usd_per_million: string|null, audio_input_usd_per_minute: string|null, audio_output_usd_per_minute: string|null, audio_tokens_per_second: int|null}
     */
    public function toArray(Request $request): array
    {
        /** @var LlmModel $model */
        $model = $this->resource;

        return [
            'key' => $model->key,
            'vendor' => $model->vendor,
            'display_name' => $model->display_name,
            'capability' => $model->capability->value,
            'mode' => $model->capability->mode()->value,
            'is_available' => $model->is_available,
            'sort_order' => $model->sort_order,
            'rate_card_source_url' => $model->rate_card_source_url,
            'rate_card_verified_at' => $model->rate_card_verified_at?->toIso8601String(),
            'text_input_usd_per_million' => $model->text_input_usd_per_million,
            'text_output_usd_per_million' => $model->text_output_usd_per_million,
            'text_input_usd_per_million_high' => $model->text_input_usd_per_million_high,
            'text_output_usd_per_million_high' => $model->text_output_usd_per_million_high,
            'context_tier_threshold_tokens' => $model->context_tier_threshold_tokens,
            'audio_input_usd_per_million' => $model->audio_input_usd_per_million,
            'audio_output_usd_per_million' => $model->audio_output_usd_per_million,
            'audio_input_usd_per_minute' => $model->audio_input_usd_per_minute,
            'audio_output_usd_per_minute' => $model->audio_output_usd_per_minute,
            'audio_tokens_per_second' => $model->audio_tokens_per_second,
        ];
    }
}
