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
    /** @return array<string, mixed> */
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
