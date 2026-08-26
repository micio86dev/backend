<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LlmCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LlmCredentialResource (pluggable-conversation-llm PR P2).
 *
 * `api_key` never appears — it is already `$hidden` on the model, and this
 * resource does not read it back in regardless. Only `key_last_four`
 * identifies the credential to an operator, per the `WriteOnlySecretField`
 * doctrine the backoffice reuses in PR P7.
 *
 * @mixin LlmCredential
 */
class LlmCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     *
     * @scramble-return array{id: int, name: string, vendor: string, key_last_four: string, validated_at: string|null, validation_error: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var LlmCredential $credential */
        $credential = $this->resource;

        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'vendor' => $credential->vendor,
            'key_last_four' => $credential->key_last_four,
            'validated_at' => $credential->validated_at?->toIso8601String(),
            'validation_error' => $credential->validation_error,
            'created_at' => $credential->created_at?->toIso8601String(),
        ];
    }
}
