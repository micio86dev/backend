<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AvatarTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AvatarTemplateResource (C14).
 *
 * An explicit whitelist, not `$this->resource->toArray()`. `organization_id` is
 * deliberately absent: the caller is already scoped to their own tenant, so it
 * carries no information they lack — and echoing internal tenant ids back is
 * how they end up in client-side code that then tries to send them.
 *
 * @mixin AvatarTemplate
 */
class AvatarTemplateResource extends JsonResource
{
    /**
     * Same Scramble local-assignment defect as `ApiClientResource` (design.md
     * D2, dates-and-destructive-actions Phase 0 audit) — the `@var
     * AvatarTemplate $template` below is not honoured by Scramble's static
     * walk, so every `$template->x` fetch degraded to its inferred default.
     * `@scramble-return` is the actual override hook (a plain `@return`
     * alone does not change `scramble:export`'s output — verified
     * empirically on `ApiClientResource` first); `@return` is kept for
     * PHPStan/IDE tooling.
     *
     * `llm_model_id` / `llm_credential_id` / `llm_sync_status` / `llm_synced_at`
     * are the pluggable-conversation-llm binding (PR P3a wrote them; this is
     * the read side, closing a gap the write path left open — see
     * `openspec/changes/pluggable-conversation-llm/apply-progress.md`). Only
     * the credential's own numeric id is exposed — same doctrine as
     * `LlmCredentialResource`, which never serializes anything beyond
     * `key_last_four` for the credential itself; here we go further and
     * expose not even that, since a template has no legitimate reason to
     * echo the credential's name or masked key back to the caller who is
     * already looking at the credential list separately.
     *
     * @return array{id: int, name: string, description: string|null, provider: string, config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null, llm_model_id: int|null, llm_credential_id: int|null, llm_sync_status: string|null, llm_synced_at: string|null}
     *
     * @scramble-return array{id: int, name: string, description: string|null, provider: string, config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null, llm_model_id: int|null, llm_credential_id: int|null, llm_sync_status: string|null, llm_synced_at: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var AvatarTemplate $template */
        $template = $this->resource;

        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'provider' => $template->provider,
            'config' => $template->config,
            'is_active' => $template->is_active,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'llm_model_id' => $template->llm_model_id,
            'llm_credential_id' => $template->llm_credential_id,
            'llm_sync_status' => $template->llm_sync_status,
            'llm_synced_at' => $template->llm_synced_at?->toIso8601String(),
        ];
    }
}
