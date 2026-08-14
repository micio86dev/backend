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
     * @return array{id: int, name: string, description: string|null, provider: string, config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null}
     *
     * @scramble-return array{id: int, name: string, description: string|null, provider: string, config: array<string, mixed>, is_active: bool, created_at: string|null, updated_at: string|null}
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
        ];
    }
}
